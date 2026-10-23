"""Time-based comparison of stacker variants on out-of-fold base predictions.

Run from the ml directory, after rolling_origin (baseline) and tuning (tuned):

    uv run python -m training.stacking

Inputs are the rolling-origin forecasts, so every base prediction is out of
sample and every target date lies inside meta_train. Base models: Prophet
(default, refitted at each forecast origin) and LSTM (default) from the
baseline run, XGBoost (nested-tuned) from the tuning run, all with the realistic
monthly-mean temperature. XGBoost and LSTM seeds are paired, giving three
stacking replicates.

Validation: for evaluation fold k (k >= 1), each stacker is fitted only on rows
whose target date is on or before fold k's origin, then scored on fold k.
Fold 0 is training-only. Stackers are fitted per SKU and per horizon bucket:
on h=1 rows for the h=1 score, and on all horizons pooled for the h1-28 score.

Selection rule, fixed before results were seen: take the variant with the
lowest mean-of-SKUs validation RMSE at h=1, then fall back to the simplest
variant whose fold-paired gap to it is within the fold SD of that gap.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd
from scipy.optimize import minimize
from sklearn.linear_model import LinearRegression

from training.forecasting import STACK_FEATURE_COLUMNS, LinearStacker

BASE_MODEL_COLUMNS = {"Prophet": "prophet_pred", "XGBoost": "xgboost_pred", "LSTM": "lstm_pred"}
VARIANTS = {
    "prophet_only": "(c) Prophet alone",
    "prophet_xgb_mean": "(b) Mean of Prophet and XGBoost",
    "convex": "(a) Convex weights (>= 0, sum 1, no intercept)",
    "ols": "(d) Unconstrained OLS with intercept (current)",
}
REFERENCE_MODELS = {"xgboost_only": "XGBoost alone", "lstm_only": "LSTM alone"}
BUCKETS = {"h1": (1,), "h1-28 mean": tuple(range(1, 29))}
SELECTION_BUCKET = "h1"


def fit_convex_weights(features: np.ndarray, target: np.ndarray) -> np.ndarray:
    """Least-squares weights constrained to be non-negative and sum to one."""
    count = features.shape[1]
    scale = float(np.mean(target ** 2)) or 1.0

    def loss(weights: np.ndarray) -> float:
        return float(np.mean((features @ weights - target) ** 2) / scale)

    def gradient(weights: np.ndarray) -> np.ndarray:
        return 2 * features.T @ (features @ weights - target) / len(target) / scale

    result = minimize(
        loss,
        np.full(count, 1 / count),
        jac=gradient,
        method="SLSQP",
        bounds=[(0.0, 1.0)] * count,
        constraints=[{"type": "eq", "fun": lambda weights: weights.sum() - 1.0}],
        options={"ftol": 1e-12, "maxiter": 500},
    )
    if not result.success:
        raise RuntimeError(f"Convex stacker did not converge: {result.message}")

    weights = np.clip(result.x, 0.0, None)
    return weights / weights.sum()


def fit_variant(variant: str, features: pd.DataFrame, target: pd.Series) -> LinearStacker:
    values = features[list(STACK_FEATURE_COLUMNS)].to_numpy(dtype=float)
    actual = np.asarray(target, dtype=float)

    if variant == "prophet_only":
        return LinearStacker([1.0, 0.0, 0.0])
    if variant == "prophet_xgb_mean":
        return LinearStacker([0.5, 0.5, 0.0])
    if variant == "xgboost_only":
        return LinearStacker([0.0, 1.0, 0.0])
    if variant == "lstm_only":
        return LinearStacker([0.0, 0.0, 1.0])
    if variant == "convex":
        return LinearStacker(fit_convex_weights(values, actual))
    if variant == "ols":
        regression = LinearRegression().fit(values, actual)
        return LinearStacker(regression.coef_, regression.intercept_)

    raise ValueError(f"Unknown stacker variant: {variant}")


def load_out_of_fold(sources: dict[str, list[Path]], temperature_mode: str) -> pd.DataFrame:
    """One row per SKU, seed, origin and horizon with all three base predictions.

    `sources` maps each base model name to the run directories its predictions
    are read from; runs written before the period column existed are development.
    """
    dates = ["fold_origin", "origin_date", "target_date"]
    keys = ["period", "sku", "fold", "fold_origin", "origin_date", "target_date", "horizon", "actual"]
    frames: dict[Path, pd.DataFrame] = {}

    def select(model: str, column: str, with_seed: bool) -> pd.DataFrame:
        columns = [*keys, "seed"] if with_seed else keys
        parts = []
        for directory in sources[model]:
            if directory not in frames:
                frame = pd.read_csv(directory / "predictions.csv.gz", parse_dates=dates)
                if "period" not in frame:
                    frame["period"] = "development"
                frames[directory] = frame
            frame = frames[directory]
            rows = frame[(frame["temperature_mode"] == temperature_mode) & (frame["model"] == model)]
            parts.append(rows[[*columns, "predicted"]].rename(columns={"predicted": column}))
        return pd.concat(parts, ignore_index=True)

    merged = (
        select("LSTM", "lstm_pred", with_seed=True)
        .merge(select("XGBoost", "xgboost_pred", with_seed=True), on=[*keys, "seed"])
        .merge(select("Prophet", "prophet_pred", with_seed=False), on=keys)
    )
    if merged.empty:
        raise ValueError("No overlapping out-of-fold predictions were found.")

    return merged


def bucket_metrics(test: pd.DataFrame, predictions: np.ndarray) -> dict[str, float]:
    """Per-horizon RMSE, MAPE and sMAPE averaged over horizons, as in rolling_origin."""
    errors = test.assign(predicted=predictions)
    errors["squared_error"] = (errors["actual"] - errors["predicted"]) ** 2
    errors["ape"] = (errors["actual"] - errors["predicted"]).abs() / errors["actual"] * 100
    errors["sape"] = (
        (errors["actual"] - errors["predicted"]).abs()
        / ((errors["actual"].abs() + errors["predicted"].abs()) / 2).clip(lower=1e-8)
        * 100
    )
    by_horizon = errors.groupby("horizon")[["squared_error", "ape", "sape"]].mean()
    return {
        "RMSE": float(np.sqrt(by_horizon["squared_error"]).mean()),
        "MAPE": float(by_horizon["ape"].mean()),
        "sMAPE": float(by_horizon["sape"].mean()),
    }


def training_rows(bucket_rows: pd.DataFrame, fold_origin: pd.Timestamp) -> pd.DataFrame:
    """Rows a stacker may learn from when it is scored on the fold starting at fold_origin."""
    return bucket_rows[bucket_rows["target_date"] <= fold_origin]


def evaluate_stackers(
    out_of_fold: pd.DataFrame,
    eval_period: str = "development",
) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Score every variant on each fold of `eval_period`.

    Training rows may come from any period, but only those whose target date is
    on or before the scored fold's origin. The first development fold has no
    such rows and is skipped.
    """
    metric_rows, weight_rows = [], []
    variants = {**VARIANTS, **REFERENCE_MODELS}

    for (sku, seed), rows in out_of_fold.groupby(["sku", "seed"], sort=True):
        fold_origins = rows.groupby(["period", "fold"])["fold_origin"].first().sort_values()
        scored = [key for key in fold_origins.index if key[0] == eval_period]
        if eval_period == "development":
            scored = scored[1:]
        for bucket, horizons in BUCKETS.items():
            bucket_rows = rows[rows["horizon"].isin(horizons)]
            for period, fold in scored:
                train = training_rows(bucket_rows, fold_origins[(period, fold)])
                test = bucket_rows[(bucket_rows["period"] == period) & (bucket_rows["fold"] == fold)]
                for variant, label in variants.items():
                    stacker = fit_variant(variant, train, train["actual"])
                    labels = {"sku": sku, "seed": seed, "horizon": bucket, "fold": fold, "variant": label}
                    metric_rows.append(
                        {**labels, "train_rows": len(train), **bucket_metrics(test, stacker.predict(test))}
                    )
                    if variant in VARIANTS:
                        weight_rows.append(
                            {
                                **labels,
                                **dict(zip(("w_prophet", "w_xgboost", "w_lstm"), stacker.coef_)),
                                "intercept": stacker.intercept_,
                                "weight_sum": float(stacker.coef_.sum()),
                            }
                        )

    return pd.DataFrame(metric_rows), pd.DataFrame(weight_rows)


