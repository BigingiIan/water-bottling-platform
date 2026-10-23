from pathlib import Path

import pandas as pd
import pytest

from tests.test_training_pipeline import make_daily_data
from training.train_pipeline import run_training

FINAL_TEST_RMSE = 12.3456


def write_fake_evaluation(directory: Path, sku: str) -> None:
    """The minimum final_test outputs run_training reads: summary, predictions, fold metrics."""
    final_test = directory / "final_test"
    final_test.mkdir(parents=True)
    pd.DataFrame(
        [
            {
                "temperature_mode": "climatology",
                "horizon": "h1",
                "model": "Prophet",
                "sku": sku,
                "RMSE_mean": FINAL_TEST_RMSE,
                "RMSE_fold_sd": 1.0,
                "MAPE_mean": 10.0,
                "MAPE_fold_sd": 0.5,
                "sMAPE_mean": 9.5,
                "sMAPE_fold_sd": 0.5,
                "folds": 6,
            }
        ]
    ).to_csv(final_test / "summary.csv", index=False)
    pd.DataFrame(
        {
            "sku": [sku, sku],
            "fold": [0, 1],
            "fold_origin": ["2025-05-25", "2025-06-22"],
            "target_date": ["2025-05-26", "2025-07-20"],
        }
    ).to_csv(final_test / "predictions.csv.gz", index=False)
    pd.DataFrame(
        [
            {
                "temperature_mode": "climatology",
                "sku": sku,
                "model": "Prophet",
                "seed": -1,
                "fold": fold,
                "horizon": "h1",
                "RMSE": FINAL_TEST_RMSE + fold,
                "MAPE": 10.0,
                "sMAPE": 9.5,
            }
            for fold in (0, 1)
        ]
    ).to_csv(final_test / "fold_metrics.csv", index=False)


@pytest.fixture(scope="session")
def trained_model_dir(tmp_path_factory: pytest.TempPathFactory) -> Path:
    """A real 1L bundle trained once per test session, with the smallest settings."""
    root = tmp_path_factory.mktemp("training")
    data_path = root / "daily.csv"
    make_daily_data().to_csv(data_path, index=False)
    write_fake_evaluation(root / "evaluation", "1L")

    run_training(
        data_path=data_path,
        output_dir=root / "saved_models",
        evaluation_dir=root / "evaluation",
        xgboost_trials=2,
        epochs=1,
        verbose=0,
        seed=21,
    )
    return root / "saved_models"
