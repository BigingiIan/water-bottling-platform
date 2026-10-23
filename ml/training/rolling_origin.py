"""Rolling-origin (expanding window) evaluation of the per-SKU base models.

Run from the ml directory:

    uv run python -m training.rolling_origin --skus 1L --max-folds 1

Fold layout (per SKU). With the default --period development every target date is inside
meta_train and final_test is never read. --period final_test runs the same layout starting at
the end of meta_train, and is meant to be run once, after every choice has been made:

- Fold k is refitted at origin T_k = last base_train day + 28 * k, on every day up to T_k.
- Within a fold there is one forecast origin per day, s = T_k ... T_k + 27, and each origin
  forecasts s+1 ... s+28. Folds stop before any target date reaches final_test.
- XGBoost and LSTM keep their fold fit and condition on actual sales up to s. For h > 1 they
  forecast recursively.
- "Prophet" is refitted at every forecast origin s (re-anchored); "Prophet (fold fit)" is fitted
  once at T_k, which shows how much daily re-anchoring is worth.
"""

from __future__ import annotations

import argparse
import json
import logging
import platform
import sys
import time
from datetime import datetime, timezone
from importlib import metadata
from pathlib import Path
from typing import Any, Callable

import numpy as np
import pandas as pd

from training.forecasting import (
    DEFAULT_LOOKBACK,
    DEFAULT_LSTM_CONFIG,
    DEFAULT_PROPHET_CONFIG,
    MAX_LAG,
    TEMPERATURE_MODES,
    TemperatureClimatology,
    build_forecast_batch,
    fit_lstm,
    fit_prophet,
    fit_xgboost,
    forecast_lstm,
    forecast_seasonal_naive,
    forecast_xgboost,
    predict_prophet,
    prepare_context,
)
from training.train_pipeline import chronological_split, load_daily_data

LOGGER = logging.getLogger("ml.rolling_origin")
DEFAULT_SEEDS = (21, 22, 23)
DETERMINISTIC_SEED = -1
MODEL_NAMES = {
    "naive": "Seasonal naive",
    "prophet": "Prophet",
    "prophet_fold": "Prophet (fold fit)",
    "xgboost": "XGBoost",
    "lstm": "LSTM",
}
REPORTED_HORIZONS = (1, 7, 14, 28)
HORIZON_MEAN_LABEL = "h1-28 mean"
PERIODS = ("development", "final_test")
LIBRARIES = ("numpy", "pandas", "prophet", "cmdstanpy", "xgboost", "tensorflow", "scikit-learn")


def build_parser() -> argparse.ArgumentParser:
    ml_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--data",
        type=Path,
        default=ml_root.parent / "data" / "kenya_water_demand_daily.csv",
    )
    parser.add_argument("--output", type=Path, default=ml_root / "outputs" / "rolling_origin")
    parser.add_argument("--skus", nargs="*", default=None)
    parser.add_argument(
        "--temperature-modes", nargs="*", choices=TEMPERATURE_MODES, default=list(TEMPERATURE_MODES)
    )
    parser.add_argument("--models", nargs="*", choices=MODEL_NAMES, default=list(MODEL_NAMES))
    parser.add_argument("--seeds", nargs="*", type=int, default=list(DEFAULT_SEEDS))
    parser.add_argument("--max-folds", type=int, default=6)
    parser.add_argument("--fold-step", type=int, default=28)
    parser.add_argument("--origins-per-fold", type=int, default=28)
    parser.add_argument("--horizon", type=int, default=28)
    parser.add_argument("--lstm-epochs", type=int, default=15)
    parser.add_argument(
        "--period",
        choices=PERIODS,
        default="development",
        help="development: folds inside meta_train. final_test: the single held-out pass.",
    )
    return parser


def parse_args() -> argparse.Namespace:
    return build_parser().parse_args()


def library_versions() -> dict[str, str]:
    versions = {"python": platform.python_version()}
    for library in LIBRARIES:
        try:
            versions[library] = metadata.version(library)
        except metadata.PackageNotFoundError:
            versions[library] = "not installed"

    return versions


