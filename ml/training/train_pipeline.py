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
import tensorflow as tf
import xgboost as xgb
from prophet import Prophet
from sklearn.linear_model import LinearRegression
from sklearn.preprocessing import MinMaxScaler
from tensorflow.keras.callbacks import EarlyStopping
from tensorflow.keras.layers import Dense, Dropout, Input, LSTM
from tensorflow.keras.models import Sequential

LOGGER = logging.getLogger("ml.training")
SEED = 21
LSTM_LOOKBACK = 14
CONTEXT_COLUMNS = (
    "is_promotion",
    "is_public_holiday",
    "is_school_term",
    "temperature_c",
)
LSTM_FEATURE_COLUMNS = ("units_sold", *CONTEXT_COLUMNS)
STACK_FEATURE_COLUMNS = ("prophet_pred", "xgboost_pred", "lstm_pred")
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
XGBOOST_FEATURE_CANDIDATES = (
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


@dataclass
class FittedSku:
    prophet: Any
    xgboost: xgb.XGBRegressor
    lstm: Sequential
    lstm_scaler: MinMaxScaler
    meta_stacker: LinearRegression
    prophet_regressors: list[str]
    xgboost_feature_cols: list[str]
    final_metrics: dict[str, dict[str, float]]
    metadata: dict[str, Any]


def parse_args() -> argparse.Namespace:
    default_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(
        description="Train per-SKU demand models and export FastAPI-ready bundles."
    )
    parser.add_argument(
        "--data",
        type=Path,
        default=default_root / "data" / "kenya_water_demand_daily.csv",
        help="CSV with date, product_sku, units_sold and the four context features.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=default_root / "saved_models",
        help="Directory receiving one model artifact directory per SKU.",
    )
    parser.add_argument("--lookback", type=int, default=LSTM_LOOKBACK)
    parser.add_argument("--epochs", type=int, default=15)
    parser.add_argument("--batch-size", type=int, default=32)
    parser.add_argument("--verbose", type=int, choices=(0, 1, 2), default=2)
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


def engineer_xgboost_features(frame: pd.DataFrame) -> pd.DataFrame:
    result = frame.copy()
    for lag in (1, 7, 14):
        result[f"lag_{lag}"] = result["units_sold"].shift(lag)

    shifted_sales = result["units_sold"].shift(1)
    for window in (7, 14):
        result[f"rolling_mean_{window}"] = shifted_sales.rolling(window=window).mean()
        result[f"rolling_std_{window}"] = shifted_sales.rolling(window=window).std()

    result["day_of_week"] = result["date"].dt.dayofweek.astype(float)
    return result


def _xgboost_prediction_rows(
    history: pd.DataFrame,
    current: pd.DataFrame,
) -> pd.DataFrame:
    combined = pd.concat([history, current], ignore_index=True)
    combined = combined.sort_values("date").reset_index(drop=True)
    start_date = current["date"].min()
    result = engineer_xgboost_features(combined)
    return result.loc[result["date"].isin(current["date"]) & (result["date"] >= start_date)].copy()


def predict_xgboost(
    model: xgb.XGBRegressor,
    feature_columns: list[str],
    history: pd.DataFrame,
    current: pd.DataFrame,
) -> np.ndarray:
    engineered = _xgboost_prediction_rows(history, current)
    features = engineered[feature_columns]
    if features.isna().any().any():
        raise ValueError("XGBoost inference features contain missing lag values.")

    return np.clip(model.predict(features), 0, None)


def fit_lstm(
    base_train: pd.DataFrame,
    lookback: int,
    epochs: int,
    batch_size: int,
    verbose: int,
    seed: int,
) -> tuple[Sequential, MinMaxScaler]:
    np.random.seed(seed)
    tf.random.set_seed(seed)

    scaler = MinMaxScaler()
    scaled = scaler.fit_transform(base_train[list(LSTM_FEATURE_COLUMNS)].astype(np.float32))
    X_train, y_train = _make_lstm_sequences(scaled, lookback)
    if len(X_train) < 2:
        raise ValueError(f"Not enough LSTM training sequences for lookback={lookback}.")

    model = Sequential(
        [
            Input(shape=(lookback, len(LSTM_FEATURE_COLUMNS))),
            LSTM(64, activation="tanh", return_sequences=True),
            Dropout(0.2),
            LSTM(32, activation="tanh"),
            Dropout(0.2),
            Dense(1),
        ]
    )
    model.compile(optimizer="adam", loss="mse")

    use_validation = len(X_train) >= 20
    callbacks = (
        [EarlyStopping(monitor="val_loss", patience=5, restore_best_weights=True)]
        if use_validation
        else []
    )
    model.fit(
        X_train,
        y_train,
        epochs=epochs,
        batch_size=batch_size,
        validation_split=0.10 if use_validation else 0.0,
        callbacks=callbacks,
        shuffle=False,
        verbose=verbose,
    )

    return model, scaler


def _make_lstm_sequences(
    scaled_data: np.ndarray,
    lookback: int,
) -> tuple[np.ndarray, np.ndarray]:
    X, y = [], []
    for index in range(len(scaled_data) - lookback):
        X.append(scaled_data[index : index + lookback, :])
        y.append(scaled_data[index + lookback, 0])

    return np.asarray(X, dtype=np.float32), np.asarray(y, dtype=np.float32)


def predict_lstm(
    model: Sequential,
    scaler: MinMaxScaler,
    history: pd.DataFrame,
    current: pd.DataFrame,
    lookback: int,
) -> np.ndarray:
    inference_data = pd.concat(
        [history.tail(lookback), current],
        ignore_index=True,
    )
    scaled = scaler.transform(inference_data[list(LSTM_FEATURE_COLUMNS)].astype(np.float32))
    sequences, _ = _make_lstm_sequences(scaled, lookback)
    if len(sequences) != len(current):
        raise ValueError(
            f"LSTM generated {len(sequences)} predictions for {len(current)} rows."
        )

    scaled_predictions = model.predict(sequences, verbose=0).reshape(-1)
    inverse_data = np.zeros((len(scaled_predictions), len(LSTM_FEATURE_COLUMNS)), dtype=np.float32)
    inverse_data[:, 0] = scaled_predictions
    return np.clip(scaler.inverse_transform(inverse_data)[:, 0], 0, None)


def model_metrics(y_true: np.ndarray, y_pred: np.ndarray) -> dict[str, float]:
    actual = np.asarray(y_true, dtype=float)
    predicted = np.asarray(y_pred, dtype=float)
    if len(actual) != len(predicted) or len(actual) == 0:
        raise ValueError("Metric inputs must be non-empty and have matching lengths.")

    denominator = (np.abs(actual) + np.abs(predicted)) / 2.0
    return {
        "RMSE": float(np.sqrt(np.mean((actual - predicted) ** 2))),
        "MAPE": float(np.mean(np.abs(actual - predicted) / np.clip(actual, 1e-8, None)) * 100),
        "sMAPE": float(
            np.mean(np.abs(predicted - actual) / np.clip(denominator, 1e-8, None)) * 100
        ),
    }


def fit_sku(
    sku_data: pd.DataFrame,
    lookback: int,
    epochs: int,
    batch_size: int,
    verbose: int,
    seed: int,
    model_version: str,
) -> FittedSku:
    sku = str(sku_data["product_sku"].iloc[0])
    base_train, meta_train, final_test = chronological_split(sku_data, lookback)

    prophet_regressors = [
        column for column in CONTEXT_COLUMNS if base_train[column].nunique(dropna=False) > 1
    ]
    prophet_model = Prophet(
        yearly_seasonality=len(base_train) >= 730,
        weekly_seasonality=True,
        daily_seasonality=False,
    )
    for column in prophet_regressors:
        prophet_model.add_regressor(column)

    prophet_fit = base_train.rename(columns={"date": "ds", "units_sold": "y"})
    prophet_model.fit(prophet_fit[["ds", "y", *prophet_regressors]])

    def prophet_predict(frame: pd.DataFrame) -> np.ndarray:
        future = frame.rename(columns={"date": "ds"})
        return np.clip(
            prophet_model.predict(future[["ds", *prophet_regressors]])["yhat"].to_numpy(),
            0,
            None,
        )

    xgb_feature_columns = list(XGBOOST_FEATURE_CANDIDATES)
    base_features = engineer_xgboost_features(base_train)
    clean_base = base_features.dropna(subset=[*xgb_feature_columns, "units_sold"])
    if clean_base.empty:
        raise ValueError(f"SKU {sku} has no valid XGBoost training rows after lag creation.")

    xgb_model = xgb.XGBRegressor(
        n_estimators=100,
        max_depth=6,
        learning_rate=0.1,
        subsample=0.8,
        colsample_bytree=0.8,
        random_state=seed,
        n_jobs=-1,
        verbosity=0,
    )
    xgb_model.fit(clean_base[xgb_feature_columns], clean_base["units_sold"])

    lstm_model, lstm_scaler = fit_lstm(
        base_train,
        lookback,
        epochs,
        batch_size,
        verbose,
        seed,
    )

    prophet_meta = prophet_predict(meta_train)
    xgb_meta = predict_xgboost(xgb_model, xgb_feature_columns, base_train, meta_train)
    lstm_meta = predict_lstm(lstm_model, lstm_scaler, base_train, meta_train, lookback)

    stack_train = pd.DataFrame(
        {
            "prophet_pred": prophet_meta,
            "xgboost_pred": xgb_meta,
            "lstm_pred": lstm_meta,
        },
        columns=STACK_FEATURE_COLUMNS,
    )
    meta_stacker = LinearRegression()
    meta_stacker.fit(stack_train, meta_train["units_sold"].to_numpy())

    prophet_final = prophet_predict(final_test)
    xgb_final = predict_xgboost(xgb_model, xgb_feature_columns, meta_train, final_test)
    lstm_final = predict_lstm(lstm_model, lstm_scaler, meta_train, final_test, lookback)
    stack_final = pd.DataFrame(
        {
            "prophet_pred": prophet_final,
            "xgboost_pred": xgb_final,
            "lstm_pred": lstm_final,
        },
        columns=STACK_FEATURE_COLUMNS,
    )
    stacked_predictions = np.clip(meta_stacker.predict(stack_final), 0, None)
    actuals = final_test["units_sold"].to_numpy()

    final_metrics = {
        "Prophet": model_metrics(actuals, prophet_final),
        "XGBoost": model_metrics(actuals, xgb_final),
        "LSTM": model_metrics(actuals, lstm_final),
        "Stacked": model_metrics(actuals, stacked_predictions),
    }
    rmse_score = final_metrics["Stacked"]["RMSE"]
    if not np.isfinite(rmse_score) or rmse_score > 9999.9999:
        raise ValueError(
            f"SKU {sku} stacked RMSE {rmse_score} is outside the FastAPI manifest limits."
        )

    return FittedSku(
        prophet=prophet_model,
        xgboost=xgb_model,
        lstm=lstm_model,
        lstm_scaler=lstm_scaler,
        meta_stacker=meta_stacker,
        prophet_regressors=prophet_regressors,
        xgboost_feature_cols=xgb_feature_columns,
        final_metrics=final_metrics,
        metadata={
            "model_version": model_version,
            "rmse_score": rmse_score,
            "prophet_regressors": prophet_regressors,
            "xgboost_feature_cols": xgb_feature_columns,
            "lstm_lookback": lookback,
        },
    )


def save_sku_bundle(output_dir: Path, sku: str, fitted: FittedSku) -> Path:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", sku):
        raise ValueError(f"SKU cannot safely be used as a model directory: {sku!r}")

    output_dir.mkdir(parents=True, exist_ok=True)
    target_dir = output_dir / sku
    target_dir.parent.mkdir(parents=True, exist_ok=True)

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
    missing = [
        filename for filename in ARTIFACT_FILENAMES if not (directory / filename).is_file()
    ]
    if missing:
        raise RuntimeError(f"Incomplete artifact bundle in {directory}: {missing}")

    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    if not isinstance(manifest.get("model_version"), str) or not manifest["model_version"].strip():
        raise RuntimeError(f"Model bundle has an invalid model_version: {directory}")

    rmse_score = manifest.get("rmse_score")
    if (
        isinstance(rmse_score, bool)
        or not isinstance(rmse_score, (int, float))
        or not np.isfinite(rmse_score)
        or not 0 <= rmse_score <= 9999.9999
    ):
        raise RuntimeError(f"Model bundle has an invalid rmse_score: {directory}")

    metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))
    if metadata != manifest:
        raise RuntimeError(f"metadata.json and manifest.json differ: {directory}")

    return manifest


