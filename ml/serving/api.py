from __future__ import annotations

import hmac
import json
import logging
import os
import re
import sys
from contextlib import asynccontextmanager
from dataclasses import dataclass
from datetime import date, timedelta
from importlib import metadata
from pathlib import Path
from typing import Annotated, Any

import joblib
import numpy as np
import pandas as pd
from fastapi import Depends, FastAPI, HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from serving.inference import ForecastBundle, is_weighted_bundle, load_bundle, predict_sku

logger = logging.getLogger(__name__)

FEATURE_COLUMNS = (
    "is_promotion",
    "is_public_holiday",
    "is_school_term",
    "temperature_c",
)
LSTM_FEATURE_COLUMNS = ("units_sold", *FEATURE_COLUMNS)
DEFAULT_XGBOOST_FEATURE_COLUMNS = (
    *FEATURE_COLUMNS,
    "lag_1",
    "lag_7",
    "lag_14",
    "rolling_mean_7",
    "rolling_std_7",
    "rolling_mean_14",
    "rolling_std_14",
    "day_of_week",
)
DEFAULT_LOOKBACK = 14
MAX_HISTORY_DAYS = 400
DEFAULT_MODEL_DIR = Path(__file__).resolve().parents[1] / "outputs" / "saved_models"


class FeaturePoint(BaseModel):
    model_config = ConfigDict(extra="forbid")

    date: date
    units_sold: Annotated[float, Field(ge=0, allow_inf_nan=False)]
    is_promotion: bool
    is_public_holiday: bool
    is_school_term: bool
    temperature_c: Annotated[float, Field(ge=-60, le=60, allow_inf_nan=False)]


class PredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    sku: Annotated[str, Field(min_length=1, max_length=64)]
    date: date
    history: Annotated[list[FeaturePoint], Field(min_length=DEFAULT_LOOKBACK, max_length=MAX_HISTORY_DAYS)]
    is_promotion: bool
    is_public_holiday: bool
    is_school_term: bool
    temperature_c: Annotated[float, Field(ge=-60, le=60, allow_inf_nan=False)]

    @field_validator("sku")
    @classmethod
    def validate_sku(cls, value: str) -> str:
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", value):
            raise ValueError("sku contains unsupported characters")

        return value

    @model_validator(mode="after")
    def validate_history_dates(self) -> PredictionRequest:
        if self.history != sorted(self.history, key=lambda row: row.date):
            raise ValueError("history must be ordered by date")

        recent_history = self.history[-DEFAULT_LOOKBACK:]
        expected_last_date = self.date - timedelta(days=1)

        if recent_history[-1].date != expected_last_date:
            raise ValueError("history must end on the day before the prediction date")

        for previous, current in zip(recent_history, recent_history[1:]):
            if current.date - previous.date != timedelta(days=1):
                raise ValueError("the most recent 14 history dates must be consecutive")

        return self


@dataclass(frozen=True)
class ModelBundle:
    prophet: Any
    xgboost: Any
    lstm: Any
    lstm_scaler: Any
    meta_learner: Any
    manifest: dict[str, Any]


