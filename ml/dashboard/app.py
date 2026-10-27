"""AquaOps forecasting dashboard.

Run from the ml directory:

    uv run streamlit run dashboard/app.py

Reads the saved model bundles and metrics files written by
`training.train_pipeline`, and calls `serving.inference.predict_sku` directly.
There is no HTTP API in between.
"""

from __future__ import annotations

import sys
from pathlib import Path

ML_ROOT = Path(__file__).resolve().parents[1]
if str(ML_ROOT) not in sys.path:
    sys.path.insert(0, str(ML_ROOT))

import altair as alt
import numpy as np
import pandas as pd
import streamlit as st

from dashboard import data
from dashboard.forecast_utils import (
    BAND_HORIZONS,
    HISTORY_DAYS,
    build_context,
    defaults_from_last_year,
    rmse_by_horizon,
    score_window,
)

st.set_page_config(page_title="AquaOps Forecasting", page_icon=":material/water_drop:", layout="wide")

MODEL_COLORS = {
    "Prophet": "#2563eb",
    "Prophet (fold fit)": "#60a5fa",
    "XGBoost": "#0d9488",
    "LSTM": "#d97706",
    "Seasonal naive": "#94a3b8",
}
METRIC_LABELS = {"RMSE": "RMSE (units per day)", "MAPE": "MAPE (%)", "sMAPE": "sMAPE (%)"}
RUN_LABELS = {
    "final_test": "Final test (reported result)",
    "development_baseline": "Development: original settings",
    "development_tuned": "Development: tuned settings",
}


def stretch(chart: alt.Chart) -> None:
    st.altair_chart(chart, width="stretch")


def horizon_label(code: str) -> str:
    return data.HORIZON_LABELS.get(code, code)


def fmt(value: float, digits: int = 1) -> str:
    return "n/a" if value is None or pd.isna(value) else f"{value:,.{digits}f}"


# ---------------------------------------------------------------- Overview

