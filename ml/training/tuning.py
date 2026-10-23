"""Nested hyperparameter tuning inside the rolling-origin evaluation.

Run from the ml directory:

    uv run python -m training.tuning --skus 1L --max-folds 1 --trials 2

For each outer fold with origin T, every candidate configuration is scored on an
inner window that ends at T. The candidate is fitted on data up to T - 55, then
forecasts origins T - 55 ... T - 28, 28 days each, so the last inner target date
is T itself. Nothing after the fold origin, and nothing in final_test, is used to
choose a configuration. The winner is then scored on the outer fold by the same
code as the baseline (rolling_origin.evaluate_sku).

Every model gets the same budget: the original configuration plus the same
number of random draws from its grid, with a fixed search seed. Inner scoring
uses one seed (21) and fits Prophet once at the inner origin rather than at every
inner forecast origin. Both choices keep the search affordable.
"""

from __future__ import annotations

import argparse
import itertools
import json
import logging
import sys
import time
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd

from training.forecasting import (
    DEFAULT_LSTM_CONFIG,
    DEFAULT_PROPHET_CONFIG,
    DEFAULT_XGBOOST_PARAMS,
    MAX_LAG,
    TemperatureClimatology,
    build_forecast_batch,
    fit_lstm,
    fit_prophet,
    fit_xgboost,
    forecast_lstm,
    forecast_xgboost,
    predict_prophet,
    prepare_context,
)
from training.rolling_origin import build_parser, default_configs, run_and_save

LOGGER = logging.getLogger("ml.tuning")
SEARCH_SEED = 2026
INNER_SEED = 21
TUNABLE_MODELS = ("prophet", "xgboost", "lstm")

# Lookback is capped at 14 because Laravel sends 14 days of history to /predict.
SEARCH_SPACES: dict[str, dict[str, list[Any]]] = {
    "prophet": {
        "changepoint_prior_scale": [0.001, 0.01, 0.05, 0.1, 0.5],
        "seasonality_mode": ["additive", "multiplicative"],
        "regressor_prior_scale": [0.1, 1.0, 10.0],
        "yearly_seasonality": ["auto", True, False],
    },
    "xgboost": {
        "max_depth": [2, 3, 4, 6, 8],
        "learning_rate": [0.03, 0.05, 0.1, 0.2],
        "n_estimators": [100, 200, 400, 800],
        "subsample": [0.6, 0.8, 1.0],
    },
    "lstm": {
        "units": [[64, 32], [32, 16], [32], [16]],
        "dropout": [0.0, 0.1, 0.2, 0.3],
        "lookback": [7, 14],
        "epochs": [15, 40],
    },
}
DEFAULT_SEARCH_CONFIGS: dict[str, dict[str, Any]] = {
    "prophet": dict(DEFAULT_PROPHET_CONFIG),
    "xgboost": {key: DEFAULT_XGBOOST_PARAMS[key] for key in SEARCH_SPACES["xgboost"]},
    "lstm": dict(DEFAULT_LSTM_CONFIG),
}


def candidate_configs(model_key: str, trials: int, seed: int = SEARCH_SEED) -> list[dict[str, Any]]:
    """The original configuration first, then `trials - 1` distinct random grid points."""
    space = SEARCH_SPACES[model_key]
    default = DEFAULT_SEARCH_CONFIGS[model_key]
    grid = [dict(zip(space, values)) for values in itertools.product(*space.values())]
    if default not in grid:
        raise ValueError(f"The {model_key} default configuration is not in its search grid.")

    others = [config for config in grid if config != default]
    picks = np.random.default_rng(seed).choice(len(others), size=trials - 1, replace=False)
    return [default, *(others[index] for index in picks)]


def inner_scores(
    model_key: str,
    config: dict[str, Any],
    history: pd.DataFrame,
    temperature_mode: str,
    origins_per_fold: int,
    horizon: int,
) -> dict[str, float]:
    """Score one configuration on the inner window that ends at the last history row."""
    last = len(history) - 1
    inner_origin = last - (origins_per_fold - 1) - horizon
    climatology = TemperatureClimatology.fit(history.iloc[: inner_origin + 1])
    prepared = prepare_context(history, temperature_mode, climatology)
    train = prepared.iloc[: inner_origin + 1]
    positions = np.arange(inner_origin, inner_origin + origins_per_fold)
    batch = build_forecast_batch(prepared, positions, horizon, MAX_LAG)
    offsets = (positions - inner_origin)[:, None] + np.arange(horizon)[None, :]
    actuals = history["units_sold"].to_numpy(dtype=float)[inner_origin + 1 + offsets]

    if model_key == "prophet":
        model, regressors = fit_prophet(train, **config)
        predictions = predict_prophet(model, regressors, prepared.iloc[inner_origin + 1 : last + 1])[
            offsets
        ]
    elif model_key == "xgboost":
        predictions = forecast_xgboost(fit_xgboost(train, INNER_SEED, params=config), batch)
    else:
        model, scaler = fit_lstm(train, INNER_SEED, **config)
        predictions = forecast_lstm(model, scaler, batch, config["lookback"])

    rmse_by_horizon = np.sqrt(np.mean((actuals - predictions) ** 2, axis=0))
    return {
        "inner_rmse_h1": float(rmse_by_horizon[0]),
        "inner_rmse_mean": float(rmse_by_horizon.mean()),
        "inner_mape_mean": float(np.mean(np.abs(actuals - predictions) / actuals) * 100),
    }


