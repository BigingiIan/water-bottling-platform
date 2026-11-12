# ML forecast service

Evaluate, then refit and export the per-SKU models, from the `ml` directory:

```powershell
uv run python -m training.rolling_origin --output outputs/rolling_origin/baseline
uv run python -m training.tuning
uv run python -m training.stacking
uv run python -m training.tuning --period final_test --tuned-models xgboost `
  --models naive prophet xgboost lstm --output outputs/rolling_origin/final_test
uv run python -m training.stacking --period final_test
uv run python -m training.train_pipeline
```

The input CSV (default `..\data\kenya_water_demand_daily.csv`) must contain
`date`, `product_sku`, `units_sold`, `is_promotion`, `is_public_holiday`,
`is_school_term`, and `temperature_c`. Evaluation uses rolling origins with
monthly-mean temperature; `--period final_test` is meant to run once. The
trainer refits the chosen configuration (Prophet alone, stacker weights
`[1, 0, 0]`) on every row, still fits and saves XGBoost and the LSTM, and sets
`rmse_score` to Prophet's final_test h=1 RMSE. `metadata.json` and an identical
`manifest.json` are written, as are `meta_stacker.joblib` and
`meta_learner.joblib`. Next to the SKU directories it writes
`forecast_metrics.csv`, `forecast_metrics_summary.csv` and
`metrics_manifest.json` (every run per SKU, model and fold, with the final_test
runs flagged) for a dashboard.

All inference lives in `serving/inference.py`:
`predict_sku(sku, horizon_days, context_df)` returns a DataFrame and has no
web-framework code. Models whose stacker weight is exactly zero are neither
loaded nor run.

The service defaults to `ml/outputs/saved_models` (resolved from the `ml`
directory, not the working directory), the same default Laravel uses; set
`ML_MODEL_DIR` only if the artifacts are stored elsewhere:

```powershell
$env:ML_MODEL_DIR = "C:\path\to\water-bottling-platform\ml\outputs\saved_models"
uv run --directory ml uvicorn serving.api:app --app-dir . --host 127.0.0.1 --port 8001
```

A SKU whose artifacts fail to load is logged and skipped; the other SKUs are
still served, and `GET /health` lists the skipped ones under `failed`.

## Running on a host

- Install the locked dependencies with `uv sync --frozen --no-dev`
  (`requirements.txt` is generated from `uv.lock` for pip-only environments).
- Set `ML_MODEL_DIR` to the bundle directory and `ML_API_TOKEN` to a long random
  secret. With the token set, every endpoint requires
  `Authorization: Bearer <token>` and answers 401 otherwise; set the same value
  as `ML_FORECAST_TOKEN` in Laravel's `.env`. Without it the service logs a
  warning at startup and accepts unauthenticated requests (local development
  only).
- Run a single uvicorn worker (`--workers 1`, the default): every worker loads
  every model into memory.
- Bind to `127.0.0.1`, or to a private-network address when Laravel runs on
  another machine, and never expose port 8001 publicly.
- The Streamlit dashboard has no authentication. `.streamlit/config.toml` binds
  it to `127.0.0.1`; do not expose it, and leave Laravel's `ML_DASHBOARD_URL`
  unset in production.

Set `ML_MODEL_DIR` to a directory containing one subdirectory per SKU. Each
SKU directory must contain:

- `prophet.joblib`: fitted Prophet model, or a fitted notebook wrapper exposing
  `.model` and optionally `.active_regressors`.
- `xgboost.joblib`: fitted XGBoost regressor, or a fitted notebook wrapper
  exposing `.model` and `.feature_cols`.
- `lstm.keras`: fitted Keras model.
- `lstm_scaler.joblib`: fitted scaler for `units_sold`, `is_promotion`,
  `is_public_holiday`, `is_school_term`, and `temperature_c`.
- `meta_learner.joblib`: fitted stacking estimator, or fitted wrapper exposing
  `.model`.
- `manifest.json`: required `model_version` (the training run's identifier,
  such as its ISO date) and `rmse_score` (the SKU's stacked final-test RMSE),
  plus optional `prophet_regressors`, `xgboost_feature_cols`, and
  `lstm_lookback` overrides. The default LSTM lookback is 14 days.

Bundles whose manifest has `stacker_weights` are served through `predict_sku()`.
They use the monthly temperature means stored in the manifest, so the request's
`temperature_c` values are accepted but not used. Older bundles keep the
original loader and use the request's temperatures.

`POST /predict` accepts `sku`, ISO `date`, the target day's four context
features, and an ordered `history` of at least 14 consecutive daily rows. Each
history row includes `date`, `units_sold`, and all four context features. The
response contains `predicted_units_sold`, `model_version`, and `rmse_score`.
The service loads model artifacts at startup and keeps no request data or
prediction records.

Laravel sources historical unit sales from `sales_transactions` and all daily
context from `daily_sku_features`. The `sales_data_covered` flag must be set
only after the transaction source confirms that a SKU/date is fully ingested.
A missing sales row is interpreted as zero only for a date explicitly marked
covered. A prediction remains unavailable until all 14 historical dates are
covered and have complete features, and the target date has complete context.
Forecast results are upserted by product, forecast date, and model version
into `forecast_records`, with a one-day horizon.

Promotion management and the source for `temperature_c` remain open business
decisions. The feature seeder leaves both fields null; it does not fabricate
values. The synthetic data generator referenced for Kenyan public holidays
and school terms was not present in this checkout. The current seeder marks
fixed-date national holidays and Good Friday/Easter Monday, and uses a
month-level term approximation (January-March, May-July, September-November)
until the generator's exact calendar functions are available. Islamic holidays
are not inferred because their observed dates are announced separately.

## Dashboard

A Streamlit dashboard reads the saved bundles and metrics files directly and
calls `predict_sku()` in-process, so there is no HTTP API in between. From the
`ml` directory:

```powershell
uv run streamlit run dashboard/app.py
```

Tabs: Overview (reported accuracy and what is being served), Forecast
(backtest against actual sales, or forecast beyond the data with an editable
calendar), Models (comparison against the seasonal naive baseline, with fold
spread), Stackers (variants and weights) and Folds (per-fold results).

The dashboard looks for `metrics_manifest.json` in `ML_MODEL_DIR`, then
`saved_models`, then `outputs/saved_models`, and uses the bundles that sit next
to the metrics it found. Set `DASHBOARD_DATA_CSV` if the source data is not at
`..\data\kenya_water_demand_daily.csv`. The Forecast tab always shows
"backtest" results as in-sample, because the final models are refit on every
day in the data; the honest accuracy is the final_test estimate on Overview.

`uv run pytest tests/test_dashboard_utils.py tests/test_dashboard_app.py`
checks the forecast helpers and that every tab renders against the saved
artifacts.
