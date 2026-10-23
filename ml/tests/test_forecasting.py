import numpy as np
import pandas as pd
import pytest

from tests.test_training_pipeline import make_daily_data
from training.forecasting import (
    LSTM_FEATURE_COLUMNS,
    XGBOOST_FEATURE_COLUMNS,
    TemperatureClimatology,
    build_forecast_batch,
    engineer_xgboost_features,
    fit_lstm,
    fit_xgboost,
    forecast_lstm,
    forecast_seasonal_naive,
    forecast_xgboost,
    prepare_context,
)
from training.rolling_origin import fold_origins


def predict_xgboost(model, feature_columns, history, current) -> np.ndarray:
    """The original one-step XGBoost inference, kept as a reference."""
    combined = pd.concat([history, current], ignore_index=True).sort_values("date")
    engineered = engineer_xgboost_features(combined.reset_index(drop=True))
    rows = engineered.loc[engineered["date"].isin(current["date"])]
    return np.clip(model.predict(rows[feature_columns]), 0, None)


def predict_lstm(model, scaler, history, current, lookback) -> np.ndarray:
    """The original one-step LSTM inference, kept as a reference."""
    inference = pd.concat([history.tail(lookback), current], ignore_index=True)
    scaled = scaler.transform(inference[list(LSTM_FEATURE_COLUMNS)].astype(np.float32))
    sequences = np.asarray(
        [scaled[index : index + lookback] for index in range(len(scaled) - lookback)],
        dtype=np.float32,
    )
    predictions = model.predict(sequences, verbose=0).reshape(-1)
    inverse = np.zeros((len(predictions), len(LSTM_FEATURE_COLUMNS)), dtype=np.float32)
    inverse[:, 0] = predictions
    return np.clip(scaler.inverse_transform(inverse)[:, 0], 0, None)


def prepared_frame(days: int = 160) -> pd.DataFrame:
    frame = make_daily_data(days)
    frame["date"] = pd.to_datetime(frame["date"])
    return prepare_context(frame, "actual", None)


def test_one_day_xgboost_forecast_matches_the_one_step_pipeline() -> None:
    frame = prepared_frame()
    model = fit_xgboost(frame.iloc[:100], seed=21)
    batch = build_forecast_batch(frame, np.arange(99, 111), horizon=1, history_length=14)

    expected = predict_xgboost(
        model, list(XGBOOST_FEATURE_COLUMNS), frame.iloc[:100], frame.iloc[100:112]
    )

    np.testing.assert_allclose(forecast_xgboost(model, batch)[:, 0], expected, rtol=1e-6)


def test_one_day_lstm_forecast_matches_the_one_step_pipeline() -> None:
    frame = prepared_frame()
    model, scaler = fit_lstm(frame.iloc[:100], seed=21, epochs=1)
    batch = build_forecast_batch(frame, np.arange(99, 111), horizon=1, history_length=14)

    expected = predict_lstm(model, scaler, frame.iloc[:100], frame.iloc[100:112], lookback=14)

    np.testing.assert_allclose(forecast_lstm(model, scaler, batch)[:, 0], expected, rtol=1e-4)


def test_forecasts_ignore_sales_after_the_origin() -> None:
    frame = prepared_frame()
    model = fit_xgboost(frame.iloc[:100], seed=21)
    lstm_model, scaler = fit_lstm(frame.iloc[:100], seed=21, epochs=1)
    altered = frame.copy()
    altered.loc[100:, "units_sold"] = np.nan

    for forecast in (
        lambda batch: forecast_xgboost(model, batch),
        lambda batch: forecast_lstm(lstm_model, scaler, batch),
        forecast_seasonal_naive,
    ):
        original = forecast(build_forecast_batch(frame, [99], horizon=28, history_length=14))
        blinded = forecast(build_forecast_batch(altered, [99], horizon=28, history_length=14))
        np.testing.assert_allclose(original, blinded)


def test_climatology_uses_training_months_only() -> None:
    frame = prepared_frame()
    climatology = TemperatureClimatology.fit(frame.iloc[:31])
    january_mean = frame.iloc[:31]["temperature_c"].mean()

    applied = prepare_context(frame, "climatology", climatology)

    assert climatology.monthly_means[1] == pytest.approx(january_mean)
    assert climatology.monthly_means[6] == pytest.approx(january_mean)
    january = applied.loc[applied["date"].dt.month == 1, "temperature_c"]
    np.testing.assert_allclose(january, january_mean)


def test_fold_targets_never_reach_the_final_test_split() -> None:
    origins = fold_origins(
        base_end=657, meta_end=876, fold_step=28, origins_per_fold=28, horizon=28, max_folds=10
    )

    assert origins == [656, 684, 712, 740, 768, 796]
    assert origins[-1] + 27 + 28 <= 875


def test_final_test_folds_start_at_the_end_of_meta_train_and_stay_in_the_data() -> None:
    origins = fold_origins(
        base_end=876, meta_end=1096, fold_step=28, origins_per_fold=28, horizon=28, max_folds=10
    )

    assert origins == [875, 903, 931, 959, 987, 1015]
    assert origins[-1] + 27 + 28 <= 1095