def overview_tab(manifest: dict, summary: pd.DataFrame, bundles: dict[str, dict]) -> None:
    estimate = manifest["reported_estimate"]
    mean = estimate["mean_of_skus"]
    st.subheader("Reported accuracy")
    st.caption(
        f"{estimate['model']} on the {estimate['run'].replace('_', ' ')} window, "
        f"{horizon_label(estimate['horizon']).lower()}, temperature from monthly means. "
        "Averages are simple means across the five products."
    )
    cols = st.columns(3)
    cols[0].metric("RMSE (units per day)", fmt(mean["RMSE_mean"]))
    cols[0].caption(f"Spread across folds: ± {fmt(mean['RMSE_fold_sd'])}")
    cols[1].metric("MAPE", f"{fmt(mean['MAPE_mean'])}%")
    cols[1].caption(f"Spread across folds: ± {fmt(mean['MAPE_fold_sd'], 2)} points")
    cols[2].metric("sMAPE", f"{fmt(mean['sMAPE_mean'])}%")
    cols[2].caption(f"Spread across folds: ± {fmt(mean['sMAPE_fold_sd'], 2)} points")

    st.warning(
        "The data is synthetic (generator seed 42) and its structure suits Prophet. "
        "These numbers show how the pipeline behaves, not accuracy on real Kenyan sales.",
        icon=":material/science:",
    )

    run, horizon = estimate["run"], estimate["horizon"]
    frame = summary[(summary["run"] == run) & (summary["horizon"] == horizon)]
    chosen = frame[frame["model"] == estimate["model"]].set_index("sku")
    naive = frame[frame["model"] == "Seasonal naive"].set_index("sku")
    rows = []
    for sku in [*data.SKUS, data.MEAN_ROW]:
        if sku not in chosen.index:
            continue
        row = {
            "Product": sku,
            "RMSE": chosen.loc[sku, "RMSE_mean"],
            "RMSE ± sd": chosen.loc[sku, "RMSE_fold_sd"],
            "MAPE %": chosen.loc[sku, "MAPE_mean"],
            "sMAPE %": chosen.loc[sku, "sMAPE_mean"],
        }
        if sku in naive.index:
            row["Seasonal naive RMSE"] = naive.loc[sku, "RMSE_mean"]
            row["Better than naive by"] = 1 - chosen.loc[sku, "RMSE_mean"] / naive.loc[sku, "RMSE_mean"]
        if sku in bundles:
            row["Served rmse_score"] = bundles[sku].get("rmse_score")
        rows.append(row)
    st.dataframe(
        pd.DataFrame(rows),
        hide_index=True,
        width="stretch",
        column_config={
            "RMSE": st.column_config.NumberColumn(format="%.1f"),
            "RMSE ± sd": st.column_config.NumberColumn(format="%.1f"),
            "MAPE %": st.column_config.NumberColumn(format="%.1f"),
            "sMAPE %": st.column_config.NumberColumn(format="%.1f"),
            "Seasonal naive RMSE": st.column_config.NumberColumn(format="%.1f"),
            "Better than naive by": st.column_config.NumberColumn(format="percent"),
            "Served rmse_score": st.column_config.NumberColumn(format="%.1f"),
        },
    )
    st.caption(
        "RMSE is in units per day, so 500ml and 1L dominate the average. "
        "Compare products with MAPE and sMAPE."
    )

    st.subheader("What is being served")
    if not bundles:
        st.info("No model bundles were found, so the served configuration is not shown.")
        return
    first = next(iter(bundles.values()))
    versions = {bundle.get("model_version") for bundle in bundles.values()}
    weights = first.get("stacker_weights", {})
    left, right = st.columns(2)
    with left:
        st.markdown(f"**Model version** `{first.get('model_version')}`")
        if len(versions) > 1:
            st.error("The five product bundles do not share one model version. Re-run the training pipeline.")
        elif manifest.get("model_version") and versions != {manifest["model_version"]}:
            st.error(
                "The bundles and the metrics files come from different training runs "
                f"({first.get('model_version')} against {manifest['model_version']}). "
                "The forecast tab uses the bundles; the accuracy numbers use the metrics files."
            )
        st.markdown(f"**Ensemble mode** {first.get('ensemble_mode', 'stacked')}")
        st.markdown(
            "**Stacker weights** "
            + ", ".join(f"{name} {value:g}" for name, value in weights.items())
        )
        st.markdown(
            f"**Refit on** {first.get('training_rows')} days, "
            f"{first.get('training_start')} to {first.get('training_end')}"
        )
    with right:
        st.markdown(f"**Temperature source** {first.get('temperature_source', 'request')}")
        reported = estimate.get("per_sku_rmse", {})
        mismatched = [
            sku for sku, bundle in bundles.items()
            if sku in reported and abs(float(bundle.get("rmse_score", np.nan)) - reported[sku]) > 0.01
        ]
        if mismatched:
            st.error(f"Served rmse_score differs from the reported estimate for: {', '.join(mismatched)}.")
        else:
            st.markdown("**Served rmse_score** matches the reported per-product RMSE.")
        st.markdown(f"**Library versions** " + ", ".join(f"{k} {v}" for k, v in first.get("library_versions", {}).items()))

    with st.expander("How these numbers were produced"):
        protocol = first.get("evaluation_protocol", {})
        for key, value in protocol.items():
            st.markdown(f"- **{key.replace('_', ' ')}**: {value}")
        for note in manifest.get("notes", []):
            st.markdown(f"- {note}")


# ---------------------------------------------------------------- Forecast

def run_forecast(sku: str, horizon: int, context: pd.DataFrame, model_dir: Path) -> pd.DataFrame:
    from serving.inference import predict_sku

    return predict_sku(sku, horizon, context, model_dir=model_dir)