def paired_summary(metrics: pd.DataFrame) -> pd.DataFrame:
    """Mean and fold SD per variant, plus the fold-paired difference to Prophet alone."""
    per_fold = metrics.groupby(["horizon", "variant", "sku", "fold"])[["RMSE", "MAPE", "sMAPE"]].mean()
    seed_sd = metrics.groupby(["horizon", "variant", "sku", "fold"])["RMSE"].std(ddof=1)
    all_skus = per_fold.groupby(["horizon", "variant", "fold"]).mean()
    all_skus["sku"] = "Mean of SKUs"
    per_fold = pd.concat([per_fold, all_skus.set_index("sku", append=True).reorder_levels(per_fold.index.names)])
    per_fold = per_fold.reset_index()

    prophet = per_fold[per_fold["variant"] == VARIANTS["prophet_only"]].drop(columns="variant")
    joined = per_fold.merge(prophet, on=["horizon", "sku", "fold"], suffixes=("", "_prophet"))
    for metric in ("RMSE", "MAPE", "sMAPE"):
        joined[f"{metric}_vs_prophet"] = joined[metric] - joined[f"{metric}_prophet"]

    columns = ["RMSE", "MAPE", "sMAPE", "RMSE_vs_prophet", "MAPE_vs_prophet", "sMAPE_vs_prophet"]
    summary = joined.groupby(["horizon", "variant", "sku"])[columns].agg(["mean", "std"])
    summary.columns = [f"{column}_{'fold_sd' if stat == 'std' else stat}" for column, stat in summary.columns]
    summary["RMSE_seed_sd"] = seed_sd.groupby(["horizon", "variant", "sku"]).mean()
    return summary.reset_index(), per_fold


