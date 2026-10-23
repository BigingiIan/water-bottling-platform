"""Importable inference for the per-SKU forecast bundles.

`predict_sku(sku, horizon_days, context_df)` is the single entry point. It has
no web-framework code, so the FastAPI service and a future Streamlit dashboard
can both import it.

`context_df` holds one row per consecutive day:

- history rows: `date`, `units_sold` and the context columns, ending the day
  before the first forecast date (at least 14 rows);
- future rows: `date` and the context columns with `units_sold` missing, at
  least `horizon_days` of them.

Bundles trained with the monthly-mean temperature source ignore any supplied
`temperature_c` and use the climatology stored in their manifest, exactly as in
training. Models whose stacker weight is exactly zero are neither loaded nor
run; their stacker input is zero, so the output is identical to running them.
"""

from __future__ import annotations

import json
import math
import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd

from training.forecasting import (
    CONTEXT_COLUMNS,
    MAX_LAG,
    STACK_FEATURE_COLUMNS,
    TemperatureClimatology,
    build_forecast_batch,
    forecast_lstm,
    forecast_xgboost,
    predict_prophet,
    prepare_context,
)

ENSEMBLE_MODELS = ("prophet", "xgboost", "lstm")
MODEL_ARTIFACTS = {
    "prophet": ("prophet.joblib",),
    "xgboost": ("xgboost.joblib",),
    "lstm": ("lstm.keras", "lstm_scaler.joblib"),
}
CALENDAR_COLUMNS = CONTEXT_COLUMNS[:3]
MONTHLY_MEAN_TEMPERATURE = "monthly_mean"


def default_model_dir() -> Path:
    return Path(
        os.environ.get("ML_MODEL_DIR", str(Path(__file__).resolve().parents[1] / "saved_models"))
    )


def validate_manifest(manifest: dict[str, Any]) -> None:
    """The fields Laravel relies on: model_version and rmse_score."""
    model_version = manifest.get("model_version")
    if not isinstance(model_version, str) or not model_version.strip() or len(model_version) > 255:
        raise ValueError("manifest.json must define a model_version of 1 to 255 characters")

    rmse_score = manifest.get("rmse_score")
    if (
        isinstance(rmse_score, bool)
        or not isinstance(rmse_score, (int, float))
        or not math.isfinite(rmse_score)
        or not 0 <= rmse_score <= 9999.9999
    ):
        raise ValueError("manifest.json rmse_score must be between 0 and 9999.9999")


def is_weighted_bundle(manifest: dict[str, Any]) -> bool:
    """True for bundles whose manifest records the stacker weights."""
    return isinstance(manifest.get("stacker_weights"), dict)


@dataclass(frozen=True)
class ForecastBundle:
    sku: str
    manifest: dict[str, Any]
    stacker: Any
    stacker_weights: dict[str, float]
    climatology: TemperatureClimatology | None
    prophet: Any | None = None
    xgboost: Any | None = None
    lstm: Any | None = None
    lstm_scaler: Any | None = None

    @property
    def model_version(self) -> str:
        return self.manifest["model_version"]

    @property
    def rmse_score(self) -> float:
        return float(self.manifest["rmse_score"])

    @property
    def lstm_lookback(self) -> int:
        return int(self.manifest.get("lstm_lookback", MAX_LAG))

    @property
    def history_length(self) -> int:
        return max(MAX_LAG, self.lstm_lookback)

    def is_loaded(self, model: str) -> bool:
        return getattr(self, model) is not None


def load_bundle(directory: Path, skip_unused_models: bool = True) -> ForecastBundle:
    """Load one SKU bundle; with skip_unused_models, zero-weight models are not loaded."""
    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    validate_manifest(manifest)
    if not is_weighted_bundle(manifest):
        raise ValueError(f"{directory} has no stacker_weights; it is not a predict_sku bundle.")

    weights = {model: float(manifest["stacker_weights"][model]) for model in ENSEMBLE_MODELS}
    stacker = joblib.load(directory / "meta_learner.joblib")
    coefficients = np.asarray(getattr(stacker, "coef_", []), dtype=float)
    intercept = float(getattr(stacker, "intercept_", 0.0))
    expected = np.array([weights[model] for model in ENSEMBLE_MODELS])
    expected_intercept = float(manifest.get("stacker_intercept", 0.0))
    if coefficients.shape != expected.shape or not np.array_equal(coefficients, expected):
        raise ValueError(f"{directory}: meta_learner weights do not match manifest stacker_weights.")
    if intercept != expected_intercept:
        raise ValueError(f"{directory}: meta_learner intercept does not match the manifest.")

    climatology = None
    if manifest.get("temperature_source") == MONTHLY_MEAN_TEMPERATURE:
        climatology = TemperatureClimatology.from_dict(manifest["temperature_climatology"])

    needed = {model for model in ENSEMBLE_MODELS if weights[model] != 0.0 or not skip_unused_models}
    models: dict[str, Any] = {}
    for model in needed:
        missing = [name for name in MODEL_ARTIFACTS[model] if not (directory / name).is_file()]
        if missing:
            raise FileNotFoundError(f"Incomplete model artifacts in {directory}: {missing}")
    if "prophet" in needed:
        models["prophet"] = joblib.load(directory / "prophet.joblib")
    if "xgboost" in needed:
        models["xgboost"] = joblib.load(directory / "xgboost.joblib")
    if "lstm" in needed:
        from tensorflow.keras.models import load_model

        models["lstm"] = load_model(directory / "lstm.keras", compile=False)
        models["lstm_scaler"] = joblib.load(directory / "lstm_scaler.joblib")

    return ForecastBundle(
        sku=directory.name,
        manifest=manifest,
        stacker=stacker,
        stacker_weights=weights,
        climatology=climatology,
        **models,
    )