def forecast_tab(history: pd.DataFrame | None, summary: pd.DataFrame, model_dir: Path | None) -> None:
    if history is None:
        st.error(f"The source data file was not found at {data.data_csv_path()}. Set DASHBOARD_DATA_CSV to its location.")
        return
    if model_dir is None:
        st.error("No model bundles were found. Run `uv run python -m training.train_pipeline` from the ml folder.")
        return

    c1, c2, c3 = st.columns([1, 2, 1])
    sku = c1.selectbox("Product", data.SKUS)
    mode = c2.radio("Mode", ["Backtest against actual sales", "Forecast beyond the data"], horizontal=True)
    horizon = c3.slider("Days ahead", 1, 28, 7)

    frame = history[history["product_sku"] == sku].reset_index(drop=True)
    first_day, last_day = frame["date"].min(), frame["date"].max()
    backtest = mode.startswith("Backtest")

    if backtest:
        latest = (last_day - pd.Timedelta(days=horizon)).date()
        earliest = (first_day + pd.Timedelta(days=HISTORY_DAYS)).date()
        start_value = min(latest, max(earliest, (last_day - pd.Timedelta(days=horizon + 28)).date()))
        as_of = pd.Timestamp(
            st.date_input("Forecast starts the day after", value=start_value, min_value=earliest, max_value=latest)
        )
        future_context = None
        st.info(
            "The final models were refit on every day in the data, so a backtest here is in-sample "
            "and looks better than real use. Honest accuracy is on the Overview tab.",
            icon=":material/info:",
        )
    else:
        as_of = last_day
        dates = pd.date_range(last_day + pd.Timedelta(days=1), periods=horizon, freq="D")
        defaults = defaults_from_last_year(frame, dates)
        st.caption(
            f"Sales are known up to {last_day.date()}. Calendar defaults copy the same date last year. "
            "Movable holidays (Easter, Eid) and promotions are not known, so check and edit them."
        )
        future_context = st.data_editor(
            defaults,
            key=f"calendar-{sku}-{horizon}",
            hide_index=True,
            disabled=["date"],
            width="stretch",
            column_config={
                "date": st.column_config.DateColumn("Date", format="ddd D MMM YYYY"),
                "is_promotion": st.column_config.CheckboxColumn("Promotion"),
                "is_public_holiday": st.column_config.CheckboxColumn("Public holiday"),
                "is_school_term": st.column_config.CheckboxColumn("School term"),
            },
        )

    try:
        context, actual = build_context(frame, as_of, horizon, future_context)
        with st.spinner("Forecasting"):
            result = run_forecast(sku, horizon, context, model_dir)
    except Exception as error:  # the dashboard should explain, not crash
        st.error(f"Could not forecast: {error}")
        return

    rmse_known = {}
    for code, days in zip(["h1", "h7", "h14", "h28"], BAND_HORIZONS):
        match = summary[
            (summary["run"] == "final_test") & (summary["model"] == "Prophet")
            & (summary["sku"] == sku) & (summary["horizon"] == code)
        ]
        if not match.empty:
            rmse_known[days] = float(match["RMSE_mean"].iloc[0])
    result = result.copy()
    if rmse_known:
        band = rmse_by_horizon(rmse_known, result["horizon"].to_numpy())
        result["lower"] = np.maximum(result["predicted_units_sold"] - band, 0)
        result["upper"] = result["predicted_units_sold"] + band
    if actual is not None:
        result["actual"] = actual.reindex(pd.to_datetime(result["date"])).to_numpy()

    capacity = float(frame["production_capacity_sku"].iloc[-1]) if "production_capacity_sku" in frame else None
    predicted = result["predicted_units_sold"]
    k1, k2, k3, k4 = st.columns(4)
    k1.metric("Total forecast", f"{predicted.sum():,.0f} units")
    k2.metric("Average per day", f"{predicted.mean():,.0f}")
    k3.metric("Peak day", f"{predicted.max():,.0f}")
    if capacity:
        k4.metric("Peak vs daily capacity", f"{predicted.max() / capacity:.0%}", help=f"Capacity: {capacity:,.0f} units per day")

    shown_history = frame[frame["date"] <= as_of].tail(HISTORY_DAYS)[["date", "units_sold"]]
    result["date"] = pd.to_datetime(result["date"])
    layers = [
        alt.Chart(shown_history).mark_line(color="#64748b").encode(
            x=alt.X("date:T", title=None),
            y=alt.Y("units_sold:Q", title="Units per day", scale=alt.Scale(zero=False)),
        )
    ]
    if "lower" in result:
        layers.append(alt.Chart(result).mark_area(opacity=0.18, color=MODEL_COLORS["Prophet"]).encode(x="date:T", y="lower:Q", y2="upper:Q"))
    layers.append(alt.Chart(result).mark_line(color=MODEL_COLORS["Prophet"], point=True).encode(x="date:T", y="predicted_units_sold:Q"))
    if actual is not None:
        layers.append(alt.Chart(result).mark_line(color="#0f172a", strokeDash=[4, 3]).encode(x="date:T", y="actual:Q"))
    if capacity:
        layers.append(alt.Chart(pd.DataFrame({"y": [capacity]})).mark_rule(color="#dc2626", strokeDash=[6, 4]).encode(y="y:Q"))
    stretch(alt.layer(*layers).properties(height=340))
    legend = "Grey line: recent sales. Blue: forecast"
    if "lower" in result:
        legend += ", shaded band ± the evaluated RMSE at that horizon (interpolated from 1, 7, 14 and 28 days)"
    if actual is not None:
        legend += ". Dashed dark line: what actually sold"
    if capacity:
        legend += ". Red dashed line: daily capacity"
    st.caption(legend + ".")

    if actual is not None:
        scores = score_window(result["actual"].to_numpy(), predicted.to_numpy())
        s1, s2, s3 = st.columns(3)
        s1.metric("Backtest RMSE", fmt(scores["RMSE"]))
        s2.metric("Backtest MAPE", f"{fmt(scores['MAPE'])}%")
        s3.metric("Backtest sMAPE", f"{fmt(scores['sMAPE'])}%")

    table = result.rename(columns={"predicted_units_sold": "forecast"})
    columns = ["date", "horizon", "forecast"] + [c for c in ("lower", "upper", "actual") if c in table]
    table = table[columns].copy()
    table["date"] = table["date"].dt.date
    st.dataframe(table.round(1), hide_index=True, width="stretch")
    st.download_button(
        "Download forecast as CSV",
        table.round(2).to_csv(index=False),
        file_name=f"forecast_{sku}_{as_of.date()}_{horizon}d.csv",
        mime="text/csv",
    )
    st.caption(
        f"Model version {result['model_version'].iloc[0]} · mode {result['ensemble_mode'].iloc[0]} · "
        "temperature uses monthly means, so the supplied temperature is ignored."
    )


