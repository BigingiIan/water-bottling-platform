"""Pure helpers for the Forecast tab (no Streamlit import, so they are easy to test)."""

from __future__ import annotations

import numpy as np
import pandas as pd

CONTEXT_FLAGS = ["is_promotion", "is_public_holiday", "is_school_term"]
HISTORY_DAYS = 60
BAND_HORIZONS = (1, 7, 14, 28)


def defaults_from_last_year(history: pd.DataFrame, dates: pd.DatetimeIndex) -> pd.DataFrame:
    """Default calendar flags for future dates: the flag on the same calendar date last year.

    Fixed-date holidays and school terms repeat well this way. Movable holidays
    (Easter, Eid) do not, and promotions are unknown, so promotions default to
    False. The user can edit every value before forecasting.
    """
    by_day = {
        (row.date.month, row.date.day): row
        for row in history.sort_values("date").itertuples(index=False)
    }
    rows = []
    for day in dates:
        source = by_day.get((day.month, day.day))
        rows.append(
            {
                "date": day,
                "is_promotion": False,
                "is_public_holiday": bool(source.is_public_holiday) if source is not None else False,
                "is_school_term": bool(source.is_school_term) if source is not None else False,
            }
        )
    return pd.DataFrame(rows)


def build_context(
    sku_frame: pd.DataFrame,
    as_of: pd.Timestamp,
    horizon: int,
    future_context: pd.DataFrame | None = None,
) -> tuple[pd.DataFrame, pd.Series | None]:
    """Build the `context_df` that `predict_sku` expects.

    History rows end on `as_of` and carry `units_sold`. Future rows carry only
    the calendar flags. With `future_context=None` the future flags come from
    the data itself (backtest) and the actual sales are returned for scoring.
    """
    as_of = pd.Timestamp(as_of)
    columns = ["date", "units_sold", *CONTEXT_FLAGS]
    history = sku_frame.loc[sku_frame["date"] <= as_of, columns].tail(HISTORY_DAYS)
    if len(history) < 14:
        raise ValueError("Need at least 14 days of sales history before the forecast start.")

    actual: pd.Series | None = None
    if future_context is None:
        wanted = pd.date_range(as_of + pd.Timedelta(days=1), periods=horizon, freq="D")
        future_rows = sku_frame[sku_frame["date"].isin(wanted)]
        if len(future_rows) != horizon:
            raise ValueError("The data does not cover the whole forecast window.")
        actual = future_rows.set_index("date")["units_sold"].astype(float)
        future = future_rows[["date", *CONTEXT_FLAGS]].copy()
    else:
        future = future_context[["date", *CONTEXT_FLAGS]].copy().head(horizon)
        if len(future) != horizon:
            raise ValueError("The calendar table has fewer rows than the forecast horizon.")

    future["units_sold"] = np.nan
    context = pd.concat([history, future[columns]], ignore_index=True)
    for column in CONTEXT_FLAGS:
        context[column] = context[column].astype(int)
    context["date"] = pd.to_datetime(context["date"])
    return context, actual


def rmse_by_horizon(rmse_at: dict[int, float], horizons: np.ndarray) -> np.ndarray:
    """Interpolate evaluated RMSE (h=1, 7, 14, 28) to every requested horizon."""
    known = sorted(rmse_at)
    return np.interp(horizons, known, [rmse_at[h] for h in known])


def score_window(actual: np.ndarray, predicted: np.ndarray) -> dict[str, float]:
    """RMSE, MAPE and sMAPE over one window (MAPE and sMAPE in percent)."""
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)
    error = predicted - actual
    nonzero = actual != 0
    denominator = (np.abs(actual) + np.abs(predicted)) / 2
    valid = denominator != 0
    return {
        "RMSE": float(np.sqrt(np.mean(error**2))),
        "MAPE": float(np.mean(np.abs(error[nonzero] / actual[nonzero])) * 100) if nonzero.any() else float("nan"),
        "sMAPE": float(np.mean(np.abs(error[valid]) / denominator[valid]) * 100) if valid.any() else float("nan"),
    }