def select_variant(per_fold: pd.DataFrame) -> dict[str, object]:
    """Apply the selection rule from the module docstring to mean-of-SKUs folds."""
    rows = per_fold[(per_fold["horizon"] == SELECTION_BUCKET) & (per_fold["sku"] == "Mean of SKUs")]
    rmse = rows.pivot(index="fold", columns="variant", values="RMSE")[list(VARIANTS.values())]
    best = rmse.mean().idxmin()
    checks = []
    for label in VARIANTS.values():
        gap = rmse[label] - rmse[best]
        checks.append(
            {"variant": label, "gap_mean": float(gap.mean()), "gap_fold_sd": float(gap.std(ddof=1))}
        )

    chosen = next(
        check["variant"]
        for check in checks
        if check["variant"] == best or abs(check["gap_mean"]) <= check["gap_fold_sd"]
    )
    return {"lowest_validation_rmse": best, "chosen": chosen, "gaps_to_lowest": checks}


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    ml_root = Path(__file__).resolve().parents[1]
    outputs = ml_root / "outputs" / "rolling_origin"
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--baseline", type=Path, default=outputs / "baseline")
    parser.add_argument("--tuned", type=Path, default=outputs / "tuned")
    parser.add_argument("--final", type=Path, default=outputs / "final_test")
    parser.add_argument("--output", type=Path, default=None)
    parser.add_argument("--temperature-mode", default="climatology")
    parser.add_argument("--period", choices=("development", "final_test"), default="development")
    args = parser.parse_args()

    development_dir = outputs / "stacking"
    output = args.output or (development_dir if args.period == "development" else outputs / "stacking_final_test")
    sources = {"Prophet": [args.baseline], "LSTM": [args.baseline], "XGBoost": [args.tuned]}
    if args.period == "final_test":
        sources = {model: [*directories, args.final] for model, directories in sources.items()}

    out_of_fold = load_out_of_fold(sources, args.temperature_mode)
    metrics, weights = evaluate_stackers(out_of_fold, args.period)
    summary, per_fold = paired_summary(metrics)
    if args.period == "development":
        selection = select_variant(per_fold)
    else:
        selection = {
            "reported_estimate": json.loads(
                (development_dir / "stacker_selection.json").read_text(encoding="utf-8")
            )["chosen"],
            "note": "Chosen on development folds before final_test was scored; not re-selected here.",
        }

    output.mkdir(parents=True, exist_ok=True)
    metrics.to_csv(output / "stacker_fold_metrics.csv", index=False)
    weights.to_csv(output / "stacker_weights.csv", index=False)
    summary.to_csv(output / "stacker_summary.csv", index=False)
    (output / "stacker_selection.json").write_text(json.dumps(selection, indent=2) + "\n")

    def cell(row: pd.Series, column: str) -> str:
        return f"{row[f'{column}_mean']:.1f} ± {row[f'{column}_fold_sd']:.1f}"

    for bucket in BUCKETS:
        rows = summary[summary["horizon"] == bucket]
        for column in ("RMSE", "RMSE_vs_prophet", "MAPE"):
            table = rows.assign(value=rows.apply(cell, axis=1, column=column)).pivot(
                index="variant", columns="sku", values="value"
            )
            folds = metrics["fold"].nunique()
            print(f"\n{bucket} | {column} mean ± fold SD ({folds} {args.period} folds)")
            print(table.to_string())

    weight_summary = (
        weights[weights["horizon"] == SELECTION_BUCKET]
        .groupby(["variant", "sku"])[["w_prophet", "w_xgboost", "w_lstm", "intercept", "weight_sum"]]
        .agg(["mean", "std"])
        .round(3)
    )
    print("\nh1 stacker weights, mean and SD across folds and seeds")
    print(weight_summary.loc[[VARIANTS["convex"], VARIANTS["ols"]]].to_string())
    print("\nSelection:", json.dumps(selection, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
