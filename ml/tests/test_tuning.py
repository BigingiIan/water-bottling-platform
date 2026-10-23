import argparse

import pandas as pd

from tests.test_training_pipeline import make_daily_data
from training import tuning
from training.rolling_origin import default_configs, evaluate_sku
from training.tuning import SEARCH_SPACES, candidate_configs, inner_scores


def evaluation_args(**overrides: object) -> argparse.Namespace:
    values = {
        "models": ["naive"],
        "seeds": [21],
        "max_folds": 2,
        "fold_step": 28,
        "origins_per_fold": 28,
        "horizon": 28,
        "lstm_epochs": 1,
    }
    return argparse.Namespace(**{**values, **overrides})


def sku_frame(days: int = 400) -> pd.DataFrame:
    frame = make_daily_data(days)
    frame["date"] = pd.to_datetime(frame["date"])
    return frame


def test_config_selection_only_sees_rows_up_to_each_fold_origin() -> None:
    frame = sku_frame(days=450)
    args = evaluation_args()
    seen = []

    def spy(history, temperature_mode, fold, fold_origin):
        seen.append((len(history), fold_origin, history["date"].max()))
        return default_configs(args)

    evaluate_sku(frame, "climatology", args, {}, spy)

    assert seen == [
        (270, 269, frame["date"].iloc[269]),
        (298, 297, frame["date"].iloc[297]),
    ]


def test_every_model_gets_the_same_repeatable_budget_starting_with_the_default() -> None:
    for model_key in SEARCH_SPACES:
        candidates = candidate_configs(model_key, trials=12)

        assert len(candidates) == 12
        assert candidates[0] == tuning.DEFAULT_SEARCH_CONFIGS[model_key]
        assert len({str(config) for config in candidates}) == 12
        assert candidates == candidate_configs(model_key, trials=12)


def test_inner_window_ends_at_the_fold_origin(monkeypatch) -> None:
    frame = sku_frame().iloc[:240]
    captured = {}
    original = tuning.build_forecast_batch

    def spy(prepared, positions, horizon, history_length):
        captured["last_target"] = positions.max() + horizon
        return original(prepared, positions, horizon, history_length)

    monkeypatch.setattr(tuning, "build_forecast_batch", spy)
    scores = inner_scores(
        "xgboost", tuning.DEFAULT_SEARCH_CONFIGS["xgboost"], frame, "climatology", 28, 28
    )

    assert captured["last_target"] == len(frame) - 1
    assert set(scores) == {"inner_rmse_h1", "inner_rmse_mean", "inner_mape_mean"}
