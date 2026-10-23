"""Refit the chosen forecasting configuration on all data and export serving bundles.

Run from the ml directory, after the rolling-origin evaluation has written
outputs/rolling_origin (baseline, tuned, stacking, final_test, stacking_final_test):

    uv run python -m training.train_pipeline

The configuration and its reported accuracy come from that evaluation. Prophet
alone (stacker weights [1, 0, 0]) was chosen on development folds inside
meta_train, and rmse_score is Prophet's h=1 RMSE on the single final_test pass,
made with models fitted before that window. The refitted models here have seen
every row, so they have no held-out score of their own; the final_test estimate
is the honest one.

XGBoost and the LSTM are still fitted and saved with zero weight, so the
comparison can be re-run on real data later. The serving code skips them.
"""

from __future__ import annotations

import argparse
import json
import logging
import re
import shutil
import sys
import tempfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd

from training.forecasting import (
    CONTEXT_COLUMNS,
    DEFAULT_LSTM_CONFIG,
    DEFAULT_PROPHET_CONFIG,
    XGBOOST_FEATURE_COLUMNS,
    LinearStacker,
    TemperatureClimatology,
    fit_lstm,
    fit_prophet,
    fit_xgboost,
    prepare_context,
)

LOGGER = logging.getLogger("ml.training")
SEED = 21
REQUIRED_DATA_COLUMNS = {
    "date",
    "product_sku",
    "units_sold",
    *CONTEXT_COLUMNS,
}
ARTIFACT_FILENAMES = (
    "prophet.joblib",
    "xgboost.joblib",
    "lstm.keras",
    "lstm_scaler.joblib",
    "meta_stacker.joblib",
    "meta_learner.joblib",
    "metadata.json",
    "manifest.json",
)
ENSEMBLE_MODE = "prophet_only"
STACKER_WEIGHTS = {"prophet": 1.0, "xgboost": 0.0, "lstm": 0.0}
REPORTED_MODEL = "Prophet"
REPORTED_HORIZON = "h1"
TUNING_ORIGINS = 28
TUNING_HORIZON = 28
EVALUATION_PROTOCOL = {
    "scheme": "rolling origin, expanding window",
    "headline_horizon_days": 1,
    "max_horizon_days": 28,
    "fold_step_days": 28,
    "origins_per_fold": 28,
    "temperature": "monthly mean of the training rows",
    "selection": "stacker variant and hyperparameters chosen on development folds inside meta_train",
    "reported_estimate": "Prophet h=1 RMSE on the single final_test pass, pre-refit models",
    "data": "synthetic (generator seed 42); not a real-world accuracy estimate",
}


@dataclass
class FittedSku:
    prophet: Any
    xgboost: Any
    lstm: Any
    lstm_scaler: Any
    meta_stacker: LinearStacker
    metadata: dict[str, Any]
    xgboost_trials: list[dict[str, Any]]


def parse_args() -> argparse.Namespace:
    ml_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--data",
        type=Path,
        default=ml_root.parent / "data" / "kenya_water_demand_daily.csv",
        help="CSV with date, product_sku, units_sold and the four context features.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=ml_root / "saved_models",
        help="Directory receiving one model artifact directory per SKU and the metrics files.",
    )
    parser.add_argument(
        "--evaluation",
        type=Path,
        default=ml_root / "outputs" / "rolling_origin",
        help="Directory holding the rolling-origin evaluation runs.",
    )
    parser.add_argument("--xgboost-trials", type=int, default=12)
    parser.add_argument("--epochs", type=int, default=DEFAULT_LSTM_CONFIG["epochs"])
    parser.add_argument("--verbose", type=int, choices=(0, 1, 2), default=0)
    parser.add_argument("--seed", type=int, default=SEED)
    return parser.parse_args()