def select_config(
    model_key: str,
    history: pd.DataFrame,
    temperature_mode: str,
    trials: int,
    origins_per_fold: int,
    horizon: int,
    objective: str = "inner_rmse_mean",
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """Score every candidate on the inner window ending at the last history row.

    Returns the winning configuration and one log row per trial. Ties go to the
    earlier trial, so the original configuration wins any tie.
    """
    candidates = candidate_configs(model_key, trials)
    trial_rows = []
    for trial, config in enumerate(candidates):
        started = time.perf_counter()
        scores = inner_scores(model_key, config, history, temperature_mode, origins_per_fold, horizon)
        trial_rows.append(
            {
                "model": model_key,
                "trial": trial,
                "is_default": trial == 0,
                "config": json.dumps(config, sort_keys=True),
                **scores,
                "seconds": round(time.perf_counter() - started, 2),
            }
        )

    best = min(trial_rows, key=lambda row: (row[objective], row["trial"]))
    for row in trial_rows:
        row["selected"] = row is best

    return candidates[best["trial"]], trial_rows


class NestedTuner:
    """Chooses each model's configuration per fold and logs every trial."""

    def __init__(self, args: argparse.Namespace) -> None:
        self.args = args
        self.log: list[dict[str, Any]] = []

    def __call__(
        self,
        history: pd.DataFrame,
        temperature_mode: str,
        fold: int,
        fold_origin: int,
    ) -> dict[str, dict[str, Any]]:
        configs = default_configs(self.args)
        sku = str(history["product_sku"].iloc[0])

        for model_key in self.args.tuned_models:
            config, trial_rows = select_config(
                model_key,
                history,
                temperature_mode,
                self.args.trials,
                self.args.origins_per_fold,
                self.args.horizon,
                self.args.objective,
            )
            labels = {
                "temperature_mode": temperature_mode,
                "sku": sku,
                "fold": fold,
                "fold_origin": history["date"].iloc[fold_origin],
            }
            self.log.extend({**labels, **row} for row in trial_rows)
            best = next(row for row in trial_rows if row["selected"])

            configs[model_key] = config
            if model_key == "prophet":
                configs["prophet_fold"] = configs["prophet"]
            LOGGER.info(
                "SKU %s | %s | fold %s | %s picked trial %s (%s=%.2f, default %.2f)",
                sku,
                temperature_mode,
                fold + 1,
                model_key,
                best["trial"],
                self.args.objective,
                best[self.args.objective],
                trial_rows[0][self.args.objective],
            )

        return configs


def compare_runs(baseline_dir: Path, tuned_dir: Path) -> pd.DataFrame:
    """Baseline vs tuned per SKU and model, with the fold-paired difference.

    Seeds are averaged within each fold first. A difference smaller than its
    fold-to-fold SD is within noise.
    """
    keys = ["temperature_mode", "horizon", "model", "sku", "fold"]

    def per_fold(directory: Path) -> pd.DataFrame:
        metrics = pd.read_csv(directory / "fold_metrics.csv")
        return metrics.groupby(keys)[["RMSE", "MAPE", "sMAPE"]].mean()

    joined = per_fold(baseline_dir).join(per_fold(tuned_dir), lsuffix="_baseline", rsuffix="_tuned", how="inner")
    for metric in ("RMSE", "MAPE", "sMAPE"):
        joined[f"{metric}_diff"] = joined[f"{metric}_tuned"] - joined[f"{metric}_baseline"]

    group = ["temperature_mode", "horizon", "model", "sku"]
    result = joined.groupby(group).agg(["mean", "std"])
    result.columns = [f"{column}_{'fold_sd' if stat == 'std' else stat}" for column, stat in result.columns]
    result["RMSE_verdict"] = np.where(
        result["RMSE_diff_mean"].abs() < result["RMSE_diff_fold_sd"].fillna(np.inf),
        "within noise",
        np.where(result["RMSE_diff_mean"] < 0, "tuned better", "tuned worse"),
    )
    return result.reset_index()


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    sys.stdout.reconfigure(encoding="utf-8")
    ml_root = Path(__file__).resolve().parents[1]
    parser = build_parser()
    parser.add_argument("--trials", type=int, default=12)
    parser.add_argument("--tuned-models", nargs="*", choices=TUNABLE_MODELS, default=list(TUNABLE_MODELS))
    parser.add_argument(
        "--objective",
        choices=("inner_rmse_mean", "inner_rmse_h1"),
        default="inner_rmse_mean",
    )
    parser.add_argument("--baseline", type=Path, default=ml_root / "outputs" / "rolling_origin" / "baseline")
    parser.set_defaults(
        output=ml_root / "outputs" / "rolling_origin" / "tuned",
        models=["prophet", "prophet_fold", "xgboost", "lstm"],
        temperature_modes=["climatology"],
    )
    args = parser.parse_args()

    tuner = NestedTuner(args)
    run_and_save(args, tuner)
    pd.DataFrame(tuner.log).to_csv(args.output / "tuning_log.csv", index=False)

    if (args.baseline / "fold_metrics.csv").is_file():
        comparison = compare_runs(args.baseline, args.output)
        comparison.to_csv(args.output / "baseline_vs_tuned.csv", index=False)
        print("\nBaseline vs tuned RMSE (fold-paired difference, tuned minus baseline):")
        print(
            comparison[comparison["horizon"].isin(["h1", "h1-28 mean"])][
                ["horizon", "model", "sku", "RMSE_baseline_mean", "RMSE_tuned_mean",
                 "RMSE_diff_mean", "RMSE_diff_fold_sd", "RMSE_verdict"]
            ].to_string(index=False, float_format=lambda value: f"{value:.1f}")
        )

    return 0


if __name__ == "__main__":
    sys.exit(main())