# ---------------------------------------------------------------- Models

def models_tab(manifest: dict, summary: pd.DataFrame) -> None:
    runs = [run for run in RUN_LABELS if run in set(summary["run"])]
    c1, c2, c3, c4 = st.columns(4)
    run = c1.selectbox("Run", runs, format_func=lambda r: RUN_LABELS[r])
    in_run = summary[summary["run"] == run]
    modes = sorted(in_run["temperature_mode"].unique())
    mode = c2.selectbox("Temperature input", modes, format_func=lambda m: {"actual": "Actual (best case)", "climatology": "Monthly mean (realistic)"}.get(m, m))
    horizons = [h for h in data.HORIZON_LABELS if h in set(in_run["horizon"])]
    horizon = c3.selectbox("Horizon", horizons, format_func=horizon_label)
    metric = c4.selectbox("Metric", list(METRIC_LABELS), format_func=lambda m: METRIC_LABELS[m])
    sku = st.selectbox("Product", [data.MEAN_ROW, *data.SKUS], format_func=lambda s: "All products (mean)" if s == data.MEAN_ROW else s)

    if run != "final_test":
        st.info("Development runs were used to choose settings, so they are optimistic. Only the final test run is unbiased.", icon=":material/info:")
    frame = in_run[(in_run["temperature_mode"] == mode) & (in_run["horizon"] == horizon) & (in_run["sku"] == sku)].copy()
    if frame.empty:
        st.info("No results for this combination.")
        return
    mean_col, sd_col = f"{metric}_mean", f"{metric}_fold_sd"
    frame["low"] = (frame[mean_col] - frame[sd_col]).clip(lower=0)
    frame["high"] = frame[mean_col] + frame[sd_col]
    order = frame.sort_values(mean_col)["model"].tolist()
    colors = alt.Scale(domain=list(MODEL_COLORS), range=list(MODEL_COLORS.values()))
    base = alt.Chart(frame)
    bars = base.mark_bar().encode(
        y=alt.Y("model:N", sort=order, title=None),
        x=alt.X(f"{mean_col}:Q", title=METRIC_LABELS[metric]),
        color=alt.Color("model:N", scale=colors, legend=None),
        tooltip=["model", alt.Tooltip(mean_col, format=".2f"), alt.Tooltip(sd_col, format=".2f")],
    )
    spread = base.mark_rule(color="#0f172a").encode(y=alt.Y("model:N", sort=order), x="low:Q", x2="high:Q")
    stretch((bars + spread).properties(height=40 * len(frame) + 30))
    st.caption("Bars show the mean over rolling-origin folds. The line shows ± one fold standard deviation.")
    if sku == data.MEAN_ROW and metric == "RMSE":
        st.caption("RMSE averages are dominated by 500ml and 1L. MAPE and sMAPE compare products fairly.")

    table = frame.sort_values(mean_col)[["model", "RMSE_mean", "RMSE_fold_sd", "MAPE_mean", "MAPE_fold_sd", "sMAPE_mean", "sMAPE_fold_sd"]]
    st.dataframe(table.round(2), hide_index=True, width="stretch")


