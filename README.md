# Integrated Water Bottling Operations Platform

Final Year Project (U-CS 106), Strathmore University.

An internal operations platform for Kenyan water bottling SMEs, combining production scheduling, inventory and sales tracking, role-based access control, and an ensemble machine learning model (Prophet + XGBoost + LSTM, stacked) for daily demand forecasting.

> **Status:** work in progress. See [Project status](#project-status) for what is built, what is pending, and known limitations.

---

## Contents

- [Architecture](#architecture)
- [Tech stack](#tech-stack)
- [Features](#features)
- [Getting started](#getting-started)
- [Test accounts](#test-accounts)
- [Machine learning](#machine-learning)
- [Forecast integration](#forecast-integration)
- [Repository layout](#repository-layout)
- [Testing](#testing)
- [Project status](#project-status)

---

## Architecture

```
┌──────────────────────────┐   HTTP (JSON)   ┌──────────────────────────┐
│  Laravel web application │ ──────────────► │  FastAPI prediction      │
│  Livewire UI, RBAC, DB   │  POST /predict  │  service (stateless)     │
│  MLForecastClient        │ ◄────────────── │  loads saved per-SKU     │
└────────────┬─────────────┘                 │  model artifacts         │
             │                               └──────────────────────────┘
             ▼
        MySQL database
```

- **Laravel owns the data.** It assembles each forecast request (recent sales history plus date features) from its own database and sends it to the service.
- **The prediction service is stateless.** It only loads trained models and scores whatever feature vector it receives. There is no cache and no independent data refresh.
- **Forecasts fail honestly.** If artifacts, history, or features are missing, the service returns an error and the UI shows "forecast unavailable" rather than a placeholder number.

## Tech stack

| Layer | Technology |
|---|---|
| Web backend | Laravel, Livewire |
| Database | MySQL |
| Prediction service | FastAPI (Python) |
| ML | Prophet, XGBoost, TensorFlow/Keras (LSTM), scikit-learn |
| Frontend | Blade, Livewire, Chart.js |
| Model development | Google Colab notebook |

## Features

### Access control (RBAC): complete
- Five roles: Administrator, Production Manager, Production Staff, Inventory Staff, Maintenance Staff
- Eight modules, each with a three-state access level per role: `none`, `view`, `full`
- Staff directory (`/staff`) with search and role/status/module filters
- Live permission matrix (`/staff/permissions`): clicking a cell cycles `none → view → full` and persists immediately
- Enforcement: `none` blocks page load (403), `view` allows reading but blocks writes (403), `full` allows both
- Admin-created accounts only (no self-registration), login per user
- Role-aware sidebar: users only see modules they can access
- Admin "View As" for testing other roles' access
- Branded 403, 404 and 500 error pages. 500 pages never render exception details.

### Production: in progress
- Production schedule page with date-scoped KPIs, line/SKU/status filters, and run detail
- "Log Production" modal that writes `production_logs` entries for the authenticated operator, gated by Production-module `full` access
- Forecast strip showing the ML-suggested quantity for the next active SKU, or a clear unavailable state

### Inventory: in progress
- Inventory overview page (`/inventory`) with stock levels, filtering, and batch management, gated by the Inventory module
- `StockService` records every change as a `stock_movements` entry and derives stock levels and status checks from them
- Leakage reports are flagged automatically when the discrepancy exceeds a threshold (`LeakageReportFlag`)

### User invitations
- Administrators invite staff by email (`UserInvitation` mailable); invitees set their own password via a signed link

### Planned
Orders views, quality, maintenance, reports, and a dedicated forecasting page.

## Getting started

### Prerequisites
- PHP and Composer (TODO: confirm version)
- Node.js and npm
- MySQL
- Python 3.10+ (TODO: confirm) for the prediction service

### Laravel application

```bash
git clone <repo-url>        # TODO: add repo URL
cd <repo-folder>

composer install
cp .env.example .env
php artisan key:generate

# Configure DB_* values in .env, then:
php artisan migrate --seed

npm install
npm run build

php artisan serve
```

Seeders create roles, modules, role-module permissions, and the production lines. Set `APP_DEBUG=false` for anything other than local development.

### Prediction service

```bash
cd <path-to-fastapi-service>   # TODO: confirm path
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn api:app --reload --port 8001   # TODO: confirm module name and port
```

Point Laravel at the service via the relevant `.env` key (TODO: document the exact variable name).

## Test accounts

`TestUserSeeder` creates one account per role. It only runs in `local` and `testing` environments.

| Role | Email |
|---|---|
| Administrator | `admin@aquaops.test` |
| Production Manager | `prodmgr@aquaops.test` |
| Production Staff | `prodstaff@aquaops.test` |
| Inventory Staff | `inventory@aquaops.test` |
| Maintenance Staff | `maintenance@aquaops.test` |

The development password is set in the seeder. Never run this seeder against a shared or production database.

## Machine learning

### Model

A meta-learner stacking ensemble, trained per SKU:

1. **Base models:** Prophet (trend and seasonality with promotion, holiday, school-term and temperature regressors), XGBoost (lag and rolling features), and an LSTM (14-day lookback)
2. **Meta-learner:** linear regression over the three base predictions

The notebook is kept in `model_reference/` as the reference for training and validation. It is a development artifact, not a deployable component.

### Data

Training uses a synthetic dataset (fixed seed, reproducible):

- 5 SKUs: 500ml, 1L, 5L, 10L, 20L
- Daily records from 2023-01-01 to 2025-12-31
- Features: promotion flag, Kenyan public holidays, school terms, temperature, plus weekly and yearly seasonality, growth trend, and random demand shocks

### Evaluation protocol

- Chronological split per SKU: **60% base-train / 20% meta-train / 20% final-test**
- Base models are fit on base-train. The meta-learner is fit on base-model predictions over meta-train. All reported metrics come from final-test.
- Lag and lookback history is carried across split boundaries so no rows are lost or leaked.
- Metrics: RMSE, MAPE, sMAPE, reported per SKU and averaged

### Results

<!-- TODO: refresh from a full run of the refactored notebook before publishing numbers -->

Results will be added after the refactored notebook is run end to end. An earlier run showed the stacked ensemble narrowly beating Prophet (the strongest single model) on the average row, with LSTM weakest. The meta-learner coefficients should be checked to see how much weight each base model receives.

### Known limitations

- Prophet's yearly seasonality is disabled automatically when a training slice has under 730 days of history, which applies to the base-train split.
- LSTM likely underperforms because each SKU has only about 650 training rows.
- Eid holidays in the synthetic generator use hardcoded approximate dates.
- The model was trained on synthetic data. Real-world accuracy depends on operational data being captured.

## Forecast integration

`POST /predict` returns a stacked ensemble prediction along with `model_version` and `rmse_score`.

How Laravel builds a request:

- **History:** daily `units_sold` from `sales_transactions.quantity`, joined through `warehouse_stock` to `products`. A missing day is treated as zero **only** if that SKU/date is marked `sales_data_covered`; otherwise the history is considered incomplete.
- **Requirement:** 14 consecutive covered history days plus complete features.
- **Features:** read from `daily_sku_features` (`is_promotion`, `is_public_holiday`, `is_school_term`, `temperature_c`).
- **Persistence:** successful results are upserted into `forecast_records`, keyed by product, forecast date, and model version, so re-renders never create duplicates.

Each SKU needs an artifact bundle plus a manifest (`metadata.json` with `model_version` and `rmse_score`). Until a SKU has both, its forecast stays unavailable.

## Repository layout

<!-- TODO: adjust to match the actual repo -->

```
app/
  Livewire/                 Livewire components (staff, permissions, production schedule, inventory)
  Services/MLForecastClient.php
  Services/Inventory/       StockService (stock movements and calculations)
  Support/                  Helpers such as LeakageReportFlag
  Mail/                     UserInvitation mailable
  Exceptions/Inventory/     Domain exceptions for stock operations
database/
  migrations/
  seeders/                  Roles, modules, permissions, production lines, test users
resources/views/            Blade and Livewire views, branded error pages
design-reference/           HTML design mockups (RBAC, production, inventory, error states)
model_reference/            Training notebook (reference for ML work)
ml/                         Training code, prediction service, and ML tests (see ml/README.md)
tests/
```

## Testing

```bash
php artisan test            # Laravel suite
# FastAPI suite: TODO add command (e.g. pytest from the service directory)
```

Inventory coverage lives in `InventoryOverviewTest`, `Services/Inventory/StockServiceTest` and `Unit/Support/LeakageReportFlagTest`.

## Project status

| Area | Status |
|---|---|
| RBAC (schema, matrix, enforcement, login, view-as, error pages) | Complete |
| Production schedule and log production | Wired to real data |
| User invitations by email | Complete |
| Prediction service and Laravel client | Built, tested |
| `train_pipeline.py` and saved model artifacts | **Pending.** Real predictions need this. |
| Promotion and temperature data sources | **Pending.** Source not yet chosen. |
| Specific forecast-unavailable reasons in the UI | Optional polish (the client returns only a nullable float today) |
| Inventory overview and stock movements | Built, tested |
| Orders, quality, maintenance, reports modules | Planned |

### Known limitations
- `sales_data_covered` defaults to `false`; an ingestion process must mark dates covered only after confirming transaction data is complete.
- School terms are a documented month-based approximation, and Eid dates are not inferred.
- "Request Access" on the 403 page is a placeholder, not a workflow.