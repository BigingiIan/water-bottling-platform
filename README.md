# Integrated Water Bottling Operations Platform

Final Year Project (U-CS 106), Strathmore University.

AquaOps is an internal operations platform for Kenyan water bottling SMEs. It brings production, inventory, orders, invoicing and revenue into one role-based system, adds request-based communication between departments, and uses a machine learning service for daily demand forecasting. Prophet, XGBoost, an LSTM and stacked combinations were evaluated; Prophet alone is served because it scored best on the held-out test.

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
- [Scheduled commands](#scheduled-commands)
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
└────────────┬─────────────┘   GET /health   │  model bundles           │
             │                               └──────────────────────────┘
             ▼
        MySQL database                        Streamlit dashboard (optional)
                                              reads the same bundles and metrics
```

- **Laravel owns the data.** It assembles each forecast request (recent sales history plus date features) from its own database and sends it to the service.
- **The prediction service is stateless.** It only loads trained models and scores the features it receives. There is no cache and no independent data refresh.
- **Forecasts fail honestly.** If artifacts, history or features are missing, the service returns an error and the UI shows the specific reason the forecast is unavailable, never a placeholder number.

## Tech stack

| Layer | Technology |
|---|---|
| Web backend | PHP 8.3+, Laravel 13, Livewire 4 |
| Database | MySQL (SQLite in memory for tests) |
| Frontend | Blade, Livewire, Alpine (bundled with Livewire), Tailwind CSS 4, Vite, Heroicons |
| Charts | Inline SVG Blade components (no JavaScript chart library) |
| Documents | barryvdh/laravel-dompdf for invoice PDFs |
| Prediction service | FastAPI (Python 3.11+, managed with `uv`) |
| ML | Prophet, XGBoost, TensorFlow/Keras (LSTM), scikit-learn |
| Model dashboard | Streamlit |

## Features

Access is controlled per module. Each role has one of three levels per module: `none` (the page returns 403), `view` (read only, writes return 403) or `full`. The sidebar, dashboard and buttons only show what the signed-in role can use.

### Access control and staff
- Five seeded roles (Administrator, Production Manager, Production Staff, Inventory Staff, Maintenance Staff) plus custom roles.
- Twelve modules: Dashboard, Production, Inventory, Orders, Equipment, Forecasting, Invoicing, Revenue, Requests, Users & Roles, Settings, Error Logs.
- **Staff Directory** (`/staff`):
  - Search and filters by role, status and module, plus a CSV export that respects the filters.
  - A row menu to change role, reset a password, or suspend and reactivate.
  - A side panel per user with role, status, last active and recent activity.
- **Roles & Permissions** (`/staff/permissions`):
  - Role cards with user and module counts.
  - A matrix with a None / View / Full control per cell. Changes are staged and saved together after a confirmation summary.
  - The Administrator role can never be reduced, and system roles can't be deleted.
- Admin-created accounts only, with email invitations and a signed set-password link. Administrators can "View As" another user.
- Branded 403, 404 and 500 pages. 500 pages never show exception details.

### Dashboard
- **Widgets:** built from a registry, and only for modules the user can access. Nothing renders and no query runs for a widget the user can't see.
- **Role defaults:** each role type has a default layout. Users can show, hide and reorder widgets with "Customise", and the layout is saved per user.
- **Needs your attention:** the most urgent items across permitted modules:
  - out-of-stock, critical and low stock
  - leakage reports at investigate level
  - orders that can't be dispatched
  - overdue invoices
  - unread requests
- **Quick actions:** shown only for actions the user can perform.
- **Refresh:** widgets refresh every 60 seconds.

### Production
- Schedule (`/production/schedule`), production runs with logging and completion, and history.
- Process-loss checks run when a run is completed.

### Inventory
- Stock overview (`/inventory`) with per-product cover against the forecast, warehouse stock lines, batches, leakage reports and stock movements.
- `StockService` records every change as a stock movement. Stock counts, adjustments and stock-in are recorded through it.
- Leakage detection grades reports as Review or Investigate, from manual entries, batch checks and stock counts.

### Orders, invoicing and revenue
- **Orders** (`/orders`): draft, confirm (reserves stock), dispatch (deducts stock oldest batch first), deliver, or cancel. Order numbers run sequentially per year.
- **Invoicing** (`/invoices`):
  - Invoices are raised from orders, with VAT, payments and overdue tracking.
  - Issued invoices are locked; a mistake is corrected by voiding and reissuing.
  - Each invoice has a PDF.
- **Revenue** (`/revenue`): invoiced, collected and outstanding amounts.

### Requests
Request-based communication lets a user ask another department to act without being given access to that department's module. Requests aren't a chat: each one has an owner, a status and an optional link to a record.

- **Visibility:** you see requests you sent and requests addressed to your role. Administrators see every request, and nobody sees other departments' threads.
- **Sending:** "Send request" appears on order detail, production runs, batches, leakage reports and stock lines, and "New request" on the Requests page.
- **Snapshot of the record:**
  - Captured on the server from a fixed set of safe fields (for an order: customer, items, status and requested date). Prices are never included.
  - A sender can only attach a record from a module they can view.
  - Recipients always see the snapshot. They get a link to the live record only if they can view it.
- **Status:** only the receiving department or an administrator moves a request through Acknowledged, In progress, Done or Declined. Declining needs a reason.
- **What the sender can do:** comment, cancel while the request is open, and reopen a declined request once.
- **Access requests:** "Request Access" on the 403 page sends an access request to administrators with the user's role and the page they tried to open. Approving it means changing the role in Roles & Permissions; nothing is granted automatically.
- **Notifications:**
  - **New request:** to the department.
  - **Status change:** to the sender.
  - **Comments:** to the other side of the thread.
  - **Overdue:** a daily reminder, sent once per request.
- **Badge:** the sidebar shows the number of open requests.

### Forecasting
- The forecasting page (`/forecasting`) shows 30 days of history against stored forecasts, tomorrow's cover per SKU, and why a forecast is unavailable when it is.
- The footer shows a forecast-service status dot to roles with Forecasting access. It is checked at most once a minute and never delays a page.

### Settings, notifications and audit
- **Settings** (`/settings`): organisation details, thresholds and defaults such as `requests.default_due_days`, plus the audit trail.
- **In-app notifications:** stock, leakage, overdue invoices, large payments, errors and requests. Users can switch off some alert types in their profile.
- **Audit log:** records settings changes, user and role actions, invoices, payments and requests.

## Getting started

### Prerequisites
- PHP 8.3 or later and Composer
- Node.js (20.19+ or 22.12+, as required by Vite 8) and npm
- MySQL
- Python 3.11 or later and [`uv`](https://docs.astral.sh/uv/) for the prediction service

### Laravel application

```bash
git clone https://github.com/BigingiIan/water-bottling-platform.git
cd water-bottling-platform

composer install
cp .env.example .env
php artisan key:generate

# Configure DB_* values in .env, then:
php artisan migrate --seed

npm install
npm run build

composer run dev        # or: php artisan serve, plus npm run dev
```

`composer run setup` does the install, key, migrate and build steps in one go, but doesn't seed.

The database seeder creates roles, modules, permissions, production lines and daily SKU features. In `local` and `testing` it also creates the [test accounts](#test-accounts). Optional demo data, for local use only:

```bash
php artisan db:seed --class=DemoDataSeeder          # inventory demo data
php artisan db:seed --class=ForecastingDemoSeeder   # 60 days of sales history from data/kenya_water_demand_daily.csv
```

Useful `.env` keys:

| Key | Purpose |
|---|---|
| `ML_FORECAST_URL` | Prediction service URL (default `http://127.0.0.1:8001`) |
| `ML_FORECAST_TIMEOUT` | Request timeout in seconds (default 3) |
| `ML_MODEL_DIR` | Model bundles Laravel reads manifests from (default `ml/outputs/saved_models`) |
| `ML_DASHBOARD_URL` | Link to the Streamlit dashboard from the forecasting page |
| `APP_VERSION` | Version shown in the footer. Set it at deploy time, for example from `git describe --tags --abbrev=0`. Defaults to `0.1.0`. |

Set `APP_DEBUG=false` for anything other than local development. Run `php artisan schedule:work` locally so the [scheduled commands](#scheduled-commands) fire.

### Prediction service

From the repository root, in PowerShell:

```powershell
$env:ML_MODEL_DIR = "$PWD\ml\outputs\saved_models"
uv run --directory ml uvicorn serving.api:app --app-dir . --host 0.0.0.0 --port 8001
```

Set `ML_MODEL_DIR` explicitly. If you don't, the service falls back to `ml/saved_models`, which holds an older stacked bundle. Laravel reads its manifests from `ml/outputs/saved_models`, the current Prophet-only bundle written by the training pipeline, so both sides should point there. Training, evaluation and the bundle format are documented in [`ml/README.md`](ml/README.md).

The optional model dashboard:

```powershell
cd ml
uv run streamlit run dashboard/app.py
```

## Test accounts

`TestUserSeeder` creates one account per role. It only runs in `local` and `testing` environments, and every account uses the password `password`.

| Role | Email |
|---|---|
| Administrator | `admin@aquaops.test` |
| Production Manager | `prodmgr@aquaops.test` |
| Production Staff | `prodstaff@aquaops.test` |
| Inventory Staff | `inventory@aquaops.test` |
| Maintenance Staff | `maintenance@aquaops.test` |

Never run this seeder against a shared or production database.

## Machine learning

### Models evaluated

Per SKU, against a seasonal-naive baseline:

- **Prophet:** trend and seasonality, with promotion, public holiday, school term and temperature regressors.
- **XGBoost:** lag and rolling-window features, with tuned hyperparameters.
- **LSTM:** 14-day lookback.
- **Stacked combinations** of the three: convex weights, the mean of Prophet and XGBoost, and unconstrained least squares.

**Prophet alone was chosen as the served configuration before the final test.** The stackers did not beat it: the best gave Prophet almost all of the weight. The trainer refits on every row and writes stacker weights of `[1, 0, 0]`, so only Prophet is loaded and run at inference time. XGBoost and the LSTM are still fitted and saved for comparison.

### Data

Training uses a synthetic dataset (`data/kenya_water_demand_daily.csv`, fixed seed, reproducible):

- 5 SKUs: 500ml, 1L, 5L, 10L, 20L
- Daily records from 2023-01-01 to 2025-12-31
- Features: promotion flag, Kenyan public holidays, school terms, temperature, plus weekly and yearly seasonality, growth trend, and random demand shocks

### Evaluation protocol

- Rolling-origin evaluation per SKU, with 6 folds and horizons from 1 to 28 days.
- Development folds are used for tuning and model selection. The `final_test` period is run once, after the configuration is chosen.
- Temperature uses monthly means, since daily temperature isn't known in advance.
- Metrics: RMSE, MAPE and sMAPE, per SKU and averaged, with fold standard deviations.

### Results

Final-test results, averaged over the five SKUs (one-day-ahead forecasts, 6 folds):

| Model | RMSE | MAPE | sMAPE |
|---|---:|---:|---:|
| **Prophet (served)** | **121.3** | **12.86%** | **12.36%** |
| Stacker, convex weights | 121.4 | 12.73% | 12.33% |
| Stacker, unconstrained least squares | 121.4 | 12.80% | 12.35% |
| Mean of Prophet and XGBoost | 123.9 | 12.71% | 12.49% |
| XGBoost (tuned) | 134.1 | 13.40% | 13.49% |
| LSTM | 155.2 | 19.90% | 18.70% |
| Seasonal naive | 188.9 | 19.39% | 18.71% |

Averaged over 1 to 28 days ahead, Prophet's RMSE is 122.2 and its MAPE is 12.69%. These numbers come from `ml/outputs/saved_models/forecast_metrics_summary.csv`, which is regenerated by the training pipeline.

### Known limitations

- The model is trained on synthetic data. Real-world accuracy depends on capturing operational sales data.
- The LSTM underperforms, most likely because each SKU has only a few hundred training rows.
- Eid holidays in the synthetic generator use hardcoded approximate dates.

## Forecast integration

`POST /predict` returns a prediction with `model_version` and `rmse_score`, and `GET /health` reports reachability and the model version served per SKU.

How Laravel builds a request:

- **History:** daily `units_sold` from `sales_transactions.quantity`, joined through `warehouse_stock` to `products`. A missing day counts as zero **only** if that SKU and date are marked `sales_data_covered`; otherwise the history is incomplete.
- **Requirement:** 14 consecutive covered history days plus complete features.
- **Features:** read from `daily_sku_features` (`is_promotion`, `is_public_holiday`, `is_school_term`, `temperature_c`).
- **Persistence:** results are upserted into `forecast_records`, keyed by product, forecast date and model version, so re-renders never create duplicates.

Each SKU needs a model bundle with a manifest (`model_version` and `rmse_score`). Until a SKU has both, its forecast stays unavailable.

## Scheduled commands

All run in the operations timezone (the `organisation.timezone` setting, default Africa/Nairobi). The scheduler must be running (`php artisan schedule:work` locally, or a cron entry for `schedule:run` in production).

| Command | When | What it does |
|---|---|---|
| `errors:prune` | daily | Deletes error events past the configured retention period |
| `sales:close-day` | 17:45 | Marks the day's sales complete for forecasting, once no order dated that day is still open |
| `forecasts:generate` | 18:00 | Generates tomorrow's forecasts |
| `invoices:notify-overdue` | 08:00 | Notifies invoicing users about each overdue invoice, once |
| `requests:notify-overdue` | 08:05 | Notifies the receiving department about each overdue request, once |

## Repository layout

```
app/
  Console/Commands/         Scheduled commands (forecasts, sales day, overdue invoices and requests)
  Http/Controllers/         Login, password set, View As, access requests, invoice PDFs
  Livewire/                 Pages and modals; Dashboard/ holds the dashboard widgets
  Models/                   Eloquent models
  Services/                 Domain services: Dashboard, Forecasting, Inventory, Invoicing, Leakage,
                            Orders, Requests, Revenue, plus MLForecastClient, NotificationService, SettingsService
  Support/                  Shared helpers: ModuleAccess, YearlySequence, SettingsDate, dashboard registry
config/settings.php         Setting definitions (defaults, validation, Settings tabs)
data/                       Synthetic demand dataset
database/
  migrations/
  seeders/                  Roles, modules, permissions, lines, features, test users, demo data
resources/views/            Blade and Livewire views, shared components, branded error pages
design-reference/           HTML design mockups
model_reference/            Original training notebook (reference only)
ml/                         Training, prediction service, dashboard and ML tests (see ml/README.md)
tests/                      Feature and unit tests
```

## Testing

```bash
php artisan test                 # Laravel suite (in-memory SQLite)
cd ml && uv run pytest           # prediction service and dashboard
```

The Laravel suite covers access control at every boundary, including per-module permissions, request visibility and snapshots, widget gating and fixed query counts.

## Project status

| Area | Status |
|---|---|
| RBAC (roles, permission matrix, enforcement, login, invitations, View As, error pages) | Complete |
| Staff Directory and Roles & Permissions | Complete |
| Role-aware dashboard (widgets, customise, attention list, quick actions) | Complete |
| Production schedule, runs and history | Complete |
| Inventory (stock, batches, leakage detection, stock counts, movements) | Complete |
| Orders | Complete |
| Invoicing, payments and invoice PDFs | Complete |
| Revenue | Complete |
| Requests between departments | Complete |
| Settings, notifications and audit log | Complete |
| Prediction service, training pipeline and Laravel client | Complete (Prophet served) |
| Equipment / maintenance module | Planned (the module exists for permissions only) |
| Reports | Planned (sidebar placeholder) |
| Promotion and temperature data sources for live use | Pending. A source isn't chosen yet. |
| Help guide (`/help`) | Planned. The footer link appears once the route exists. |

### Known limitations
- `sales_data_covered` defaults to `false`. `sales:close-day` marks a day covered once no order dated that day is still open, and forecasts need 14 consecutive covered days.
- School terms are a documented month-based approximation, and Eid dates aren't inferred.
- Invoices are internal only: there's no eTIMS/KRA integration, no credit notes (corrections are void then reissue), and invoices aren't emailed.
- Revenue days are grouped in UTC, not Nairobi local time.
- Below the `lg` breakpoint the sidebar becomes a slide-out menu, and wide tables scroll horizontally inside their cards.
- In a hidden browser tab, dashboard polling slows down (Livewire throttles it) rather than stopping completely.