def load_daily_data(path: Path) -> pd.DataFrame:
    if not path.is_file():
        raise FileNotFoundError(f"Training data file does not exist: {path}")

    frame = pd.read_csv(path)
    missing_columns = sorted(REQUIRED_DATA_COLUMNS - set(frame.columns))
    if missing_columns:
        raise ValueError(f"Training data is missing columns: {missing_columns}")

    frame = frame.copy()
    frame["date"] = pd.to_datetime(frame["date"], errors="coerce")
    if frame["date"].isna().any():
        raise ValueError("Training data contains invalid dates.")

    if frame["product_sku"].isna().any():
        raise ValueError("Training data contains an empty product_sku.")
    frame["product_sku"] = frame["product_sku"].astype(str)
    invalid_skus = [
        sku
        for sku in frame["product_sku"].unique()
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", sku)
    ]
    if invalid_skus:
        raise ValueError(f"SKU values cannot be used as artifact directory names: {invalid_skus}")

    if frame["units_sold"].isna().any() or (frame["units_sold"] < 0).any():
        raise ValueError("units_sold must contain finite, non-negative values.")

    for column in CONTEXT_COLUMNS:
        if frame[column].isna().any():
            raise ValueError(f"Training data contains missing values in {column}.")

    if not np.isfinite(frame[["units_sold", "temperature_c"]].to_numpy(dtype=float)).all():
        raise ValueError("Training data contains non-finite numeric values.")

    for column in CONTEXT_COLUMNS[:3]:
        if not frame[column].isin([0, 1, False, True]).all():
            raise ValueError(f"{column} must contain boolean or 0/1 values.")
        frame[column] = frame[column].astype(bool)

    frame["temperature_c"] = frame["temperature_c"].astype(float)
    frame["units_sold"] = frame["units_sold"].astype(float)
    frame = frame.sort_values(["product_sku", "date"]).reset_index(drop=True)

    duplicates = frame.duplicated(["product_sku", "date"])
    if duplicates.any():
        examples = frame.loc[duplicates, ["product_sku", "date"]].head().to_dict("records")
        raise ValueError(f"Training data has duplicate SKU/date rows: {examples}")

    return frame


def chronological_split(
    sku_data: pd.DataFrame,
    lookback: int,
) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    count = len(sku_data)
    base_end = int(count * 0.60)
    meta_end = int(count * 0.80)
    base_train = sku_data.iloc[:base_end].copy().reset_index(drop=True)
    meta_train = sku_data.iloc[base_end:meta_end].copy().reset_index(drop=True)
    final_test = sku_data.iloc[meta_end:].copy().reset_index(drop=True)

    if len(base_train) < lookback + 2:
        raise ValueError(
            f"Need at least {lookback + 2} base rows; got {len(base_train)}."
        )
    if len(meta_train) < lookback + 2:
        raise ValueError(
            f"Need at least {lookback + 2} meta-training rows; got {len(meta_train)}."
        )
    if len(final_test) < 1:
        raise ValueError("The final test split must contain at least one row.")

    return base_train, meta_train, final_test


def final_test_estimates(evaluation_dir: Path) -> tuple[pd.DataFrame, dict[str, str]]:
    """Prophet's final_test h=1 metrics per SKU, and the final_test window."""
    summary_path = evaluation_dir / "final_test" / "summary.csv"
    if not summary_path.is_file():
        raise FileNotFoundError(f"Run the final_test evaluation first; missing {summary_path}.")

    summary = pd.read_csv(summary_path)
    rows = summary[
        (summary["temperature_mode"] == "climatology")
        & (summary["model"] == REPORTED_MODEL)
        & (summary["horizon"] == REPORTED_HORIZON)
        & (summary["sku"] != "Mean of SKUs")
    ].set_index("sku")
    targets = pd.read_csv(evaluation_dir / "final_test" / "predictions.csv.gz", usecols=["target_date"])
    window = {"start": str(targets["target_date"].min()), "end": str(targets["target_date"].max())}
    return rows, window