# ---------------------------------------------------------------- Stackers

def stackers_tab() -> None:
    period = st.radio(
        "Evaluation period",
        ["final_test", "development"],
        horizontal=True,
        format_func=lambda p: "Final test (reported)" if p == "final_test" else "Development (used to choose the stacker)",
    )
    files = data.stacker_files(period)
    summary, weights = data.read_csv(files["summary"]), data.read_csv(files["weights"])
    selection = data.read_json(files["selection"])
    if summary is None:
        st.info("No stacker results were found for this period. Run `uv run python -m training.stacking`.")
        return
    if selection:
        st.markdown(f"**Chosen stacker** {selection.get('reported_estimate') or selection.get('selected') or 'see selection file'}")
        if selection.get("note"):
            st.caption(selection["note"])

    c1, c2 = st.columns(2)
    horizon = c1.selectbox("Horizon", [h for h in data.HORIZON_LABELS if h in set(summary["horizon"])], format_func=horizon_label, key="stk-h")
    sku = c2.selectbox("Product", [data.MEAN_ROW, *data.SKUS], format_func=lambda s: "All products (mean)" if s == data.MEAN_ROW else s, key="stk-s")
    frame = summary[(summary["horizon"] == horizon) & (summary["sku"] == sku)].copy()
    if frame.empty:
        st.info("No results for this combination.")
        return

    frame["low"] = frame["RMSE_vs_prophet_mean"] - frame["RMSE_vs_prophet_fold_sd"]
    frame["high"] = frame["RMSE_vs_prophet_mean"] + frame["RMSE_vs_prophet_fold_sd"]
    base = alt.Chart(frame)
    bars = base.mark_bar(color="#2563eb").encode(
        y=alt.Y("variant:N", title=None, sort="-x"),
        x=alt.X("RMSE_vs_prophet_mean:Q", title="RMSE minus Prophet's RMSE (units per day, below 0 is better)"),
        tooltip=["variant", alt.Tooltip("RMSE_vs_prophet_mean", format=".2f")],
    )
    spread = base.mark_rule(color="#0f172a").encode(y=alt.Y("variant:N", sort="-x"), x="low:Q", x2="high:Q")
    zero = alt.Chart(pd.DataFrame({"x": [0]})).mark_rule(color="#94a3b8").encode(x="x:Q")
    stretch((bars + spread + zero).properties(height=40 * len(frame) + 40))
    st.caption("When the spread crosses zero, the variant is not clearly different from Prophet alone.")

    table = frame[["variant", "RMSE_mean", "RMSE_fold_sd", "RMSE_vs_prophet_mean", "RMSE_vs_prophet_fold_sd", "MAPE_mean", "MAPE_vs_prophet_mean"]]
    st.dataframe(table.round(2), hide_index=True, width="stretch")

    if weights is not None:
        st.subheader("Stacker weights")
        w = weights[weights["horizon"] == horizon]
        if sku != data.MEAN_ROW:
            w = w[w["sku"] == sku]
        grouped = w.groupby("variant")[["w_prophet", "w_xgboost", "w_lstm", "intercept", "weight_sum"]].agg(["mean", "std"])
        grouped.columns = [f"{name} ({stat})" for name, stat in grouped.columns]
        st.dataframe(grouped.round(3).reset_index(), hide_index=True, width="stretch")
        st.caption("Weights are averaged over folds (and seeds). A large spread, a weight sum far from 1 or a big intercept means the stacker is unstable.")