def fold_origins(
    base_end: int,
    meta_end: int,
    fold_step: int,
    origins_per_fold: int,
    horizon: int,
    max_folds: int,
) -> list[int]:
    """Row positions of each fold's refit origin; every target stays before meta_end."""
    origins = []
    origin = base_end - 1
    while len(origins) < max_folds and origin + origins_per_fold - 1 + horizon <= meta_end - 1:
        origins.append(origin)
        origin += fold_step

    if not origins:
        raise ValueError("The development period is too short for a single fold.")

    return origins


def _prediction_frame(
    labels: dict[str, object],
    origin_dates: np.ndarray,
    target_dates: np.ndarray,
    actuals: np.ndarray,
    predictions: np.ndarray,
) -> pd.DataFrame:
    count, horizon = predictions.shape
    return pd.DataFrame(
        {
            **labels,
            "origin_date": np.repeat(origin_dates, horizon),
            "target_date": target_dates.reshape(-1),
            "horizon": np.tile(np.arange(1, horizon + 1), count),
            "actual": actuals.reshape(-1),
            "predicted": predictions.reshape(-1),
        }
    )


def default_configs(args: argparse.Namespace) -> dict[str, dict[str, Any]]:
    """The original pipeline's hyperparameters for every model."""
    return {
        "prophet": dict(DEFAULT_PROPHET_CONFIG),
        "prophet_fold": dict(DEFAULT_PROPHET_CONFIG),
        "xgboost": {},
        "lstm": {**DEFAULT_LSTM_CONFIG, "epochs": args.lstm_epochs},
    }


ConfigSelector = Callable[[pd.DataFrame, str, int, int], dict[str, dict[str, Any]]]


def evaluate_sku(
    sku_data: pd.DataFrame,
    temperature_mode: str,
    args: argparse.Namespace,
    timings: dict[str, float],
    select_configs: ConfigSelector | None = None,
) -> list[pd.DataFrame]:
    """Score every requested model on each outer fold of one SKU.

    `select_configs(sku_data, temperature_mode, fold, fold_origin)` returns the
    hyperparameters to use in that fold; it must only look at rows up to the
    fold origin. Without it, every fold uses the original defaults.
    """
    sku = str(sku_data["product_sku"].iloc[0])
    count = len(sku_data)
    base_train, meta_train, _ = chronological_split(sku_data, DEFAULT_LOOKBACK)
    base_end, meta_end = len(base_train), len(base_train) + len(meta_train)
    period = getattr(args, "period", "development")
    window_start, window_end = (base_end, meta_end) if period == "development" else (meta_end, count)
    origins = fold_origins(
        window_start, window_end, args.fold_step, args.origins_per_fold, args.horizon, args.max_folds
    )
    units = sku_data["units_sold"].to_numpy(dtype=float)
    frames = []

    for fold, fold_origin in enumerate(origins):
        configs = (
            select_configs(sku_data.iloc[: fold_origin + 1], temperature_mode, fold, fold_origin)
            if select_configs is not None
            else default_configs(args)
        )
        climatology = TemperatureClimatology.fit(sku_data.iloc[: fold_origin + 1])
        prepared = prepare_context(sku_data, temperature_mode, climatology)
        fold_train = prepared.iloc[: fold_origin + 1]
        positions = np.arange(fold_origin, fold_origin + args.origins_per_fold)
        batch = build_forecast_batch(
            prepared, positions, args.horizon, max(DEFAULT_LOOKBACK, MAX_LAG)
        )
        actuals = units[positions[:, None] + np.arange(1, args.horizon + 1)[None, :]]

        def record(model_key: str, seed: int, predictions: np.ndarray) -> None:
            labels = {
                "sku": sku,
                "period": period,
                "temperature_mode": temperature_mode,
                "model": MODEL_NAMES[model_key],
                "seed": seed,
                "fold": fold,
                "fold_origin": prepared["date"].iloc[fold_origin],
                "train_rows": fold_origin + 1,
            }
            frames.append(
                _prediction_frame(labels, batch.origin_dates, batch.target_dates, actuals, predictions)
            )

        def timed(model_key: str, started: float) -> None:
            timings[model_key] = timings.get(model_key, 0.0) + time.perf_counter() - started

        if "naive" in args.models:
            record("naive", DETERMINISTIC_SEED, forecast_seasonal_naive(batch))

        if "prophet_fold" in args.models:
            started = time.perf_counter()
            model, regressors = fit_prophet(fold_train, **configs["prophet_fold"])
            span = predict_prophet(
                model, regressors, prepared.iloc[fold_origin + 1 : positions[-1] + args.horizon + 1]
            )
            offsets = (positions - fold_origin)[:, None] + np.arange(args.horizon)[None, :]
            record("prophet_fold", DETERMINISTIC_SEED, span[offsets])
            timed("prophet_fold", started)

        if "prophet" in args.models:
            started = time.perf_counter()
            predictions = np.empty((len(positions), args.horizon))
            for index, origin in enumerate(positions):
                model, regressors = fit_prophet(prepared.iloc[: origin + 1], **configs["prophet"])
                predictions[index] = predict_prophet(
                    model, regressors, prepared.iloc[origin + 1 : origin + 1 + args.horizon]
                )
            record("prophet", DETERMINISTIC_SEED, predictions)
            timed("prophet", started)

        for seed in args.seeds:
            if "xgboost" in args.models:
                started = time.perf_counter()
                xgb_model = fit_xgboost(fold_train, seed, params=configs["xgboost"])
                record("xgboost", seed, forecast_xgboost(xgb_model, batch))
                timed("xgboost", started)

            if "lstm" in args.models:
                started = time.perf_counter()
                lstm_model, scaler = fit_lstm(fold_train, seed, **configs["lstm"])
                record(
                    "lstm",
                    seed,
                    forecast_lstm(lstm_model, scaler, batch, configs["lstm"]["lookback"]),
                )
                timed("lstm", started)

        LOGGER.info(
            "SKU %s | %s | fold %s/%s (origin %s, %s train rows) done",
            sku,
            temperature_mode,
            fold + 1,
            len(origins),
            prepared["date"].iloc[fold_origin].date(),
            fold_origin + 1,
        )

    return frames