def fit_sku(
    sku_data: pd.DataFrame,
    estimate: pd.Series,
    final_test_window: dict[str, str],
    xgboost_trials: int,
    epochs: int,
    verbose: int,
    seed: int,
    model_version: str,
    library_versions: dict[str, str],
) -> FittedSku:
    """Fit all three models on every row with the configuration chosen by the evaluation."""
    from training.tuning import select_config

    sku = str(sku_data["product_sku"].iloc[0])
    climatology = TemperatureClimatology.fit(sku_data)
    prepared = prepare_context(sku_data, "climatology", climatology)

    prophet_model, prophet_regressors = fit_prophet(prepared, **DEFAULT_PROPHET_CONFIG)
    xgboost_config, trials = select_config(
        "xgboost", sku_data, "climatology", xgboost_trials, TUNING_ORIGINS, TUNING_HORIZON
    )
    xgboost_model = fit_xgboost(prepared, seed, params=xgboost_config)
    lstm_config = {**DEFAULT_LSTM_CONFIG, "epochs": epochs}
    lstm_model, lstm_scaler = fit_lstm(prepared, seed, verbose=verbose, **lstm_config)
    stacker = LinearStacker([STACKER_WEIGHTS[model] for model in ("prophet", "xgboost", "lstm")])

    rmse_score = round(float(estimate["RMSE_mean"]), 4)
    if not np.isfinite(rmse_score) or not 0 <= rmse_score <= 9999.9999:
        raise ValueError(f"SKU {sku} final_test RMSE {rmse_score} is outside the manifest limits.")

    metadata = {
        "model_version": model_version,
        "rmse_score": rmse_score,
        "rmse_score_source": (
            f"{REPORTED_MODEL} h=1 RMSE on final_test, mean over {int(estimate['folds'])} "
            "rolling-origin folds, models fitted before final_test"
        ),
        "final_test_estimate": {
            "model": REPORTED_MODEL,
            "horizon_days": 1,
            "folds": int(estimate["folds"]),
            "window_start": final_test_window["start"],
            "window_end": final_test_window["end"],
            **{
                f"{metric}_{stat}": round(float(estimate[f"{metric}_{stat}"]), 4)
                for metric in ("RMSE", "MAPE", "sMAPE")
                for stat in ("mean", "fold_sd")
            },
        },
        "ensemble_mode": ENSEMBLE_MODE,
        "stacker_weights": dict(STACKER_WEIGHTS),
        "stacker_intercept": 0.0,
        "evaluation_protocol": {**EVALUATION_PROTOCOL, "folds": int(estimate["folds"])},
        "temperature_source": "monthly_mean",
        "temperature_climatology": climatology.to_dict(),
        "prophet_regressors": prophet_regressors,
        "prophet_config": dict(DEFAULT_PROPHET_CONFIG),
        "xgboost_feature_cols": list(XGBOOST_FEATURE_COLUMNS),
        "xgboost_config": xgboost_config,
        "lstm_config": lstm_config,
        "lstm_lookback": lstm_config["lookback"],
        "training_rows": len(sku_data),
        "training_start": sku_data["date"].min().date().isoformat(),
        "training_end": sku_data["date"].max().date().isoformat(),
        "seed": seed,
        "library_versions": library_versions,
    }
    return FittedSku(
        prophet=prophet_model,
        xgboost=xgboost_model,
        lstm=lstm_model,
        lstm_scaler=lstm_scaler,
        meta_stacker=stacker,
        metadata=metadata,
        xgboost_trials=[{"sku": sku, **row} for row in trials],
    )


def save_sku_bundle(output_dir: Path, sku: str, fitted: FittedSku) -> Path:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", sku):
        raise ValueError(f"SKU cannot safely be used as a model directory: {sku!r}")

    output_dir.mkdir(parents=True, exist_ok=True)
    target_dir = output_dir / sku

    with tempfile.TemporaryDirectory(prefix=f".{sku}-", dir=output_dir) as temp_path:
        staging_dir = Path(temp_path) / sku
        staging_dir.mkdir()
        joblib.dump(fitted.prophet, staging_dir / "prophet.joblib")
        joblib.dump(fitted.xgboost, staging_dir / "xgboost.joblib")
        fitted.lstm.save(staging_dir / "lstm.keras")
        joblib.dump(fitted.lstm_scaler, staging_dir / "lstm_scaler.joblib")
        joblib.dump(fitted.meta_stacker, staging_dir / "meta_stacker.joblib")
        joblib.dump(fitted.meta_stacker, staging_dir / "meta_learner.joblib")

        metadata = json.dumps(fitted.metadata, indent=2, sort_keys=True) + "\n"
        (staging_dir / "metadata.json").write_text(metadata, encoding="utf-8")
        (staging_dir / "manifest.json").write_text(metadata, encoding="utf-8")
        verify_artifact_bundle(staging_dir)

        if target_dir.exists():
            shutil.rmtree(target_dir)
        staging_dir.rename(target_dir)

    return target_dir


