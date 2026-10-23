"""Collect every evaluation run into one metrics file for the dashboard.

Writes, next to the SKU bundles:

- forecast_metrics.csv: one row per run, SKU, model, seed, fold and horizon;
- forecast_metrics_summary.csv: mean, fold SD and seed SD per run, SKU, model
  and horizon, plus a "Mean of SKUs" row (the simple mean across SKUs per fold);
- metrics_manifest.json: what each run is, which one is final_test, and which
  rows are the reported estimate.

Every metric comes from the pre-refit rolling-origin evaluation. The refitted
serving models have seen all data, so they have no held-out score of their own.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import pandas as pd

METRIC_COLUMNS = ("RMSE", "MAPE", "sMAPE")
MEAN_OF_SKUS = "Mean of SKUs"
RUNS: tuple[dict[str, str], ...] = (
    {
        "run": "development_baseline",
        "period": "development",
        "directory": "baseline",
        "file": "fold_metrics.csv",
        "configuration": "Original hyperparameters for every model.",
    },
    {
        "run": "development_tuned",
        "period": "development",
        "directory": "tuned",
        "file": "fold_metrics.csv",
        "configuration": "Every model tuned per fold on an inner window before the fold origin.",
    },
    {
        "run": "development_stacking",
        "period": "development",
        "directory": "stacking",
        "file": "stacker_fold_metrics.csv",
        "configuration": "Stacker variants on Prophet (default), XGBoost (tuned), LSTM (default).",
    },
    {
        "run": "final_test",
        "period": "final_test",
        "directory": "final_test",
        "file": "fold_metrics.csv",
        "configuration": "Final configuration: Prophet and LSTM default, XGBoost nested-tuned.",
    },
    {
        "run": "final_test_stacking",
        "period": "final_test",
        "directory": "stacking_final_test",
        "file": "stacker_fold_metrics.csv",
        "configuration": "Stacker variants on final_test; Prophet alone was chosen beforehand.",
    },
)
REPORTED_ESTIMATE = {
    "run": "final_test",
    "model": "Prophet",
    "horizon": "h1",
    "temperature_mode": "climatology",
}
FOLD_ORIGIN_SOURCES = {"development": "baseline", "final_test": "final_test"}


def _fold_origins(evaluation_dir: Path) -> pd.DataFrame:
    frames = [pd.DataFrame(columns=["sku", "fold", "fold_origin", "period"])]
    for period, directory in FOLD_ORIGIN_SOURCES.items():
        path = evaluation_dir / directory / "predictions.csv.gz"
        if not path.is_file():
            continue
        predictions = pd.read_csv(path, usecols=["sku", "fold", "fold_origin"])
        origins = predictions.drop_duplicates(["sku", "fold"]).assign(period=period)
        frames.append(origins)

    return pd.concat(frames, ignore_index=True)


def available_runs(evaluation_dir: Path) -> list[dict[str, str]]:
    """The runs whose metrics file exists; final_test must be one of them."""
    runs = [spec for spec in RUNS if (evaluation_dir / spec["directory"] / spec["file"]).is_file()]
    if REPORTED_ESTIMATE["run"] not in {spec["run"] for spec in runs}:
        raise FileNotFoundError(f"No final_test metrics under {evaluation_dir}.")

    return runs


def collect_fold_metrics(evaluation_dir: Path) -> pd.DataFrame:
    """Every available run's per-fold metrics in one long table."""
    frames = []
    for spec in available_runs(evaluation_dir):
        metrics = pd.read_csv(evaluation_dir / spec["directory"] / spec["file"])
        if "variant" in metrics:
            metrics = metrics.rename(columns={"variant": "model"}).assign(temperature_mode="climatology")
        frames.append(
            metrics[["temperature_mode", "sku", "model", "seed", "fold", "horizon", *METRIC_COLUMNS]].assign(
                run=spec["run"],
                period=spec["period"],
                is_final_test=spec["period"] == "final_test",
                configuration=spec["configuration"],
            )
        )

    combined = pd.concat(frames, ignore_index=True).merge(
        _fold_origins(evaluation_dir), on=["period", "sku", "fold"], how="left"
    )
    combined["is_reported_estimate"] = (
        (combined["run"] == REPORTED_ESTIMATE["run"])
        & (combined["model"] == REPORTED_ESTIMATE["model"])
        & (combined["horizon"] == REPORTED_ESTIMATE["horizon"])
        & (combined["temperature_mode"] == REPORTED_ESTIMATE["temperature_mode"])
    )
    columns = [
        "run",
        "period",
        "is_final_test",
        "is_reported_estimate",
        "configuration",
        "temperature_mode",
        "sku",
        "model",
        "seed",
        "fold",
        "fold_origin",
        "horizon",
        *METRIC_COLUMNS,
    ]
    return combined[columns]