# ---------------------------------------------------------------- Folds

def folds_tab(folds: pd.DataFrame | None) -> None:
    if folds is None:
        st.info("The per-fold metrics file was not found.")
        return
    reported = folds[folds["run"] == "final_test"]
    c1, c2, c3 = st.columns(3)
    model = c1.selectbox("Model", sorted(reported["model"].unique()), index=sorted(reported["model"].unique()).index("Prophet") if "Prophet" in set(reported["model"]) else 0)
    horizon = c2.selectbox("Horizon", [h for h in data.HORIZON_LABELS if h in set(reported["horizon"])], format_func=horizon_label, key="fold-h")
    metric = c3.selectbox("Metric", list(METRIC_LABELS), format_func=lambda m: METRIC_LABELS[m], key="fold-m")
    frame = reported[(reported["model"] == model) & (reported["horizon"] == horizon)]
    averaged = frame.groupby(["sku", "fold", "fold_origin"], as_index=False)[metric].mean()
    chart = alt.Chart(averaged).mark_line(point=True).encode(
        x=alt.X("fold_origin:N", title="Fold origin (forecasts start the next day)"),
        y=alt.Y(f"{metric}:Q", title=METRIC_LABELS[metric]),
        color=alt.Color("sku:N", title="Product"),
        tooltip=["sku", "fold_origin", alt.Tooltip(metric, format=".2f")],
    )
    stretch(chart.properties(height=340))
    st.caption("Each point is one rolling-origin fold on the final test window. Seeded models are averaged over seeds.")
    pivot = averaged.pivot(index="sku", columns="fold_origin", values=metric).round(2)
    st.dataframe(pivot, width="stretch")


# ---------------------------------------------------------------- Page

def main() -> None:
    artifacts = data.locate()
    st.title("Demand forecasting")
    st.caption("Per-product models for the water bottling platform. Reads the saved bundles and metrics directly.")

    with st.sidebar:
        st.markdown("**Artifacts**")
        st.caption(f"Metrics: {artifacts.metrics_dir or 'not found'}")
        st.caption(f"Model bundles: {artifacts.model_dir or 'not found'}")
        st.caption(f"Source data: {data.data_csv_path()}")
        if st.button("Reload files"):
            st.cache_data.clear()
            st.rerun()

    if artifacts.metrics_dir is None:
        st.error(
            "No metrics were found. From the ml folder run `uv run python -m training.train_pipeline`, "
            "or set ML_MODEL_DIR to the folder that holds metrics_manifest.json."
        )
        st.stop()

    manifest = data.read_json(artifacts.metrics_dir / "metrics_manifest.json")
    summary = data.read_csv(artifacts.metrics_dir / "forecast_metrics_summary.csv")
    folds = data.read_csv(artifacts.metrics_dir / "forecast_metrics.csv")
    if manifest is None or summary is None:
        st.error("The metrics files are incomplete. Re-run the training pipeline.")
        st.stop()
    bundles = data.sku_manifests(artifacts.model_dir)

    tabs = st.tabs(["Overview", "Forecast", "Models", "Stackers", "Folds"])
    with tabs[0]:
        overview_tab(manifest, summary, bundles)
    with tabs[1]:
        forecast_tab(data.read_history(), summary, artifacts.model_dir)
    with tabs[2]:
        models_tab(manifest, summary)
    with tabs[3]:
        stackers_tab()
    with tabs[4]:
        folds_tab(folds)


main()