class ModelRegistry:
    def __init__(self, model_dir: Path) -> None:
        self.model_dir = model_dir
        self.models: dict[str, ModelBundle | ForecastBundle] = {}
        self.failed: list[str] = []

    def load_available(self) -> None:
        if not self.model_dir.exists():
            logger.warning("ML model directory does not exist: %s", self.model_dir)
            return

        for sku_dir in sorted(path for path in self.model_dir.iterdir() if path.is_dir()):
            sku = sku_dir.name
            if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", sku):
                logger.warning("Ignoring unsafe SKU model directory: %s", sku_dir)
                continue

            # Any failure (including AttributeError or UnpicklingError from a library-version
            # mismatch) drops this SKU only; the rest of the registry still loads.
            try:
                bundle = self._load_bundle(sku_dir)
            except Exception as exception:
                self.failed.append(sku)
                logger.exception("Unable to load model artifacts for SKU %s: %s", sku, exception)
                continue

            self.models[sku] = bundle
            _warn_on_library_version_drift(sku, bundle.manifest)

    def _load_bundle(self, sku_dir: Path) -> ModelBundle | ForecastBundle:
        manifest_path = sku_dir / "manifest.json"
        if manifest_path.is_file() and is_weighted_bundle(
            json.loads(manifest_path.read_text(encoding="utf-8"))
        ):
            return load_bundle(sku_dir)

        required = (
            "prophet.joblib",
            "xgboost.joblib",
            "lstm.keras",
            "lstm_scaler.joblib",
            "meta_learner.joblib",
        )
        missing = [filename for filename in required if not (sku_dir / filename).is_file()]
        if missing:
            raise FileNotFoundError(
                f"Incomplete model artifacts in {sku_dir}: {', '.join(missing)}"
            )

        from tensorflow.keras.models import load_model

        manifest_path = sku_dir / "manifest.json"
        manifest = (
            json.loads(manifest_path.read_text(encoding="utf-8"))
            if manifest_path.is_file()
            else {}
        )
        model_version = manifest.get("model_version")
        rmse_score = manifest.get("rmse_score")

        if (
            not isinstance(model_version, str)
            or not model_version.strip()
            or len(model_version) > 255
        ):
            raise ValueError("manifest.json must define a model_version of 1 to 255 characters")
        if (
            isinstance(rmse_score, bool)
            or not isinstance(rmse_score, (int, float))
            or not np.isfinite(rmse_score)
            or not 0 <= rmse_score <= 9999.9999
        ):
            raise ValueError("manifest.json rmse_score must be between 0 and 9999.9999")

        return ModelBundle(
            prophet=joblib.load(sku_dir / "prophet.joblib"),
            xgboost=joblib.load(sku_dir / "xgboost.joblib"),
            lstm=load_model(sku_dir / "lstm.keras", compile=False),
            lstm_scaler=joblib.load(sku_dir / "lstm_scaler.joblib"),
            meta_learner=joblib.load(sku_dir / "meta_learner.joblib"),
            manifest=manifest,
        )


def _major_minor(version: str) -> str:
    return ".".join(version.split(".")[:2])


def _warn_on_library_version_drift(sku: str, manifest: dict[str, Any]) -> None:
    """Warn when a bundle was trained with a different major.minor of a library than is running."""
    recorded = manifest.get("library_versions")
    if not isinstance(recorded, dict):
        return

    for library, trained_version in sorted(recorded.items()):
        if not isinstance(library, str) or not isinstance(trained_version, str):
            continue

        if library == "python":
            running_version = ".".join(str(part) for part in sys.version_info[:3])
        else:
            try:
                running_version = metadata.version(library)
            except metadata.PackageNotFoundError:
                logger.warning(
                    "SKU %s was trained with %s %s, which is not installed", sku, library, trained_version
                )
                continue

        if _major_minor(running_version) != _major_minor(trained_version):
            logger.warning(
                "SKU %s was trained with %s %s but %s is running",
                sku,
                library,
                trained_version,
                running_version,
            )


def _estimator(value: Any) -> Any:
    return getattr(value, "model", value)


def _prophet_prediction(bundle: ModelBundle, request: PredictionRequest) -> float:
    wrapper = bundle.prophet
    model = _estimator(wrapper)
    regressors = bundle.manifest.get("prophet_regressors")

    if regressors is None:
        regressors = getattr(wrapper, "active_regressors", None)
    if regressors is None:
        extra_regressors = getattr(model, "extra_regressors", None)
        if extra_regressors is not None:
            regressors = list(extra_regressors.keys())
    if regressors is None:
        raise ValueError("Prophet artifact must expose its fitted regressors")

    row: dict[str, Any] = {"ds": pd.Timestamp(request.date)}
    row.update({name: getattr(request, name) for name in regressors})
    prediction = model.predict(pd.DataFrame([row]))
    return float(prediction["yhat"].iloc[0])