def summarise_fold_metrics(fold_metrics: pd.DataFrame) -> pd.DataFrame:
    """Mean and spread per run, SKU, model and horizon; seeds are averaged within folds first."""
    labels = ["run", "period", "is_final_test", "is_reported_estimate", "configuration", "temperature_mode"]
    keys = [*labels, "model", "horizon", "sku", "fold"]
    per_fold = fold_metrics.groupby(keys)[list(METRIC_COLUMNS)].mean()
    seed_sd = fold_metrics.groupby(keys)[list(METRIC_COLUMNS)].std(ddof=1)

    all_skus = per_fold.groupby([*labels, "model", "horizon", "fold"]).mean().assign(sku=MEAN_OF_SKUS)
    per_fold = pd.concat([per_fold, all_skus.set_index("sku", append=True).reorder_levels(keys)])

    group = [*labels, "model", "horizon", "sku"]
    summary = per_fold.groupby(group).agg(["mean", "std", "count"])
    summary.columns = [
        f"{metric}_{'fold_sd' if stat == 'std' else stat}" for metric, stat in summary.columns
    ]
    summary = summary.rename(columns={"RMSE_count": "folds"}).drop(columns=["MAPE_count", "sMAPE_count"])
    seed_spread = seed_sd.groupby(group).mean()
    for metric in METRIC_COLUMNS:
        summary[f"{metric}_seed_sd"] = seed_spread[metric]

    return summary.reset_index()


def write_metrics(evaluation_dir: Path, output_dir: Path, model_version: str) -> dict[str, Any]:
    fold_metrics = collect_fold_metrics(evaluation_dir)
    summary = summarise_fold_metrics(fold_metrics)
    output_dir.mkdir(parents=True, exist_ok=True)
    fold_metrics.to_csv(output_dir / "forecast_metrics.csv", index=False)
    summary.to_csv(output_dir / "forecast_metrics_summary.csv", index=False)

    reported = summary[summary["is_reported_estimate"]].set_index("sku")
    manifest = {
        "model_version": model_version,
        "created_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "files": {
            "fold_metrics": "forecast_metrics.csv",
            "summary": "forecast_metrics_summary.csv",
        },
        "runs": [
            {key: spec[key] for key in ("run", "period", "configuration")}
            for spec in available_runs(evaluation_dir)
        ],
        "final_test_runs": [
            spec["run"] for spec in available_runs(evaluation_dir) if spec["period"] == "final_test"
        ],
        "reported_estimate": {
            **REPORTED_ESTIMATE,
            "per_sku_rmse": {
                sku: round(float(row["RMSE_mean"]), 4)
                for sku, row in reported.iterrows()
                if sku != MEAN_OF_SKUS
            },
            "mean_of_skus": {
                f"{metric}_{stat}": round(float(reported.loc[MEAN_OF_SKUS, f"{metric}_{stat}"]), 4)
                for metric in METRIC_COLUMNS
                for stat in ("mean", "fold_sd")
            },
        },
        "metrics": {
            "RMSE": "units/day; per-horizon RMSE averaged over the horizons in the bucket",
            "MAPE": "percent",
            "sMAPE": "percent",
        },
        "notes": [
            "All metrics come from pre-refit models evaluated with rolling origins.",
            "The data is synthetic (generator seed 42) and its structure suits Prophet; "
            "these are not real-world accuracy estimates.",
        ],
    }
    (output_dir / "metrics_manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    return manifest
