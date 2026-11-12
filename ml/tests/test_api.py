import logging
import pickle
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import pandas as pd
import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from serving.api import (
    DEFAULT_XGBOOST_FEATURE_COLUMNS,
    FEATURE_COLUMNS,
    DEFAULT_MODEL_DIR,
    LSTM_FEATURE_COLUMNS,
    MAX_HISTORY_DAYS,
    ModelBundle,
    ModelRegistry,
    PredictionRequest,
    app,
    predict_units,
)


@pytest.fixture(autouse=True)
def isolated_service_environment(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    """Start the app without a token and without loading the real bundles."""
    monkeypatch.delenv("ML_API_TOKEN", raising=False)
    monkeypatch.setenv("ML_MODEL_DIR", str(tmp_path / "no-models"))


class FakeProphet:
    extra_regressors = {name: {} for name in FEATURE_COLUMNS}

    def predict(self, frame: pd.DataFrame) -> pd.DataFrame:
        assert bool(frame.iloc[0]["is_promotion"]) is True
        return pd.DataFrame({"yhat": [10.0]})


class FakeXGBoost:
    def predict(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == list(DEFAULT_XGBOOST_FEATURE_COLUMNS)
        assert frame.iloc[0]["lag_1"] == 13
        return np.array([20.0])


class FakeScaler:
    def transform(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == list(LSTM_FEATURE_COLUMNS)
        return frame.to_numpy(dtype=np.float32)

    def inverse_transform(self, values: np.ndarray) -> np.ndarray:
        return values


class FakeLSTM:
    def predict(self, values: np.ndarray, verbose: int) -> np.ndarray:
        assert values.shape == (1, 14, 5)
        assert verbose == 0
        return np.array([[30.0]])


class FakeMetaLearner:
    def predict(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == ["prophet_pred", "xgboost_pred", "lstm_pred"]
        return np.array([frame.iloc[0].sum()])


def make_request_body(history_days: int = 14) -> dict:
    target = date(2026, 10, 1)
    history = []
    for offset in range(history_days, 0, -1):
        history.append(
            {
                "date": (target - timedelta(days=offset)).isoformat(),
                "units_sold": max(0, 14 - offset),
                "is_promotion": False,
                "is_public_holiday": False,
                "is_school_term": True,
                "temperature_c": 22.0,
            }
        )
    history[-1]["is_promotion"] = True

    return {
        "sku": "1L",
        "date": target.isoformat(),
        "history": history,
        "is_promotion": True,
        "is_public_holiday": False,
        "is_school_term": True,
        "temperature_c": 23.5,
    }


def make_bundle() -> ModelBundle:
    return ModelBundle(
        prophet=FakeProphet(),
        xgboost=FakeXGBoost(),
        lstm=FakeLSTM(),
        lstm_scaler=FakeScaler(),
        meta_learner=FakeMetaLearner(),
        manifest={
            "model_version": "2026-10-01",
            "rmse_score": 12.3456,
            "xgboost_feature_cols": list(DEFAULT_XGBOOST_FEATURE_COLUMNS),
        },
    )


def test_stacked_prediction_scores_prophet_xgboost_and_lstm_outputs() -> None:
    payload = PredictionRequest.model_validate(make_request_body())

    prediction = predict_units(make_bundle(), payload)

    assert prediction == 60.0


def test_request_rejects_gaps_in_the_recent_history() -> None:
    body = make_request_body()
    body["history"][7]["date"] = body["history"][6]["date"]

    with pytest.raises(ValidationError, match="consecutive"):
        PredictionRequest.model_validate(body)


def test_predict_endpoint_returns_the_stacked_units() -> None:
    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 200
    assert response.json() == {
        "predicted_units_sold": 60.0,
        "model_version": "2026-10-01",
        "rmse_score": 12.3456,
    }


def test_predict_endpoint_reports_unavailable_sku_models() -> None:
    with TestClient(app) as client:
        app.state.models = {}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 503


def test_predict_endpoint_reports_missing_model_metadata() -> None:
    bundle = make_bundle()
    bundle.manifest.pop("rmse_score")

    with TestClient(app) as client:
        app.state.models = {"1L": bundle}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 503


def test_climatology_endpoint_returns_each_bundles_monthly_means() -> None:
    complete = make_bundle()
    complete.manifest["temperature_source"] = "monthly_mean"
    complete.manifest["temperature_climatology"] = {str(month): 20 + month / 10 for month in range(1, 13)}
    partial = make_bundle()
    partial.manifest["temperature_climatology"] = {"1": 21.0}
    missing = make_bundle()
    missing.manifest.pop("temperature_climatology", None)

    with TestClient(app) as client:
        app.state.models = {"1L": complete, "5L": partial, "20L": missing}

        response = client.get("/climatology")

    assert response.status_code == 200
    assert response.json() == {
        "models": {
            "1L": {
                "temperature_source": "monthly_mean",
                "months": {str(month): 20 + month / 10 for month in range(1, 13)},
            }
        }
    }


def test_health_endpoint_lists_served_model_versions() -> None:
    broken = make_bundle()
    broken.manifest.pop("model_version")

    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle(), "5L": broken}

        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "models": {"1L": "2026-10-01"}, "failed": []}


def test_request_rejects_a_history_longer_than_the_limit() -> None:
    PredictionRequest.model_validate(make_request_body(history_days=MAX_HISTORY_DAYS))

    with pytest.raises(ValidationError, match="at most 400"):
        PredictionRequest.model_validate(make_request_body(history_days=MAX_HISTORY_DAYS + 1))

    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        response = client.post("/predict", json=make_request_body(history_days=MAX_HISTORY_DAYS + 1))

    assert response.status_code == 422


def test_endpoints_are_open_and_warn_once_when_no_token_is_configured(
    caplog: pytest.LogCaptureFixture,
) -> None:
    with caplog.at_level(logging.WARNING, logger="serving.api"), TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        responses = [
            client.get("/health"),
            client.get("/climatology"),
            client.post("/predict", json=make_request_body()),
        ]

    assert [response.status_code for response in responses] == [200, 200, 200]
    assert sum("without authentication" in record.message for record in caplog.records) == 1


@pytest.mark.parametrize(
    "headers",
    [
        {},
        {"Authorization": "Bearer wrong-token"},
        {"Authorization": "Basic shared-secret"},
        {"Authorization": "shared-secret"},
        {"Authorization": "Bearer "},
    ],
)
def test_every_endpoint_rejects_a_missing_or_wrong_token(
    monkeypatch: pytest.MonkeyPatch, headers: dict[str, str]
) -> None:
    monkeypatch.setenv("ML_API_TOKEN", "shared-secret")

    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        responses = [
            client.get("/health", headers=headers),
            client.get("/climatology", headers=headers),
            client.post("/predict", json=make_request_body(), headers=headers),
            client.post("/predict", json={"sku": "1L"}, headers=headers),
        ]

    assert [response.status_code for response in responses] == [401, 401, 401, 401]
    assert all(response.json() == {"detail": "Missing or invalid API token"} for response in responses)


def test_every_endpoint_accepts_the_configured_token(
    monkeypatch: pytest.MonkeyPatch, caplog: pytest.LogCaptureFixture
) -> None:
    monkeypatch.setenv("ML_API_TOKEN", "shared-secret")
    headers = {"Authorization": "Bearer shared-secret"}

    with caplog.at_level(logging.WARNING, logger="serving.api"), TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        responses = [
            client.get("/health", headers=headers),
            client.get("/climatology", headers=headers),
            client.post("/predict", json=make_request_body(), headers=headers),
        ]

    assert [response.status_code for response in responses] == [200, 200, 200]
    assert not any("without authentication" in record.message for record in caplog.records)


def test_model_directory_defaults_to_the_exported_bundles_inside_the_package() -> None:
    assert DEFAULT_MODEL_DIR == Path(__file__).resolve().parents[1] / "outputs" / "saved_models"


@pytest.mark.parametrize(
    "error",
    [AttributeError("module has no attribute"), pickle.UnpicklingError("invalid load key"), ImportError("prophet")],
)
def test_registry_survives_one_sku_that_fails_to_load(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path, error: Exception
) -> None:
    for sku in ("1L", "5L", "20L"):
        (tmp_path / sku).mkdir()

    def load(self: ModelRegistry, sku_dir: Path) -> ModelBundle:
        if sku_dir.name == "5L":
            raise error
        return make_bundle()

    monkeypatch.setattr(ModelRegistry, "_load_bundle", load)
    registry = ModelRegistry(tmp_path)

    registry.load_available()

    assert sorted(registry.models) == ["1L", "20L"]
    assert registry.failed == ["5L"]


def test_health_endpoint_reports_skus_that_failed_to_load(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    (tmp_path / "5L").mkdir()
    monkeypatch.setenv("ML_MODEL_DIR", str(tmp_path))

    with TestClient(app) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "models": {}, "failed": ["5L"]}


def test_registry_warns_when_a_bundle_was_trained_with_another_library_version(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path, caplog: pytest.LogCaptureFixture
) -> None:
    (tmp_path / "1L").mkdir()
    bundle = make_bundle()
    bundle.manifest["library_versions"] = {"numpy": "0.1.0", "pandas": pd.__version__}
    monkeypatch.setattr(ModelRegistry, "_load_bundle", lambda self, sku_dir: bundle)

    with caplog.at_level(logging.WARNING, logger="serving.api"):
        ModelRegistry(tmp_path).load_available()

    drift = [record.message for record in caplog.records if "was trained with" in record.message]
    assert len(drift) == 1
    assert "numpy 0.1.0" in drift[0]


def test_predict_endpoint_hides_unexpected_model_failures() -> None:
    bundle = make_bundle()

    class ExplodingMetaLearner:
        def predict(self, frame: pd.DataFrame) -> np.ndarray:
            raise RuntimeError("secret internal path C:/models/1L")

    object.__setattr__(bundle, "meta_learner", ExplodingMetaLearner())

    with TestClient(app, raise_server_exceptions=False) as client:
        app.state.models = {"1L": bundle}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 500
    assert response.json() == {"detail": "The forecast service failed to score this request"}
