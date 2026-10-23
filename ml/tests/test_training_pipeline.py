import json
from datetime import date, timedelta

import pandas as pd
import pytest

from serving.api import ModelRegistry
from training.train_pipeline import (
    ARTIFACT_FILENAMES,
    chronological_split,
    load_daily_data,
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


def test_training_exports_the_full_bundle_layout_and_the_metrics_files(trained_model_dir) -> None:
    sku_dir = trained_model_dir / "1L"

    assert set(path.name for path in sku_dir.iterdir()) == set(ARTIFACT_FILENAMES)
    assert {path.name for path in trained_model_dir.iterdir() if path.is_file()} == {
        "forecast_metrics.csv",
        "forecast_metrics_summary.csv",
        "metrics_manifest.json",
        "final_xgboost_tuning.csv",
    }

    registry = ModelRegistry(trained_model_dir)
    registry.load_available()
    assert set(registry.models) == {"1L"}


def test_metrics_file_marks_the_final_test_run_and_the_reported_estimate(trained_model_dir) -> None:
    metrics = pd.read_csv(trained_model_dir / "forecast_metrics.csv")
    manifest = json.loads((trained_model_dir / "metrics_manifest.json").read_text())

    assert set(metrics["run"]) == {"final_test"}
    assert metrics["is_final_test"].all()
    assert metrics.loc[metrics["is_reported_estimate"], "model"].eq("Prophet").all()
    assert sorted(metrics["fold"]) == [0, 1]
    assert manifest["final_test_runs"] == ["final_test"]
    assert manifest["reported_estimate"]["per_sku_rmse"] == {"1L": 12.8456}


def test_training_rejects_duplicate_sku_dates(tmp_path) -> None:
    data = make_daily_data(days=40)
    data.loc[1, "date"] = data.loc[0, "date"]
    data_path = tmp_path / "duplicate.csv"
    data.to_csv(data_path, index=False)

    with pytest.raises(ValueError, match="duplicate SKU/date"):
        load_daily_data(data_path)