@lru_cache(maxsize=32)
def _cached_bundle(directory: str, skip_unused_models: bool) -> ForecastBundle:
    return load_bundle(Path(directory), skip_unused_models)


def _prepare_context(
    bundle: ForecastBundle,
    horizon_days: int,
    context_df: pd.DataFrame,
) -> tuple[pd.DataFrame, int]:
    """Validate the input rows; return the prepared frame and the origin position."""
    required = ["date", "units_sold", *CALENDAR_COLUMNS]
    if bundle.climatology is None:
        required.append("temperature_c")
    missing = [column for column in required if column not in context_df.columns]
    if missing:
        raise ValueError(f"context_df is missing columns: {missing}")

    frame = context_df.copy()
    frame["date"] = pd.to_datetime(frame["date"])
    frame = frame.sort_values("date").reset_index(drop=True)
    known = frame["units_sold"].notna().to_numpy()
    history_rows = int(known.sum())
    if not known[:history_rows].all():
        raise ValueError("Rows with units_sold must all come before the rows to forecast.")
    if history_rows < bundle.history_length:
        raise ValueError(f"context_df needs at least {bundle.history_length} history rows.")
    if len(frame) - history_rows < horizon_days:
        raise ValueError(f"context_df needs {horizon_days} future rows after the history.")

    frame = frame.iloc[history_rows - bundle.history_length : history_rows + horizon_days]
    frame = frame.reset_index(drop=True)
    if not (frame["date"].diff().dropna() == pd.Timedelta(days=1)).all():
        raise ValueError("context_df dates must be consecutive days.")
    if (frame["units_sold"].iloc[: bundle.history_length] < 0).any():
        raise ValueError("units_sold must be non-negative.")

    if bundle.climatology is not None:
        frame["temperature_c"] = np.nan
        prepared = prepare_context(frame, "climatology", bundle.climatology)
    else:
        prepared = prepare_context(frame, "actual", None)
    if prepared[list(CONTEXT_COLUMNS)].isna().any().any():
        raise ValueError("context_df has missing context values.")

    return prepared, bundle.history_length - 1


def predict_sku(
    sku: str,
    horizon_days: int,
    context_df: pd.DataFrame,
    model_dir: Path | None = None,
    bundle: ForecastBundle | None = None,
) -> pd.DataFrame:
    """Forecast `horizon_days` days after the last history row of `context_df`.

    Pass `bundle` to reuse an already-loaded bundle; otherwise it is loaded
    (and cached) from `model_dir / sku`, defaulting to ML_MODEL_DIR.
    Returns one row per forecast day with the stacked prediction, each base
    prediction (NaN for models that were not run) and the bundle metadata.
    """
    if horizon_days < 1:
        raise ValueError("horizon_days must be at least 1.")
    if bundle is None:
        bundle = _cached_bundle(str((model_dir or default_model_dir()) / sku), True)
    if bundle.sku != sku:
        raise ValueError(f"Bundle is for SKU {bundle.sku}, not {sku}.")

    prepared, origin = _prepare_context(bundle, horizon_days, context_df)
    future = prepared.iloc[origin + 1 :]
    base: dict[str, np.ndarray] = {}

    if bundle.is_loaded("prophet"):
        regressors = list(bundle.manifest["prophet_regressors"])
        base["prophet"] = predict_prophet(bundle.prophet, regressors, future)
    if bundle.is_loaded("xgboost") or bundle.is_loaded("lstm"):
        batch = build_forecast_batch(prepared, [origin], horizon_days, bundle.history_length)
        if bundle.is_loaded("xgboost"):
            base["xgboost"] = forecast_xgboost(bundle.xgboost, batch)[0]
        if bundle.is_loaded("lstm"):
            base["lstm"] = forecast_lstm(bundle.lstm, bundle.lstm_scaler, batch, bundle.lstm_lookback)[0]

    for model, weight in bundle.stacker_weights.items():
        if model not in base and weight != 0.0:
            raise ValueError(f"The {model} model has weight {weight} but is not loaded.")

    features = pd.DataFrame(
        {
            column: base.get(model, np.zeros(horizon_days))
            for model, column in zip(ENSEMBLE_MODELS, STACK_FEATURE_COLUMNS)
        }
    )
    stacked = np.asarray(bundle.stacker.predict(features), dtype=float).reshape(-1)
    if not np.isfinite(stacked).all():
        raise ValueError("The stacked model returned a non-finite prediction.")

    return pd.DataFrame(
        {
            "date": future["date"].to_numpy(),
            "horizon": np.arange(1, horizon_days + 1),
            "predicted_units_sold": stacked,
            **{
                column: base.get(model, np.full(horizon_days, np.nan))
                for model, column in zip(ENSEMBLE_MODELS, STACK_FEATURE_COLUMNS)
            },
            "model_version": bundle.model_version,
            "rmse_score": bundle.rmse_score,
            "ensemble_mode": bundle.manifest.get("ensemble_mode", "stacked"),
        }
    )
