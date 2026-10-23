"""Forecasting components shared by training, evaluation and inference.

Every model here forecasts a batch of origins at once. For horizons beyond one
day, XGBoost and the LSTM forecast recursively: each predicted day is fed back
as the lag/sequence input for the next day. Recursive forecasting keeps a single
model per SKU, which is what the serving bundle layout expects.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any, Literal

import numpy as np
import pandas as pd
import xgboost as xgb
from prophet import Prophet
from sklearn.preprocessing import MinMaxScaler

CONTEXT_COLUMNS = (
    "is_promotion",
    "is_public_holiday",
    "is_school_term",
    "temperature_c",
)
LSTM_FEATURE_COLUMNS = ("units_sold", *CONTEXT_COLUMNS)
XGBOOST_FEATURE_COLUMNS = (
    *CONTEXT_COLUMNS,
    "lag_1",
    "lag_7",
    "lag_14",
    "rolling_mean_7",
    "rolling_std_7",
    "rolling_mean_14",
    "rolling_std_14",
    "day_of_week",
)
STACK_FEATURE_COLUMNS = ("prophet_pred", "xgboost_pred", "lstm_pred")
MAX_LAG = 14
DEFAULT_LOOKBACK = 14
DEFAULT_XGBOOST_PARAMS: dict[str, Any] = {
    "n_estimators": 100,
    "max_depth": 6,
    "learning_rate": 0.1,
    "subsample": 0.8,
    "colsample_bytree": 0.8,
    "n_jobs": -1,
    "verbosity": 0,
}

TemperatureMode = Literal["climatology", "actual"]
TEMPERATURE_MODES: tuple[TemperatureMode, ...] = ("climatology", "actual")

logging.getLogger("cmdstanpy").setLevel(logging.WARNING)
logging.getLogger("prophet").setLevel(logging.WARNING)


@dataclass(frozen=True)
class TemperatureClimatology:
    """Mean temperature per calendar month, learned from training rows only.

    Tomorrow's actual temperature is not known when a forecast is made, so the
    realistic setting replaces temperature_c with this monthly mean for every
    row the models see, in training and at inference alike.
    """

    monthly_means: dict[int, float]

    @classmethod
    def fit(cls, frame: pd.DataFrame) -> TemperatureClimatology:
        dates = pd.to_datetime(frame["date"])
        means = frame.groupby(dates.dt.month)["temperature_c"].mean()
        overall = float(frame["temperature_c"].mean())
        return cls({month: float(means.get(month, overall)) for month in range(1, 13)})

    @classmethod
    def from_dict(cls, values: dict[str, float]) -> TemperatureClimatology:
        return cls({int(month): float(value) for month, value in values.items()})

    def to_dict(self) -> dict[str, float]:
        return {str(month): value for month, value in sorted(self.monthly_means.items())}

    def apply(self, frame: pd.DataFrame) -> pd.DataFrame:
        result = frame.copy()
        months = pd.to_datetime(result["date"]).dt.month
        result["temperature_c"] = months.map(self.monthly_means).astype(float)
        return result


def prepare_context(
    frame: pd.DataFrame,
    temperature_mode: TemperatureMode,
    climatology: TemperatureClimatology | None,
) -> pd.DataFrame:
    """Return a copy with float context columns and the chosen temperature source."""
    result = frame.copy()
    for column in CONTEXT_COLUMNS:
        result[column] = result[column].astype(float)

    if temperature_mode == "climatology":
        if climatology is None:
            raise ValueError("The climatology temperature mode needs a fitted climatology.")
        result = climatology.apply(result)
    elif temperature_mode != "actual":
        raise ValueError(f"Unknown temperature mode: {temperature_mode}")

    return result


@dataclass(frozen=True)
class ForecastBatch:
    """Inputs for forecasting `horizon` days after each of several origins.

    Only data up to and including each origin date is used for units sold.
    Future rows contribute their context features, which are known in advance.
    """

    origin_dates: np.ndarray
    target_dates: np.ndarray
    history_units: np.ndarray
    history_context: np.ndarray
    future_context: np.ndarray
    future_day_of_week: np.ndarray

    @property
    def size(self) -> int:
        return len(self.origin_dates)

    @property
    def horizon(self) -> int:
        return self.target_dates.shape[1]


def build_forecast_batch(
    frame: pd.DataFrame,
    origin_positions: list[int] | np.ndarray,
    horizon: int,
    history_length: int,
) -> ForecastBatch:
    """Slice a contiguous daily frame into a batch of forecast origins.

    `frame` must already have its context prepared. Units sold after an origin
    are never read, so they may be missing.
    """
    positions = np.asarray(origin_positions, dtype=int)
    if positions.min() < history_length - 1:
        raise ValueError(f"Each origin needs {history_length} days of history.")
    if positions.max() + horizon >= len(frame):
        raise ValueError("The frame has no context rows for the full forecast horizon.")

    dates = pd.to_datetime(frame["date"]).to_numpy()
    units = frame["units_sold"].to_numpy(dtype=float)
    context = frame[list(CONTEXT_COLUMNS)].to_numpy(dtype=float)
    history_index = positions[:, None] + np.arange(-history_length + 1, 1)[None, :]
    future_index = positions[:, None] + np.arange(1, horizon + 1)[None, :]

    history_units = units[history_index]
    if np.isnan(history_units).any():
        raise ValueError("Forecast history contains missing units_sold values.")

    target_dates = dates[future_index]
    return ForecastBatch(
        origin_dates=dates[positions],
        target_dates=target_dates,
        history_units=history_units,
        history_context=context[history_index],
        future_context=context[future_index],
        future_day_of_week=pd.DatetimeIndex(target_dates.reshape(-1))
        .dayofweek.to_numpy()
        .reshape(target_dates.shape),
    )


DEFAULT_PROPHET_CONFIG: dict[str, Any] = {
    "changepoint_prior_scale": 0.05,
    "seasonality_mode": "additive",
    "regressor_prior_scale": 10.0,
    "yearly_seasonality": "auto",
}
DEFAULT_LSTM_CONFIG: dict[str, Any] = {
    "units": [64, 32],
    "dropout": 0.2,
    "lookback": DEFAULT_LOOKBACK,
    "epochs": 15,
}


def fit_prophet(
    train: pd.DataFrame,
    changepoint_prior_scale: float = 0.05,
    seasonality_mode: str = "additive",
    regressor_prior_scale: float = 10.0,
    yearly_seasonality: bool | Literal["auto"] = "auto",
) -> tuple[Prophet, list[str]]:
    """Fit Prophet; "auto" turns yearly seasonality on only with 730+ training days.

    The defaults reproduce the original pipeline: Prophet's own defaults for
    the changepoint prior and seasonality mode, and a regressor prior of 10.
    """
    regressors = [
        column for column in CONTEXT_COLUMNS if train[column].nunique(dropna=False) > 1
    ]
    model = Prophet(
        yearly_seasonality=len(train) >= 730 if yearly_seasonality == "auto" else yearly_seasonality,
        weekly_seasonality=True,
        daily_seasonality=False,
        changepoint_prior_scale=changepoint_prior_scale,
        seasonality_mode=seasonality_mode,
    )
    for column in regressors:
        model.add_regressor(column, prior_scale=regressor_prior_scale)

    fit_frame = train.rename(columns={"date": "ds", "units_sold": "y"})
    model.fit(fit_frame[["ds", "y", *regressors]])
    return model, regressors


def predict_prophet(model: Prophet, regressors: list[str], frame: pd.DataFrame) -> np.ndarray:
    future = frame.rename(columns={"date": "ds"})[["ds", *regressors]]
    return np.clip(model.predict(future)["yhat"].to_numpy(), 0, None)


def engineer_xgboost_features(frame: pd.DataFrame) -> pd.DataFrame:
    result = frame.copy()
    for lag in (1, 7, 14):
        result[f"lag_{lag}"] = result["units_sold"].shift(lag)

    shifted_sales = result["units_sold"].shift(1)
    for window in (7, 14):
        result[f"rolling_mean_{window}"] = shifted_sales.rolling(window=window).mean()
        result[f"rolling_std_{window}"] = shifted_sales.rolling(window=window).std()

    result["day_of_week"] = pd.to_datetime(result["date"]).dt.dayofweek.astype(float)
    return result


def fit_xgboost(
    train: pd.DataFrame,
    seed: int,
    params: dict[str, Any] | None = None,
) -> xgb.XGBRegressor:
    features = engineer_xgboost_features(train)
    clean = features.dropna(subset=[*XGBOOST_FEATURE_COLUMNS, "units_sold"])
    if clean.empty:
        raise ValueError("No XGBoost training rows remain after lag creation.")

    model = xgb.XGBRegressor(**{**DEFAULT_XGBOOST_PARAMS, **(params or {}), "random_state": seed})
    model.fit(clean[list(XGBOOST_FEATURE_COLUMNS)], clean["units_sold"])
    return model


def forecast_xgboost(model: xgb.XGBRegressor, batch: ForecastBatch) -> np.ndarray:
    """Recursive multi-step XGBoost forecast, shape (origins, horizon)."""
    units = np.concatenate(
        [batch.history_units[:, -MAX_LAG:], np.full((batch.size, batch.horizon), np.nan)],
        axis=1,
    )
    for step in range(batch.horizon):
        target = MAX_LAG + step
        last_7 = units[:, target - 7 : target]
        last_14 = units[:, target - 14 : target]
        values = {
            **{
                column: batch.future_context[:, step, index]
                for index, column in enumerate(CONTEXT_COLUMNS)
            },
            "lag_1": units[:, target - 1],
            "lag_7": units[:, target - 7],
            "lag_14": units[:, target - 14],
            "rolling_mean_7": last_7.mean(axis=1),
            "rolling_std_7": last_7.std(axis=1, ddof=1),
            "rolling_mean_14": last_14.mean(axis=1),
            "rolling_std_14": last_14.std(axis=1, ddof=1),
            "day_of_week": batch.future_day_of_week[:, step].astype(float),
        }
        features = pd.DataFrame({column: values[column] for column in XGBOOST_FEATURE_COLUMNS})
        units[:, target] = np.clip(model.predict(features), 0, None)

    return units[:, MAX_LAG:]


def fit_lstm(
    train: pd.DataFrame,
    seed: int,
    lookback: int = DEFAULT_LOOKBACK,
    epochs: int = 15,
    batch_size: int = 32,
    verbose: int = 0,
    units: list[int] | tuple[int, ...] = (64, 32),
    dropout: float = 0.2,
) -> tuple[Any, MinMaxScaler]:
    """Fit a stacked LSTM; the defaults are the original 64-then-32 network."""
    import tensorflow as tf
    from tensorflow.keras.callbacks import EarlyStopping
    from tensorflow.keras.layers import LSTM, Dense, Dropout, Input
    from tensorflow.keras.models import Sequential

    tf.keras.utils.set_random_seed(seed)

    scaler = MinMaxScaler()
    scaled = scaler.fit_transform(train[list(LSTM_FEATURE_COLUMNS)].astype(np.float32))
    windows = np.lib.stride_tricks.sliding_window_view(scaled, lookback, axis=0)
    X_train = np.transpose(windows[:-1], (0, 2, 1)).astype(np.float32)
    y_train = scaled[lookback:, 0].astype(np.float32)
    if len(X_train) < 2:
        raise ValueError(f"Not enough LSTM training sequences for lookback={lookback}.")

    layers: list[Any] = [Input(shape=(lookback, len(LSTM_FEATURE_COLUMNS)))]
    for index, layer_units in enumerate(units):
        layers.append(
            LSTM(layer_units, activation="tanh", return_sequences=index < len(units) - 1)
        )
        layers.append(Dropout(dropout))
    model = Sequential([*layers, Dense(1)])
    model.compile(optimizer="adam", loss="mse")

    use_validation = len(X_train) >= 20
    model.fit(
        X_train,
        y_train,
        epochs=epochs,
        batch_size=batch_size,
        validation_split=0.10 if use_validation else 0.0,
        callbacks=(
            [EarlyStopping(monitor="val_loss", patience=5, restore_best_weights=True)]
            if use_validation
            else []
        ),
        shuffle=False,
        verbose=verbose,
    )
    return model, scaler


def forecast_lstm(
    model: Any,
    scaler: MinMaxScaler,
    batch: ForecastBatch,
    lookback: int = DEFAULT_LOOKBACK,
) -> np.ndarray:
    """Recursive multi-step LSTM forecast, shape (origins, horizon)."""
    history = np.concatenate(
        [batch.history_units[:, -lookback:, None], batch.history_context[:, -lookback:, :]],
        axis=2,
    )
    future = np.concatenate(
        [np.zeros((batch.size, batch.horizon, 1)), batch.future_context],
        axis=2,
    )
    scaled = np.concatenate([history, future], axis=1) * scaler.scale_ + scaler.min_
    scaled = scaled.astype(np.float32)

    predictions = np.empty((batch.size, batch.horizon))
    for step in range(batch.horizon):
        output = np.asarray(model(scaled[:, step : step + lookback, :], training=False))
        units = np.clip((output.reshape(-1) - scaler.min_[0]) / scaler.scale_[0], 0, None)
        predictions[:, step] = units
        scaled[:, lookback + step, 0] = units * scaler.scale_[0] + scaler.min_[0]

    return predictions


class LinearStacker:
    """Predicts intercept + weights . base predictions, clipped at zero.

    Exposes coef_ and intercept_ like scikit-learn's LinearRegression, and
    accepts a DataFrame with STACK_FEATURE_COLUMNS, so the FastAPI service can
    load it as meta_learner.joblib unchanged.
    """

    def __init__(self, coef: np.ndarray | list[float], intercept: float = 0.0) -> None:
        self.coef_ = np.asarray(coef, dtype=float)
        self.intercept_ = float(intercept)

    def predict(self, features: pd.DataFrame | np.ndarray) -> np.ndarray:
        values = (
            features[list(STACK_FEATURE_COLUMNS)].to_numpy(dtype=float)
            if isinstance(features, pd.DataFrame)
            else np.asarray(features, dtype=float)
        )
        return np.clip(values @ self.coef_ + self.intercept_, 0, None)


def forecast_seasonal_naive(batch: ForecastBatch) -> np.ndarray:
    """Repeat the last observed week; a reference every model should beat."""
    last_week = batch.history_units[:, -7:]
    return np.tile(last_week, (1, int(np.ceil(batch.horizon / 7))))[:, : batch.horizon]
