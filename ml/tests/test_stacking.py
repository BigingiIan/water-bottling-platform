import numpy as np
import pandas as pd

from training.stacking import (
    STACK_FEATURE_COLUMNS,
    fit_convex_weights,
    fit_variant,
    training_rows,
)


def test_convex_weights_are_non_negative_sum_to_one_and_recover_a_true_mix() -> None:
    rng = np.random.default_rng(0)
    features = rng.uniform(50, 150, size=(300, 3))
    target = features @ np.array([0.7, 0.3, 0.0]) + rng.normal(0, 1, 300)

    weights = fit_convex_weights(features, target)

    assert np.all(weights >= 0)
    assert np.isclose(weights.sum(), 1.0)
    np.testing.assert_allclose(weights, [0.7, 0.3, 0.0], atol=0.02)


def test_convex_weights_stay_constrained_when_least_squares_would_not() -> None:
    features = np.column_stack([np.arange(1, 51.0), np.arange(1, 51.0) * 2, np.ones(50)])
    target = features[:, 0] * 3

    weights = fit_convex_weights(features, target)

    assert np.all(weights >= 0)
    assert np.isclose(weights.sum(), 1.0)


def test_stacker_training_rows_never_include_targets_after_the_fold_origin() -> None:
    rows = pd.DataFrame(
        {"target_date": pd.date_range("2025-01-01", periods=10), "actual": np.arange(10.0)}
    )

    train = training_rows(rows, pd.Timestamp("2025-01-05"))

    assert train["target_date"].max() == pd.Timestamp("2025-01-05")
    assert len(train) == 5


def test_fixed_variants_and_api_style_prediction() -> None:
    features = pd.DataFrame([[100.0, 120.0, 80.0]], columns=STACK_FEATURE_COLUMNS)

    assert fit_variant("prophet_only", features, [0]).predict(features)[0] == 100.0
    assert fit_variant("prophet_xgb_mean", features, [0]).predict(features)[0] == 110.0