def fold_metrics(predictions: pd.DataFrame) -> pd.DataFrame:
    """RMSE, MAPE and sMAPE per SKU, model, seed, fold and horizon."""
    errors = predictions.assign(
        squared_error=(predictions["actual"] - predictions["predicted"]) ** 2,
        ape=(predictions["actual"] - predictions["predicted"]).abs()
        / predictions["actual"].clip(lower=1e-8)
        * 100,
        sape=(predictions["actual"] - predictions["predicted"]).abs()
        / ((predictions["actual"].abs() + predictions["predicted"].abs()) / 2).clip(lower=1e-8)
        * 100,
    )
    keys = ["temperature_mode", "sku", "model", "seed", "fold", "horizon"]
    grouped = errors.groupby(keys, sort=True)[["squared_error", "ape", "sape"]].mean()
    return pd.DataFrame(
        {
            "RMSE": np.sqrt(grouped["squared_error"]),
            "MAPE": grouped["ape"],
            "sMAPE": grouped["sape"],
        }
    ).reset_index()


def horizon_buckets(metrics: pd.DataFrame) -> pd.DataFrame:
    """Keep the reported horizons and add the mean over every horizon in the run."""
    keys = ["temperature_mode", "sku", "model", "seed", "fold"]
    selected = metrics[metrics["horizon"].isin(REPORTED_HORIZONS)].copy()
    selected["horizon"] = "h" + selected["horizon"].astype(str)
    mean = metrics.groupby(keys, sort=True)[["RMSE", "MAPE", "sMAPE"]].mean().reset_index()
    mean["horizon"] = HORIZON_MEAN_LABEL
    return pd.concat([selected, mean], ignore_index=True)


