import json
import shutil
from datetime import timedelta

import numpy as np
import pandas as pd
import pytest
from fastapi.testclient import TestClient

from serving import inference
from serving.api import ModelRegistry, app
from serving.inference import load_bundle, predict_sku
from tests.conftest import FINAL_TEST_RMSE
from tests.test_training_pipeline import make_daily_data

CONTEXT = ["is_promotion", "is_public_holiday", "is_school_term", "temperature_c"]


def forecast_context(future_days: int, history_days: int = 14) -> pd.DataFrame:
    """The last training days as history, then `future_days` days to forecast."""
    data = make_daily_data(160 + future_days)
    data["date"] = pd.to_datetime(data["date"])
    context = data.iloc[160 - history_days :][["date", "units_sold", *CONTEXT]]
    context = context.reset_index(drop=True)
    context.loc[history_days:, "units_sold"] = np.nan
    return context


def test_zero_weight_models_are_not_loaded(trained_model_dir) -> None:
    lean = load_bundle(trained_model_dir / "1L")
    full = load_bundle(trained_model_dir / "1L", skip_unused_models=False)

    assert lean.stacker_weights == {"prophet": 1.0, "xgboost": 0.0, "lstm": 0.0}
    assert lean.prophet is not None
    assert (lean.xgboost, lean.lstm, lean.lstm_scaler) == (None, None, None)
    assert None not in (full.xgboost, full.lstm, full.lstm_scaler)


@pytest.mark.parametrize("horizon_days", [1, 7])
def test_skipping_zero_weight_models_gives_identical_output(trained_model_dir, horizon_days) -> None:
    context = forecast_context(horizon_days)
    lean = predict_sku("1L", horizon_days, context, bundle=load_bundle(trained_model_dir / "1L"))
    full = predict_sku(
        "1L",
        horizon_days,
        context,
        bundle=load_bundle(trained_model_dir / "1L", skip_unused_models=False),
    )

    np.testing.assert_array_equal(lean["predicted_units_sold"], full["predicted_units_sold"])
    np.testing.assert_array_equal(lean["predicted_units_sold"], full["prophet_pred"])
    assert full[["xgboost_pred", "lstm_pred"]].notna().all().all()
    assert lean[["xgboost_pred", "lstm_pred"]].isna().all().all()
    assert len(lean) == horizon_days


def test_the_lean_path_never_runs_xgboost_or_the_lstm(trained_model_dir, monkeypatch) -> None:
    def fail(*args, **kwargs):
        raise AssertionError("A zero-weight model was run.")

    monkeypatch.setattr(inference, "forecast_xgboost", fail)
    monkeypatch.setattr(inference, "forecast_lstm", fail)
    monkeypatch.setattr(inference, "build_forecast_batch", fail)

    forecast = predict_sku("1L", 3, forecast_context(3), bundle=load_bundle(trained_model_dir / "1L"))

    assert forecast["predicted_units_sold"].notna().all()


def test_predict_sku_returns_metadata_and_loads_from_a_model_directory(trained_model_dir) -> None:
    forecast = predict_sku("1L", 2, forecast_context(2), model_dir=trained_model_dir)

    assert list(forecast.columns) == [
        "date",
        "horizon",
        "predicted_units_sold",
        "prophet_pred",
        "xgboost_pred",
        "lstm_pred",
        "model_version",
        "rmse_score",
        "ensemble_mode",
    ]
    assert forecast["horizon"].tolist() == [1, 2]
    assert forecast["rmse_score"].eq(FINAL_TEST_RMSE).all()
    assert forecast["ensemble_mode"].eq("prophet_only").all()


def test_predict_sku_ignores_supplied_temperature_and_uses_the_monthly_mean(trained_model_dir) -> None:
    bundle = load_bundle(trained_model_dir / "1L")
    context = forecast_context(1)
    hotter = context.assign(temperature_c=context["temperature_c"] + 15)

    np.testing.assert_array_equal(
        predict_sku("1L", 1, context, bundle=bundle)["predicted_units_sold"],
        predict_sku("1L", 1, hotter, bundle=bundle)["predicted_units_sold"],
    )


def test_predict_sku_rejects_short_or_broken_history(trained_model_dir) -> None:
    bundle = load_bundle(trained_model_dir / "1L")
    context = forecast_context(1)

    with pytest.raises(ValueError, match="at least 14 history rows"):
        predict_sku("1L", 1, context.iloc[1:], bundle=bundle)
    with pytest.raises(ValueError, match="consecutive"):
        predict_sku("1L", 1, forecast_context(1, history_days=20).drop(index=15), bundle=bundle)
    with pytest.raises(ValueError, match="future rows"):
        predict_sku("1L", 2, context, bundle=bundle)


def test_manifest_records_mode_weights_protocol_and_final_test_rmse(trained_model_dir) -> None:
    manifest = json.loads((trained_model_dir / "1L" / "manifest.json").read_text())
    metadata = json.loads((trained_model_dir / "1L" / "metadata.json").read_text())

    assert manifest == metadata
    assert manifest["ensemble_mode"] == "prophet_only"
    assert manifest["stacker_weights"] == {"prophet": 1.0, "xgboost": 0.0, "lstm": 0.0}
    assert manifest["rmse_score"] == FINAL_TEST_RMSE
    assert manifest["temperature_source"] == "monthly_mean"
    assert set(manifest["temperature_climatology"]) == {str(month) for month in range(1, 13)}
    assert manifest["evaluation_protocol"]["headline_horizon_days"] == 1
    assert manifest["evaluation_protocol"]["folds"] == 6
    assert manifest["evaluation_protocol"]["scheme"] == "rolling origin, expanding window"


def test_bundle_with_weights_that_disagree_with_the_stacker_is_rejected(trained_model_dir, tmp_path) -> None:
    tampered = tmp_path / "1L"
    shutil.copytree(trained_model_dir / "1L", tampered)
    manifest = json.loads((tampered / "manifest.json").read_text())
    manifest["stacker_weights"] = {"prophet": 0.5, "xgboost": 0.5, "lstm": 0.0}
    (tampered / "manifest.json").write_text(json.dumps(manifest))

    with pytest.raises(ValueError, match="do not match"):
        load_bundle(tampered)


def test_predict_endpoint_serves_weighted_bundles_through_predict_sku(trained_model_dir) -> None:
    registry = ModelRegistry(trained_model_dir)
    registry.load_available()
    context = forecast_context(1)
    target = context.iloc[14]
    body = {
        "sku": "1L",
        "date": target["date"].date().isoformat(),
        "history": [
            {**row[["units_sold", *CONTEXT]].to_dict(), "date": row["date"].date().isoformat()}
            for _, row in context.iloc[:14].iterrows()
        ],
        **{name: target[name].item() for name in CONTEXT},
    }

    with TestClient(app) as client:
        app.state.models = registry.models
        response = client.post("/predict", json=body)

    expected = predict_sku(
        "1L", 1, context, bundle=load_bundle(trained_model_dir / "1L", skip_unused_models=False)
    )
    assert registry.models["1L"].xgboost is None
    assert response.status_code == 200
    assert set(response.json()) == {"predicted_units_sold", "model_version", "rmse_score"}
    assert response.json()["predicted_units_sold"] == expected["predicted_units_sold"].iloc[0]
    assert response.json()["rmse_score"] == FINAL_TEST_RMSE
    assert response.json()["model_version"] == expected["model_version"].iloc[0]
    assert target["date"] - context["date"].iloc[13] == timedelta(days=1)