def _xgboost_prediction(bundle: ModelBundle, request: PredictionRequest) -> float:
    wrapper = bundle.xgboost
    model = _estimator(wrapper)
    feature_columns = bundle.manifest.get("xgboost_feature_cols")

    if feature_columns is None:
        feature_columns = getattr(wrapper, "feature_cols", None)
    if feature_columns is None:
        feature_columns = getattr(model, "feature_names_in_", None)
    if feature_columns is None and hasattr(model, "get_booster"):
        feature_columns = model.get_booster().feature_names
    if feature_columns is None:
        feature_columns = DEFAULT_XGBOOST_FEATURE_COLUMNS

    units = np.asarray([row.units_sold for row in request.history], dtype=float)
    values: dict[str, float] = {
        name: float(getattr(request, name)) for name in FEATURE_COLUMNS
    }
    values.update(
        {
            "lag_1": float(units[-1]),
            "lag_7": float(units[-7]),
            "lag_14": float(units[-14]),
            "rolling_mean_7": float(np.mean(units[-7:])),
            "rolling_std_7": float(np.std(units[-7:], ddof=1)),
            "rolling_mean_14": float(np.mean(units[-14:])),
            "rolling_std_14": float(np.std(units[-14:], ddof=1)),
            "day_of_week": float(request.date.weekday()),
        }
    )

    unknown_columns = [column for column in feature_columns if column not in values]
    if unknown_columns:
        raise ValueError(f"Unsupported XGBoost features: {unknown_columns}")

    frame = pd.DataFrame([{column: values[column] for column in feature_columns}])
    return max(0.0, float(np.asarray(model.predict(frame)).reshape(-1)[0]))


def _lstm_prediction(bundle: ModelBundle, request: PredictionRequest) -> float:
    lookback = int(bundle.manifest.get("lstm_lookback", DEFAULT_LOOKBACK))
    if lookback < DEFAULT_LOOKBACK or len(request.history) < lookback:
        raise ValueError(f"LSTM lookback must be at least {DEFAULT_LOOKBACK} days")

    rows = request.history[-lookback:]
    frame = pd.DataFrame(
        [
            {
                "units_sold": row.units_sold,
                **{name: getattr(row, name) for name in FEATURE_COLUMNS},
            }
            for row in rows
        ],
        columns=LSTM_FEATURE_COLUMNS,
    )
    scaled = bundle.lstm_scaler.transform(frame)
    prediction = np.asarray(
        bundle.lstm.predict(np.asarray([scaled], dtype=np.float32), verbose=0)
    ).reshape(-1)[0]

    inverse_row = np.zeros((1, len(LSTM_FEATURE_COLUMNS)), dtype=np.float32)
    inverse_row[0, 0] = prediction
    return max(0.0, float(bundle.lstm_scaler.inverse_transform(inverse_row)[0, 0]))


def request_context(request: PredictionRequest) -> pd.DataFrame:
    """The request as predict_sku() input: history rows, then the target day without sales."""
    rows = [row.model_dump() for row in request.history]
    rows.append(
        {
            "date": request.date,
            "units_sold": np.nan,
            **{name: getattr(request, name) for name in FEATURE_COLUMNS},
        }
    )
    return pd.DataFrame(rows)


def predict_units(bundle: ModelBundle, request: PredictionRequest) -> float:
    base_predictions = {
        "prophet_pred": _prophet_prediction(bundle, request),
        "xgboost_pred": _xgboost_prediction(bundle, request),
        "lstm_pred": _lstm_prediction(bundle, request),
    }
    meta_input = pd.DataFrame([base_predictions])
    meta_prediction = float(
        np.asarray(_estimator(bundle.meta_learner).predict(meta_input)).reshape(-1)[0]
    )

    if not np.isfinite(meta_prediction):
        raise ValueError("The stacked model returned a non-finite prediction")

    return max(0.0, meta_prediction)


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.api_token = os.environ.get("ML_API_TOKEN", "").strip()
    if not app.state.api_token:
        logger.warning(
            "ML_API_TOKEN is not set: the forecast service is running without authentication"
        )

    registry = ModelRegistry(Path(os.environ.get("ML_MODEL_DIR") or DEFAULT_MODEL_DIR))
    registry.load_available()
    app.state.models = registry.models
    app.state.failed_skus = registry.failed
    yield