def comparison_table(results: dict[str, FittedSku]) -> pd.DataFrame:
    rows = {}
    for sku, fitted in results.items():
        rows[sku] = {
            (model_name, metric_name): metric_value
            for model_name, metrics in fitted.final_metrics.items()
            for metric_name, metric_value in metrics.items()
        }

    table = pd.DataFrame.from_dict(rows, orient="index")
    table.index.name = "SKU"
    table.columns = pd.MultiIndex.from_tuples(table.columns, names=["Model", "Metric"])
    return table.sort_index()


def run_training(
    data_path: Path,
    output_dir: Path,
    lookback: int = LSTM_LOOKBACK,
    epochs: int = 15,
    batch_size: int = 32,
    verbose: int = 2,
    seed: int = SEED,
) -> pd.DataFrame:
    if lookback < LSTM_LOOKBACK:
        raise ValueError(f"lookback must be at least {LSTM_LOOKBACK}.")
    if epochs < 1 or batch_size < 1:
        raise ValueError("epochs and batch_size must be positive.")

    model_version = (
        datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    )
    data = load_daily_data(data_path)
    if data.empty:
        raise ValueError("Training data contains no SKU rows.")
    results: dict[str, FittedSku] = {}
    output_dir.mkdir(parents=True, exist_ok=True)

    for sku, sku_data in data.groupby("product_sku", sort=True):
        LOGGER.info("Training SKU %s (%s daily rows)", sku, len(sku_data))
        fitted = fit_sku(
            sku_data.reset_index(drop=True),
            lookback,
            epochs,
            batch_size,
            verbose,
            seed,
            model_version,
        )
        saved_dir = save_sku_bundle(output_dir, sku, fitted)
        results[sku] = fitted
        LOGGER.info("Saved complete model bundle: %s", saved_dir)

    table = comparison_table(results)
    print("\nFinal test comparison (RMSE / MAPE / sMAPE):")
    print(table.to_string(float_format=lambda value: f"{value:.4f}"))

    for sku in results:
        bundle_path = output_dir / sku
        manifest = verify_artifact_bundle(bundle_path)
        if manifest["model_version"] != model_version:
            raise RuntimeError(f"SKU {sku} metadata has an unexpected training timestamp.")
        if not np.isclose(manifest["rmse_score"], results[sku].final_metrics["Stacked"]["RMSE"]):
            raise RuntimeError(f"SKU {sku} metadata RMSE does not match its final-test score.")

    print(
        f"\nVerified complete artifact and metadata bundles for {len(results)} SKU(s) "
        f"under {output_dir}."
    )
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
            lookback=args.lookback,
            epochs=args.epochs,
            batch_size=args.batch_size,
            verbose=args.verbose,
            seed=args.seed,
        )
    except Exception:
        LOGGER.exception("Training did not complete successfully.")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
