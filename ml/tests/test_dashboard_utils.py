import numpy as np
import pandas as pd
import pytest

from dashboard.forecast_utils import (
    CONTEXT_FLAGS,
    build_context,
    defaults_from_last_year,
    rmse_by_horizon,
    score_window,
)


def make_frame(days: int = 120) -> pd.DataFrame:
    dates = pd.date_range("2025-01-01", periods=days, freq="D")
    return pd.DataFrame(
        {
            "date": dates,
            "units_sold": np.arange(days, dtype=float) + 100,
            "is_promotion": False,
            "is_public_holiday": dates.strftime("%m-%d") == "01-01",
            "is_school_term": dates.month.isin([1, 2, 3]),
        }
    )


def test_backtest_context_shapes_and_actuals():
    frame = make_frame()
    as_of = pd.Timestamp("2025-03-01")
    context, actual = build_context(frame, as_of, 7)
    assert len(context) == 60 + 7
    assert context["units_sold"].iloc[-7:].isna().all()
    assert context["units_sold"].iloc[:-7].notna().all()
    assert list(actual.index) == list(pd.date_range("2025-03-02", periods=7))
    assert actual.iloc[0] == frame.loc[frame["date"] == "2025-03-02", "units_sold"].iloc[0]
    for column in CONTEXT_FLAGS:
        assert context[column].isin([0, 1]).all()


def test_backtest_rejects_window_beyond_data():
    frame = make_frame()
    with pytest.raises(ValueError, match="does not cover"):
        build_context(frame, frame["date"].max() - pd.Timedelta(days=2), 7)


def test_requires_fourteen_days_of_history():
    frame = make_frame()
    with pytest.raises(ValueError, match="at least 14"):
        build_context(frame, pd.Timestamp("2025-01-10"), 3)


def test_future_context_is_used_when_given():
    frame = make_frame(60)
    dates = pd.date_range(frame["date"].max() + pd.Timedelta(days=1), periods=5)
    future = pd.DataFrame({"date": dates, "is_promotion": True, "is_public_holiday": False, "is_school_term": False})
    context, actual = build_context(frame, frame["date"].max(), 5, future)
    assert actual is None
    assert context["is_promotion"].iloc[-5:].eq(1).all()
    assert len(context) == 60 + 5


def test_future_context_must_cover_horizon():
    frame = make_frame(60)
    dates = pd.date_range(frame["date"].max() + pd.Timedelta(days=1), periods=2)
    future = pd.DataFrame({"date": dates, "is_promotion": False, "is_public_holiday": False, "is_school_term": False})
    with pytest.raises(ValueError, match="fewer rows"):
        build_context(frame, frame["date"].max(), 5, future)


def test_defaults_copy_same_calendar_date_last_year():
    frame = make_frame(365)
    defaults = defaults_from_last_year(frame, pd.date_range("2026-01-01", periods=3))
    assert defaults["is_public_holiday"].tolist() == [True, False, False]
    assert defaults["is_school_term"].all()
    assert not defaults["is_promotion"].any()


def test_defaults_fall_back_to_false_without_a_match():
    frame = make_frame(30)
    defaults = defaults_from_last_year(frame, pd.date_range("2026-07-01", periods=2))
    assert not defaults[["is_public_holiday", "is_school_term"]].any().any()


def test_rmse_interpolation_and_clamping():
    known = {1: 10.0, 7: 16.0, 14: 16.0, 28: 30.0}
    band = rmse_by_horizon(known, np.array([1, 4, 7, 21, 28, 40]))
    assert band[0] == 10.0
    assert band[1] == pytest.approx(13.0)
    assert band[2] == 16.0
    assert band[3] == pytest.approx(23.0)
    assert band[4] == 30.0
    assert band[5] == 30.0


def test_score_window_matches_hand_calculation():
    scores = score_window(np.array([100.0, 200.0]), np.array([110.0, 180.0]))
    assert scores["RMSE"] == pytest.approx(np.sqrt((100 + 400) / 2))
    assert scores["MAPE"] == pytest.approx((0.10 + 0.10) / 2 * 100)
    assert scores["sMAPE"] == pytest.approx((10 / 105 + 20 / 190) / 2 * 100)


def test_score_window_ignores_zero_actuals_in_mape():
    scores = score_window(np.array([0.0, 100.0]), np.array([5.0, 90.0]))
    assert scores["MAPE"] == pytest.approx(10.0)