def require_api_token(request: Request) -> None:
    """Require `Authorization: Bearer <ML_API_TOKEN>` on every endpoint when the token is configured."""
    expected: str = getattr(request.app.state, "api_token", "")
    if not expected:
        return

    scheme, _, supplied = request.headers.get("Authorization", "").partition(" ")
    if scheme.lower() != "bearer" or not hmac.compare_digest(
        supplied.strip().encode("utf-8"), expected.encode("utf-8")
    ):
        raise HTTPException(
            status_code=401,
            detail="Missing or invalid API token",
            headers={"WWW-Authenticate": "Bearer"},
        )


app = FastAPI(
    title="Water Bottling ML Forecast Service",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
    lifespan=lifespan,
    dependencies=[Depends(require_api_token)],
)


@app.get("/health")
def health(request: Request) -> dict[str, Any]:
    models: dict[str, ModelBundle | ForecastBundle] = request.app.state.models
    served_versions: dict[str, str] = {}

    for sku, bundle in sorted(models.items()):
        model_version = bundle.manifest.get("model_version")
        if isinstance(model_version, str) and model_version.strip() and len(model_version) <= 255:
            served_versions[sku] = model_version

    return {
        "status": "ok",
        "models": served_versions,
        "failed": sorted(getattr(request.app.state, "failed_skus", [])),
    }


@app.get("/climatology")
def climatology(request: Request) -> dict[str, Any]:
    """Monthly mean temperatures each served bundle scores with, read from its manifest.

    Laravel stores these in daily_sku_features.temperature_c so the value on record is the one the model
    uses. Bundles without a complete month 1-12 climatology are left out.
    """
    models: dict[str, ModelBundle | ForecastBundle] = request.app.state.models
    served: dict[str, dict[str, Any]] = {}

    for sku, bundle in sorted(models.items()):
        months = bundle.manifest.get("temperature_climatology")
        if not isinstance(months, dict):
            continue

        try:
            means = {str(month): float(months[str(month)]) for month in range(1, 13)}
        except (KeyError, TypeError, ValueError):
            continue

        if not all(np.isfinite(value) for value in means.values()):
            continue

        source = bundle.manifest.get("temperature_source")
        served[sku] = {
            "temperature_source": source if isinstance(source, str) and source else "monthly_mean",
            "months": means,
        }

    return {"models": served}


@app.post("/predict")
def predict(payload: PredictionRequest, request: Request) -> dict[str, str | float]:
    models: dict[str, ModelBundle | ForecastBundle] = request.app.state.models
    bundle = models.get(payload.sku)

    if bundle is None:
        raise HTTPException(status_code=503, detail="No trained model is available for this SKU")

    model_version = bundle.manifest.get("model_version")
    rmse_score = bundle.manifest.get("rmse_score")
    if (
        not isinstance(model_version, str)
        or not model_version.strip()
        or len(model_version) > 255
        or isinstance(rmse_score, bool)
        or not isinstance(rmse_score, (int, float))
        or not np.isfinite(rmse_score)
        or not 0 <= rmse_score <= 9999.9999
    ):
        raise HTTPException(status_code=503, detail="Model metadata is unavailable for this SKU")

    try:
        if isinstance(bundle, ForecastBundle):
            forecast = predict_sku(payload.sku, 1, request_context(payload), bundle=bundle)
            units_sold = float(forecast["predicted_units_sold"].iloc[0])
        else:
            units_sold = predict_units(bundle, payload)
    except (KeyError, TypeError, ValueError) as exception:
        logger.exception("Prediction failed for SKU %s", payload.sku)
        raise HTTPException(status_code=503, detail="The model could not score this request") from exception
    except Exception as exception:
        logger.exception("Prediction crashed for SKU %s", payload.sku)
        raise HTTPException(status_code=500, detail="The forecast service failed to score this request") from exception

    if not np.isfinite(units_sold):
        logger.error("Prediction for SKU %s was not finite", payload.sku)
        raise HTTPException(status_code=503, detail="The model could not score this request")

    return {
        "predicted_units_sold": units_sold,
        "model_version": model_version,
        "rmse_score": float(rmse_score),
    }
