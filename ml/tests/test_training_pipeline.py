from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest

from serving.api import ModelRegistry, PredictionRequest, predict_units
from training.train_pipeline import (
    ARTIFACT_FILENAMES,
    chronological_split,
    load_daily_data,
    run_training,
)


def make_daily_data(days: int = 160) -> pd.DataFrame:
    start = date(2025, 1, 1)
    rows = []
    for index in range(days):
        current_date = start + timedelta(days=index)
        rows.append(
            {
                "date": current_date.isoformat(),
                "product_sku": "1L",
                "units_sold": 100 + index + (index % 7) * 4,
                "is_promotion": index % 13 == 0,
                "is_public_holiday": index % 29 == 0,
                "is_school_term": current_date.month not in (4, 8, 12),
                "temperature_c": 20 + (index % 9) * 0.4,
            }
        )

    return pd.DataFrame(rows)


def test_chronological_split_keeps_rows_in_time_order() -> None:
    data = make_daily_data()

    base_train, meta_train, final_test = chronological_split(data, lookback=14)

    assert base_train["date"].max() < meta_train["date"].min()
    assert meta_train["date"].max() < final_test["date"].min()
    assert len(base_train) == 96
    assert len(meta_train) == 32
    assert len(final_test) == 32


def test_training_exports_a_bundle_loadable_by_the_prediction_service(tmp_path) -> None:
    data_path = tmp_path / "daily.csv"
    output_dir = tmp_path / "saved_models"
    make_daily_data().to_csv(data_path, index=False)

    comparison = run_training(
        data_path=data_path,
        output_dir=output_dir,
        lookback=14,
        epochs=1,
        batch_size=16,
        verbose=0,
        seed=21,
    )

    sku_dir = output_dir / "1L"
    assert set(path.name for path in sku_dir.iterdir()) == set(ARTIFACT_FILENAMES)
    assert list(comparison.index) == ["1L"]
    assert set(comparison.columns.get_level_values("Model")) == {
        "Prophet",
        "XGBoost",
        "LSTM",
        "Stacked",
    }

    registry = ModelRegistry(output_dir)
    registry.load_available()
    assert set(registry.models) == {"1L"}

    history = make_daily_data().tail(14).copy()
    history["date"] = pd.to_datetime(history["date"])
    future_date = history["date"].iloc[-1] + timedelta(days=1)
    payload = {
        "sku": "1L",
        "date": future_date.isoformat(),
        "history": [
            {
                **row.drop(labels=["product_sku"]).to_dict(),
                "date": row["date"].date().isoformat(),
            }
            for _, row in history.iterrows()
        ],
        "is_promotion": False,
        "is_public_holiday": False,
        "is_school_term": True,
        "temperature_c": 22.0,
    }

    prediction = predict_units(
        registry.models["1L"],
        PredictionRequest.model_validate(payload),
    )

    assert np.isfinite(prediction)
    assert prediction >= 0


def test_training_rejects_duplicate_sku_dates(tmp_path) -> None:
    data = make_daily_data(days=40)
    data.loc[1, "date"] = data.loc[0, "date"]
    data_path = tmp_path / "duplicate.csv"
    data.to_csv(data_path, index=False)

    with pytest.raises(ValueError, match="duplicate SKU/date"):
        load_daily_data(data_path)