def summarise(bucketed: pd.DataFrame) -> pd.DataFrame:
    """Mean and fold-to-fold SD per SKU, plus the simple mean across SKUs.

    Seeds are averaged within each fold first. seed_sd is the SD across seeds,
    averaged over folds, so it measures seed noise separately from fold noise.
    """
    metric_columns = ["RMSE", "MAPE", "sMAPE"]
    keys = ["temperature_mode", "horizon", "model", "sku", "fold"]
    per_fold = bucketed.groupby(keys)[metric_columns].mean()
    seed_sd = bucketed.groupby(keys)[metric_columns].std(ddof=1).fillna(0.0)

    all_skus = per_fold.groupby(["temperature_mode", "horizon", "model", "fold"]).mean()
    all_skus["sku"] = "Mean of SKUs"
    all_skus = all_skus.set_index("sku", append=True).reorder_levels(keys)
    all_seed_sd = seed_sd.groupby(["temperature_mode", "horizon", "model", "fold"]).mean()
    all_seed_sd["sku"] = "Mean of SKUs"
    all_seed_sd = all_seed_sd.set_index("sku", append=True).reorder_levels(keys)

    per_fold = pd.concat([per_fold, all_skus])
    seed_sd = pd.concat([seed_sd, all_seed_sd])
    group = ["temperature_mode", "horizon", "model", "sku"]
    summary = per_fold.groupby(group).agg(["mean", "std", "count"])
    summary.columns = [
        f"{metric}_{'fold_sd' if stat == 'std' else stat}" for metric, stat in summary.columns
    ]
    for metric in metric_columns:
        summary[f"{metric}_seed_sd"] = seed_sd.groupby(group)[metric].mean()

    summary = summary.rename(columns={"RMSE_count": "folds"}).drop(
        columns=["MAPE_count", "sMAPE_count"]
    )
    return summary.reset_index()


def print_summary(summary: pd.DataFrame) -> None:
    def cell(row: pd.Series, metric: str) -> str:
        return f"{row[f'{metric}_mean']:.1f} ± {row[f'{metric}_fold_sd']:.1f}"

    for mode in summary["temperature_mode"].unique():
        label = "REALISTIC (monthly-mean temperature)" if mode == "climatology" else (
            "UPPER BOUND (actual temperature known in advance)"
        )
        for horizon in ("h1", HORIZON_MEAN_LABEL):
            subset = summary[
                (summary["temperature_mode"] == mode) & (summary["horizon"] == horizon)
            ]
            for metric in ("RMSE", "MAPE"):
                table = subset.assign(value=subset.apply(cell, axis=1, metric=metric)).pivot(
                    index="sku", columns="model", values="value"
                )
                print(f"\n{label} | {horizon} | {metric} mean ± fold SD")
                print(table.to_string())


def run_and_save(
    args: argparse.Namespace,
    select_configs: ConfigSelector | None = None,
) -> pd.DataFrame:
    """Evaluate every SKU and temperature mode, write all outputs, return the summary."""
    versions = library_versions()
    print("Library versions:", json.dumps(versions))

    if "lstm" in args.models or "lstm" in getattr(args, "tuned_models", ()):
        import tensorflow as tf

        tf.config.experimental.enable_op_determinism()

    data = load_daily_data(args.data)
    skus = args.skus or sorted(data["product_sku"].unique())
    timings: dict[str, float] = {}
    frames = []
    started = time.perf_counter()

    for sku in skus:
        sku_data = data[data["product_sku"] == sku].reset_index(drop=True)
        if sku_data.empty:
            raise ValueError(f"Unknown SKU: {sku}")
        for mode in args.temperature_modes:
            frames.extend(evaluate_sku(sku_data, mode, args, timings, select_configs))

    elapsed = time.perf_counter() - started
    predictions = pd.concat(frames, ignore_index=True)
    metrics = fold_metrics(predictions)
    bucketed = horizon_buckets(metrics)
    summary = summarise(bucketed)

    args.output.mkdir(parents=True, exist_ok=True)
    predictions.to_csv(args.output / "predictions.csv.gz", index=False)
    bucketed.to_csv(args.output / "fold_metrics.csv", index=False)
    summary.to_csv(args.output / "summary.csv", index=False)
    run_config = {
        "created_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "command": sys.argv,
        "library_versions": versions,
        "elapsed_seconds": round(elapsed, 1),
        "model_seconds": {key: round(value, 1) for key, value in timings.items()},
        **{key: (str(value) if isinstance(value, Path) else value) for key, value in vars(args).items()},
    }
    (args.output / "run_config.json").write_text(json.dumps(run_config, indent=2) + "\n")

    print_summary(summary)
    print(f"\nElapsed {elapsed:.0f}s; per model: {run_config['model_seconds']}")
    print(f"Wrote predictions, fold metrics and summary to {args.output}")
    return summary


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    sys.stdout.reconfigure(encoding="utf-8")
    run_and_save(parse_args())
    return 0


if __name__ == "__main__":
    sys.exit(main())
