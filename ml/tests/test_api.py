from datetime import date, timedelta

import numpy as np
import pandas as pd
import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from serving.api import (
    DEFAULT_XGBOOST_FEATURE_COLUMNS,
    FEATURE_COLUMNS,
    LSTM_FEATURE_COLUMNS,
    ModelBundle,
    PredictionRequest,
    app,
    predict_units,
)


class FakeProphet:
    extra_regressors = {name: {} for name in FEATURE_COLUMNS}

    def predict(self, frame: pd.DataFrame) -> pd.DataFrame:
        assert bool(frame.iloc[0]["is_promotion"]) is True
        return pd.DataFrame({"yhat": [10.0]})


class FakeXGBoost:
    def predict(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == list(DEFAULT_XGBOOST_FEATURE_COLUMNS)
        assert frame.iloc[0]["lag_1"] == 13
        return np.array([20.0])


class FakeScaler:
    def transform(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == list(LSTM_FEATURE_COLUMNS)
        return frame.to_numpy(dtype=np.float32)

    def inverse_transform(self, values: np.ndarray) -> np.ndarray:
        return values


class FakeLSTM:
    def predict(self, values: np.ndarray, verbose: int) -> np.ndarray:
        assert values.shape == (1, 14, 5)
        assert verbose == 0
        return np.array([[30.0]])


class FakeMetaLearner:
    def predict(self, frame: pd.DataFrame) -> np.ndarray:
        assert list(frame.columns) == ["prophet_pred", "xgboost_pred", "lstm_pred"]
        return np.array([frame.iloc[0].sum()])


def make_request_body() -> dict:
    target = date(2026, 10, 1)
    history = []
    for offset in range(14, 0, -1):
        history.append(
            {
                "date": (target - timedelta(days=offset)).isoformat(),
                "units_sold": 14 - offset,
                "is_promotion": False,
                "is_public_holiday": False,
                "is_school_term": True,
                "temperature_c": 22.0,
            }
        )
    history[-1]["is_promotion"] = True

    return {
        "sku": "1L",
        "date": target.isoformat(),
        "history": history,
        "is_promotion": True,
        "is_public_holiday": False,
        "is_school_term": True,
        "temperature_c": 23.5,
    }


def make_bundle() -> ModelBundle:
    return ModelBundle(
        prophet=FakeProphet(),
        xgboost=FakeXGBoost(),
        lstm=FakeLSTM(),
        lstm_scaler=FakeScaler(),
        meta_learner=FakeMetaLearner(),
        manifest={
            "model_version": "2026-10-01",
            "rmse_score": 12.3456,
            "xgboost_feature_cols": list(DEFAULT_XGBOOST_FEATURE_COLUMNS),
        },
    )


def test_stacked_prediction_scores_prophet_xgboost_and_lstm_outputs() -> None:
    payload = PredictionRequest.model_validate(make_request_body())

    prediction = predict_units(make_bundle(), payload)

    assert prediction == 60.0


def test_request_rejects_gaps_in_the_recent_history() -> None:
    body = make_request_body()
    body["history"][7]["date"] = body["history"][6]["date"]

    with pytest.raises(ValidationError, match="consecutive"):
        PredictionRequest.model_validate(body)


def test_predict_endpoint_returns_the_stacked_units() -> None:
    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle()}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 200
    assert response.json() == {
        "predicted_units_sold": 60.0,
        "model_version": "2026-10-01",
        "rmse_score": 12.3456,
    }


def test_predict_endpoint_reports_unavailable_sku_models() -> None:
    with TestClient(app) as client:
        app.state.models = {}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 503


def test_predict_endpoint_reports_missing_model_metadata() -> None:
    bundle = make_bundle()
    bundle.manifest.pop("rmse_score")

    with TestClient(app) as client:
        app.state.models = {"1L": bundle}

        response = client.post("/predict", json=make_request_body())

    assert response.status_code == 503


def test_health_endpoint_lists_served_model_versions() -> None:
    broken = make_bundle()
    broken.manifest.pop("model_version")

    with TestClient(app) as client:
        app.state.models = {"1L": make_bundle(), "5L": broken}

        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "models": {"1L": "2026-10-01"}}