def verify_artifact_bundle(directory: Path) -> dict[str, Any]:
    from serving.inference import validate_manifest

    missing = [
        filename for filename in ARTIFACT_FILENAMES if not (directory / filename).is_file()
    ]
    if missing:
        raise RuntimeError(f"Incomplete artifact bundle in {directory}: {missing}")

    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    try:
        validate_manifest(manifest)
    except ValueError as exception:
        raise RuntimeError(f"Model bundle has invalid metadata: {directory}") from exception

    metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))
    if metadata != manifest:
        raise RuntimeError(f"metadata.json and manifest.json differ: {directory}")

    return manifest


def check_skip_equivalence(output_dir: Path, sku: str, sku_data: pd.DataFrame) -> float:
    """Score the last day both ways; the Prophet-only path must match all three models exactly."""
    from serving.inference import load_bundle, predict_sku

    context = sku_data.tail(15).copy()
    context.loc[context.index[-1], "units_sold"] = np.nan
    lean = predict_sku(sku, 1, context, bundle=load_bundle(output_dir / sku))
    full = predict_sku(
        sku, 1, context, bundle=load_bundle(output_dir / sku, skip_unused_models=False)
    )
    if not np.array_equal(lean["predicted_units_sold"], full["predicted_units_sold"]):
        raise RuntimeError(f"SKU {sku}: skipping zero-weight models changed the prediction.")

    return float(lean["predicted_units_sold"].iloc[0])


def run_training(
    data_path: Path,
    output_dir: Path,
    evaluation_dir: Path,
    xgboost_trials: int = 12,
    epochs: int = DEFAULT_LSTM_CONFIG["epochs"],
    verbose: int = 0,
    seed: int = SEED,
) -> pd.DataFrame:
    """Refit every SKU on all rows, save its bundle and the metrics files; return a summary."""
    import tensorflow as tf

    from training.metrics_file import write_metrics
    from training.rolling_origin import library_versions

    if xgboost_trials < 1 or epochs < 1:
        raise ValueError("xgboost_trials and epochs must be positive.")
    tf.config.experimental.enable_op_determinism()

    versions = library_versions()
    LOGGER.info("Library versions: %s", json.dumps(versions))
    model_version = (
        datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    )
    data = load_daily_data(data_path)
    if data.empty:
        raise ValueError("Training data contains no SKU rows.")
    estimates, window = final_test_estimates(evaluation_dir)
    missing = sorted(set(data["product_sku"]) - set(estimates.index))
    if missing:
        raise ValueError(f"No final_test estimate for SKU(s) {missing}; re-run the evaluation.")

    output_dir.mkdir(parents=True, exist_ok=True)
    rows, trial_log = [], []
    for sku, sku_data in data.groupby("product_sku", sort=True):
        LOGGER.info("Refitting SKU %s on %s daily rows", sku, len(sku_data))
        sku_data = sku_data.reset_index(drop=True)
        fitted = fit_sku(
            sku_data,
            estimates.loc[sku],
            window,
            xgboost_trials,
            epochs,
            verbose,
            seed,
            model_version,
            versions,
        )
        saved_dir = save_sku_bundle(output_dir, sku, fitted)
        manifest = verify_artifact_bundle(saved_dir)
        if manifest["model_version"] != model_version:
            raise RuntimeError(f"SKU {sku} metadata has an unexpected training timestamp.")
        check_prediction = check_skip_equivalence(output_dir, sku, sku_data)
        trial_log.extend(fitted.xgboost_trials)
        rows.append(
            {
                "sku": sku,
                "rmse_score": manifest["rmse_score"],
                "ensemble_mode": manifest["ensemble_mode"],
                "xgboost_config": json.dumps(manifest["xgboost_config"], sort_keys=True),
                "last_day_check_prediction": round(check_prediction, 2),
            }
        )
        LOGGER.info("Saved and verified model bundle: %s", saved_dir)

    pd.DataFrame(trial_log).to_csv(output_dir / "final_xgboost_tuning.csv", index=False)
    write_metrics(evaluation_dir, output_dir, model_version)
    table = pd.DataFrame(rows).set_index("sku")
    print(f"\nmodel_version {model_version}; bundles and metrics files under {output_dir}")
    print(table.to_string())
    return table


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    args = parse_args()

    try:
        run_training(
            data_path=args.data,
            output_dir=args.output,
            evaluation_dir=args.evaluation,
            xgboost_trials=args.xgboost_trials,
            epochs=args.epochs,
            verbose=args.verbose,
            seed=args.seed,
        )
    except Exception:
        LOGGER.exception("Training did not complete successfully.")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
