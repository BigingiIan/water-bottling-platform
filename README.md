# Integrated Water Bottling Operations Platform

AquaOps is an internal operations platform for Kenyan water bottling SMEs. It brings production, inventory, orders, invoicing and revenue into one role-based system, adds request-based communication between departments, and uses a machine learning service for daily demand forecasting. Prophet, XGBoost, an LSTM and stacked combinations were evaluated; Prophet alone is served because it scored best on the held-out test.

> **Status:** work in progress. See [Project status](#project-status) for what is built, what is pending, and known limitations.

---

## Contents

- [Architecture](#architecture)
- [Tech stack](#tech-stack)
- [Features](#features)
- [Getting started](#getting-started)
- [Deploying to a host](#deploying-to-a-host)
- [Test accounts](#test-accounts)
- [Demo data](#demo-data)
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
| Web backend | PHP 8.4+, Laravel 13, Livewire 4 |
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
- Thirteen modules: Dashboard, Production, Inventory, Orders, Maintenance, Forecasting, Business Calendar, Invoicing, Revenue, Requests, Users & Roles, Settings, Error Logs.
- **Staff Directory** (`/staff`):
  - Search and filters by role, status and module, plus a CSV export that respects the filters.
  - A row menu to change role, reset a password, or suspend and reactivate.
  - A side panel per user with role, status, last active and recent activity.
- **Roles & Permissions** (`/staff/permissions`):
  - Role cards with user and module counts.
  - A matrix with a None / View / Full control per cell. Changes are staged and saved together after a confirmation summary.
  - The Administrator role can never be reduced, and system roles can't be deleted.
- Admin-created accounts only. An invited user is emailed a temporary password (not a link), valid for 72 hours, and must set their own password at first sign-in. If it expires, an administrator issues a new one with Reset Password.
- Only Administrators can invite, promote, demote, reset or suspend Administrators.
- Administrators can "View As" another user; starting and ending it is recorded in the audit log.
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

### Equipment and maintenance
- **Equipment** (`/maintenance`): every machine with its type, line, location and status (operational, needs attention, under maintenance, out of service).
  - The list shows a status pill, the next preventive task and 30-day downtime.
  - Each machine's page shows its preventive plans, its work-order history, and downtime over 30 and 90 days.
- **Preventive maintenance plans:** a task repeated every N days, with a checklist and the role that does it. The next due date is stored, and is recomputed from the last completion.
- **Work orders** (`WO-YYYY-0001`): preventive, corrective or inspection, from a plan, a request or a breakdown report.
  - **Board:** Open, In progress and Done (last 30 days), with filters.
  - **Each work order:** has its checklist, start and complete actions, downtime, parts and cost.
- **Completing a preventive work order** reschedules its plan from the completion date.
- **Returning equipment to service:** if the equipment is down, the person completing the work order is asked whether it is working again. The status is never changed for them.
- **Daily generator:** `maintenance:generate-work-orders` creates one work order per plan due within two days, once per due date, and reminds the plan's role about overdue plans.
- **Production schedule:**
  - **Line status:** each line's worst equipment status and its 30-day downtime.
  - **Warning:** scheduling a run on a line with equipment under maintenance or out of service shows a warning, but doesn't block it.
- **Requests:** production can send Maintenance a request about a machine. Maintenance turns it into a corrective work order with one click, which moves the request to In progress.
- **Notifications:** work order assigned, plan overdue, and equipment out of service (to production roles).
- **Maintenance calendar:** overdue tasks, then the next eight weeks of due dates, including projected repeats.
- **Access:** Maintenance Staff and administrators have Full access, production roles View, others none.

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
- **Calendar chips:** each product's card shows "Promotion planned" and "Public holiday" chips for the next seven days they affect.
- **Temperature source:** the page shows where the temperature values came from.
- **School-term warning:** the page warns when the next 30 days fall in a year with no school terms recorded.

### Business Calendar
The Business Calendar (`/calendar`) is the single place to manage the forecast's context features: `is_promotion`, `is_public_holiday`, `is_school_term` and `temperature_c` in `daily_sku_features`.

- **Public holidays:** recurring holidays (same month and day every year) or one-off dates.
  - Only Kenya's eight fixed-date holidays are seeded.
  - Movable holidays such as Good Friday, Easter Monday and Eid are entered each year once announced, so nothing is guessed.
- **School terms:** year, term (1–3) and dates, with none seeded for future years. A day outside every recorded term counts as a school holiday.
- **Promotions:** a promotion runs for a date range on one product or all products, with a type and an optional discount.
  - Its status (planned, active or ended) is computed from the dates.
  - Promotions only set the forecast flag and never change order prices.
- **Views:** a month view shows holidays, terms and promotions as labelled, coloured bands, and each has a list with add, edit and delete.
- **Rebuilding the features:** every change rebuilds the features for exactly the dates it touches (`ContextCalendar`), and `calendar:rebuild` runs nightly for the next 30 days.
- **Protected history:** days already marked `sales_data_covered` are history the model has learned from. Changing them needs Full access and a reason, and is recorded in the audit log.
- **Access:** administrators and production managers have Full access; every other role has View.
- The footer shows a forecast-service status dot to roles with Forecasting access. It is checked at most once a minute and never delays a page.

### Landing page and sign-in
- **Public landing page (`/`):** the organisation's name and logo, one line on what the console does, a Sign in button, and four feature tiles: Production, Inventory, Orders, Forecasting.
  - **Nothing private:** it shows no counts or business data.
  - **Small:** no images apart from the logo, about 22 KB in all.
  - **Signed-in users:** they skip it and go straight to their landing page (the dashboard unless they chose another).
- **Logo:** uploaded in Settings → General (PNG, JPG or WebP, up to 256 KB and 1024 × 1024). SVG isn't accepted. It's stored privately and served from `/branding/logo`. Without one, the AquaOps mark is used.
- **Sign-in transition:** after signing in, once per session, the first page shows the logo and "Preparing your dashboard" with a gentle animation.
  - **Duration:** it ends when the page has loaded, or is fully gone by 800 ms, whichever comes first.
  - **Not on navigation:** it never appears on normal page navigation.
  - **Skipped:** entirely when the device asks for reduced motion, or when **Reduce motion** is on in the profile. That profile setting also turns off the app's other animations.

### Navigation and layout
- **Icon rail:** the sidebar collapses to a 64px icon rail.
  - **Toggle:** the button at the foot of the sidebar, or press `[`.
  - **In the rail:** labels appear as tooltips on hover and keyboard focus, the current page stays highlighted, and badges sit on the icons.
- **Navigation groups:** each group (Production, Inventory, Maintenance, Finance, Analytics, System) collapses on its own. A collapsed group still shows the page you're on.
- **Saved per user:** both the rail and the group states are saved in your preferences, not the browser, so they follow you to other devices. The server renders them on the first paint, so nothing shifts on load.
- **Below 1024px:** the sidebar becomes a drawer opened from the menu button in the header. Focus stays inside it while it's open; Esc, the close button or the backdrop closes it and returns focus to the menu button. Content uses the full width.
- **Wide tables:** they scroll inside their own container, and the page itself never scrolls sideways.

### Appearance
- **Theme modes:** Light, Dark or System. System follows the device's own light or dark setting.
- **Colour profiles:** Ocean (the AquaOps blue, default), Forest, Slate, Sunset and High contrast. Each one defines light and dark values for the same design tokens: background, surfaces, borders, text, accent, status colours, sidebar and chart series.
- **Who decides:** administrators set the organisation default in Settings → Appearance, where a preview strip shows every profile in light and dark. Anyone can override it in My Profile → Preferences, or pick "Organisation default" to follow it again.
- **No flash:** the theme is chosen on the server and written into the page head before the first paint. System mode needs no script, because the browser picks between the two token sets itself.
- **Status colours stay recognisable:** success is always green, warning amber, danger red and info blue. Only their shade changes between profiles.
- **Checked for contrast:** every text pair meets WCAG AA (4.5:1) and every control, border and chart series meets 3:1, in every profile and mode.
- **Kept fixed on purpose:** the invoice document (PDF and on screen) stays black on white like paper, the invitation email keeps its own colours, and the error pages always use Ocean light because they work without the database or the asset build.

### Help and onboarding
- **Help centre** (`/help`): linked from the footer, the profile menu and each page title.
  - Shows only the guides for modules your role can open, plus "Getting started" and "Asking another department for something".
  - Each guide covers what the page is for, 3 to 6 numbered task guides, what View and Full let you do, and common problems.
  - **What you can do:** generated from your role's permissions, so it always matches what you can open.
  - **Search:** covers only the guides you can see.
- **Page-title help:** a **?** next to each page title opens a two-sentence explanation and a link to the matching guide. It only appears for modules your role can open.
- **Welcome tour:** 5 to 7 steps on your first page after setting a password, covering the sidebar, requests, notifications, the profile menu, the dashboard and Help.
  - Steps for areas your role can't see are skipped.
  - Back, Next, Skip and Esc work from the keyboard, and on phones the tour shows as a bottom sheet.
  - It runs once. "Replay the welcome tour" is on the Help page and in My Profile.
- **Empty states:** empty lists say what belongs there, and offer the action that fills them only when you're allowed to take it.
- **Where the text lives:** guides are Markdown files in `resources/help/`, and the rest of the interface text is in `lang/en/help.php`.

### Settings, notifications and audit
- **Settings** (`/settings`): organisation details, thresholds and defaults such as `requests.default_due_days`, plus the audit trail.
- **In-app notifications:** stock, leakage, overdue invoices, large payments, errors and requests. Users can switch off some alert types in their profile.
- **Audit log:** records settings changes, user and role actions, invoices, payments and requests.

## Getting started

### Prerequisites
- PHP 8.4 or later with the `bcmath` extension, and Composer
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

The database seeder creates roles, modules, permissions, production lines, the fixed-date public holidays and daily SKU features. In `local` and `testing` it also creates the [test accounts](#test-accounts).

To see every page with a year of believable data, load the [demo business](#demo-data) (local and testing only):

```bash
php artisan demo:seed --fresh    # rebuilds the database, then about 30 s for 365 days
php artisan demo:verify          # checks the data hangs together
php artisan demo:clear           # removes the demo rows again
```

The older `DemoDataSeeder` and `ForecastingDemoSeeder` still work for a small inventory demo and 60 days of sales history.

On a database migrated before the Business Calendar existed, seed the fixed holidays and fill the next 30 days once:

```bash
php artisan migrate
php artisan db:seed --class=PublicHolidaySeeder
php artisan calendar:rebuild
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
uv run --directory ml uvicorn serving.api:app --app-dir . --host 127.0.0.1 --port 8001
```

Both the service and Laravel default to `ml/outputs/saved_models`, the current Prophet-only bundle written by the training pipeline. Set `ML_MODEL_DIR` only to point both somewhere else. Training, evaluation and the bundle format are documented in [`ml/README.md`](ml/README.md).

The optional model dashboard:

```powershell
cd ml
uv run streamlit run dashboard/app.py
```

## Deploying to a host

The app needs PHP 8.4+ with `bcmath`, MySQL, Node for the asset build, a cron entry for the scheduler, and (for forecasts) the prediction service on a private address.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force        # roles, modules, permissions, product catalogue, production lines
php artisan app:create-admin       # first administrator; the password is typed at a hidden prompt
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- **Environment:** start from `.env.example` and apply its "Production" block: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES=*` when the host terminates TLS in front of the app.
- **Mail:** set a real `MAIL_MAILER`. Never leave `MAIL_MAILER=log` on a host: invitation emails contain a temporary password and would be written to the log file.
- **Scheduler:** run `php artisan schedule:run` every minute from cron. Without it there is no day close, so forecasts never become ready, and no preventive work orders or overdue reminders.
- **Test and demo data:** `TestUserSeeder` and the `demo:*` commands refuse to run outside `local` and `testing`.
- **Prediction service:** install with `uv sync --frozen`, run one uvicorn worker bound to localhost or a private network, and set `ML_API_TOKEN` on the service to the same value as Laravel's `ML_FORECAST_TOKEN`. Never expose port 8001 or the Streamlit dashboard publicly; leave `ML_DASHBOARD_URL` unset in production. See [`ml/README.md`](ml/README.md).
- **Model files:** `ml/outputs/saved_models` (about 6 MB) is no longer git-ignored, so commit it for the host to receive the models.
- **Logo storage:** the organisation logo is stored on the `local` disk. On a host with ephemeral storage set `BRANDING_DISK` to a persistent disk, or the logo is lost on redeploy.
- **Security headers:** every page sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` and `X-Robots-Tag: noindex`, plus HSTS over HTTPS. There is no Content-Security-Policy yet.
- **Health check:** `GET /up`.

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

## Demo data

`php artisan demo:seed {--days=365} {--customers=40} {--fresh}` builds a business that has been trading for a year. Everything in it is consistent:

- **Sales:** `data/kenya_water_demand_daily.csv` replayed for the last `--days` days (ending yesterday), plus today as a closed trading day. Each SKU's daily units are split into orders across invented retailers, distributors, offices and schools. Schools buy in term time, offices on weekdays. Orders are dispatched oldest stock first and delivered the same day, with sales transactions and stock movements.
- **Production:** one run and batch per SKU each morning, sized to cover the day's sales plus a buffer, with production logs and intake and bottled volumes.
  - **Normal process loss** is 2 to 3%.
  - **Flagged batches:** five trip the review threshold and two trip investigate, graded by the real `LeakageDetector`.
  - **Drift:** 1L loss creeps up over the last ten days until the anomaly check flags it.
- **Inventory:** weekly Saturday counts, mostly exact, a few bottles short now and then, and one count that trips review. Stock never goes negative and `level_after` chains on every line. At the end 5L is low and 10L is critical, so every status shows.
- **Invoices and payments:**
  - **Invoiced:** about 90% of orders, with VAT.
  - **Paid by:** M-Pesa, cash and bank transfer, with realistic-looking but fake references.
  - **Overdue:** about 10% of recent invoices, some part paid, across all four aging buckets.
- **Calendar:** the fixed holidays, school terms for the period, and eight promotions: past ones, one running now and one planned.
- **Maintenance:** 15 machines across both lines and the treatment plant, with 17 preventive plans worked through the year.
  - **Breakdowns:** with downtime, parts and cost.
  - **Now:** the Line 02 labeller is out of service and one plan is overdue.
- **Around it:** requests between departments in every status, notifications, audit log entries and a few error events.
- **Close and forecast:** after the data is written, `sales:close-day` runs for every day and `forecasts:generate` for tomorrow. If the prediction service isn't running, the forecasts are skipped. This evening's open orders are added after the close, so today still counts as forecast history.

Generation is deterministic: a fixed Faker seed gives the same data for the same day, so screenshots are reproducible. Rows are bulk-inserted with explicit ids, and each table's demo id range is recorded in `demo_data_ranges`, which is what `demo:clear` removes. Products, roles, production lines and holidays are kept.

Without `--fresh`, the command replaces earlier demo data. It refuses to run if the database already has orders or stock that aren't demo data. Both `demo:seed` and `demo:clear` refuse to run outside `local` and `testing`.

`php artisan demo:verify` checks:

- stock never goes negative and matches the ledger;
- order lines and invoices add up (lines plus VAT);
- payments never exceed totals;
- every SKU has 14 or more covered days;
- every stock status and aging bucket shows;
- every page's main query returns rows.

### Demo accounts

Every demo account uses the password `aquaops-demo`. Use it on a local machine only, never anywhere else.

| Role | Accounts |
|---|---|
| Administrator | `wanjiru.admin@demo.aquaops.test`, `brian.admin@demo.aquaops.test` |
| Production Manager | `achieng.prodmgr@demo.aquaops.test`, `kevin.prodmgr@demo.aquaops.test` |
| Production Staff | `daniel.production@demo.aquaops.test`, `faith.production@demo.aquaops.test` |
| Inventory Staff | `grace.inventory@demo.aquaops.test`, `samuel.inventory@demo.aquaops.test` |
| Maintenance Staff | `peter.maintenance@demo.aquaops.test`, `mercy.maintenance@demo.aquaops.test` |

Every name, customer, school and machine maker in the demo is invented.

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

The service has three endpoints:

- **`POST /predict`:** returns a prediction with `model_version` and `rmse_score`.
- **`GET /health`:** reports reachability and the model version served per SKU.
- **`GET /climatology`:** returns the monthly mean temperatures each served bundle scores with.

How Laravel builds a request:

- **History:** daily `units_sold` from `sales_transactions.quantity`, joined through `warehouse_stock` to `products`. A missing day counts as zero **only** if that SKU and date are marked `sales_data_covered`; otherwise the history is incomplete.
- **Requirement:** 14 consecutive covered history days plus complete features.
- **Features:** read from `daily_sku_features` (`is_promotion`, `is_public_holiday`, `is_school_term`, `temperature_c`, plus `temperature_source`).
  - **Flags:** written by the [Business Calendar](#business-calendar).
  - **Temperature:**
    - The served model scores with the monthly mean temperatures in its bundle (`temperature_source = monthly_mean`) and ignores the value sent, but Laravel still requires one.
    - `temperature_c` is filled from `GET /climatology`, cached for a day, falling back to the same means in the manifests under `ML_MODEL_DIR` when the service is down.
    - There is deliberately no weather API: a `TemperatureSource` interface has only the monthly-mean implementation, because live temperatures would only matter if the model were retrained on them.
- **Persistence:** results are upserted into `forecast_records`, keyed by product, forecast date and model version, so re-renders never create duplicates.

Each SKU needs a model bundle with a manifest (`model_version` and `rmse_score`). Until a SKU has both, its forecast stays unavailable.

## Scheduled commands

All run in the operations timezone (the `organisation.timezone` setting, default Africa/Nairobi). The scheduler must be running (`php artisan schedule:work` locally, or a cron entry for `schedule:run` in production).

| Command | When | What it does |
|---|---|---|
| `errors:prune` | daily | Deletes error events past the configured retention period |
| `maintenance:generate-work-orders` | 06:00 | Creates work orders for preventive plans due within two days (once per due date) and reminds about overdue plans |
| `calendar:rebuild` | 00:30 | Rewrites the next 30 days of forecast context from the Business Calendar (`calendar:rebuild {from} {to}` by hand; `--include-covered` to rewrite history) |
| `sales:close-day` | 17:45 | Marks the day's sales complete for forecasting, once no order dated that day is still open |
| `forecasts:generate` | 18:00 | Generates tomorrow's forecasts |
| `invoices:notify-overdue` | 08:00 | Notifies invoicing users about each overdue invoice, once |
| `requests:notify-overdue` | 08:05 | Notifies the receiving department about each overdue request, once |

## Repository layout

```
app/
  Console/Commands/         Scheduled commands (forecasts, sales day, overdue invoices and requests), demo:seed / verify / clear
  Http/Controllers/         Login, password set, View As, access requests, invoice PDFs
  Livewire/                 Pages and modals; Dashboard/ holds the dashboard widgets
  Models/                   Eloquent models
  Services/                 Domain services: Dashboard, Forecasting, Inventory, Invoicing, Leakage,
                            Orders, Requests, Revenue, Calendar (ContextCalendar, CalendarService, TemperatureSource),
                            Maintenance (MaintenanceService, LineStatus),
                            Demo (DemoDataBuilder, DemoDataset, DemoDataVerifier, DemoDataCleaner),
                            plus MLForecastClient, NotificationService, SettingsService
  Support/                  Shared helpers: ModuleAccess, YearlySequence, SettingsDate, dashboard registry
config/settings.php         Setting definitions (defaults, validation, Settings tabs)
lang/en/help.php            Help centre, tour, popover and empty-state text
data/                       Synthetic demand dataset
database/
  migrations/
  seeders/                  Roles, modules, permissions, lines, features, test users, demo data
resources/help/             Help guides in Markdown, one per module plus general guides
resources/views/            Blade and Livewire views, shared components, branded error pages
design-reference/           HTML design mockups
model_reference/            Original training notebook (reference only)
ml/                         Training, prediction service, dashboard and ML tests (see ml/README.md)
tests/                      Feature and unit tests
```

## Testing

```bash
php artisan test                 # Laravel suite (in-memory SQLite)
npm run a11y                     # axe accessibility check (needs the app running)
cd ml && uv run pytest           # prediction service and dashboard
```

`npm run a11y` signs in to the running app (default `http://localhost:8000` as `admin@aquaops.test`), switches that account to Dark + Ocean, runs axe-core (WCAG 2.1 A and AA) on 13 main pages in a headless Edge or Chrome, then restores the account's own theme. Other combinations: `A11Y_THEME=light A11Y_PROFILE=sunset npm run a11y`. `A11Y_BASE_URL`, `A11Y_EMAIL`, `A11Y_PASSWORD` and `A11Y_BROWSER` are also read.

The Laravel suite covers access control at every boundary: per-module permissions, request visibility and snapshots, widget gating, which help guides and popovers each role sees, and fixed query counts. A structure test checks that every guide has the required sections and 3 to 6 tasks. `AppearanceContrastTest` checks every token pair in every profile and mode for WCAG AA, and that status colours keep their hue. `DesignTokenUsageTest` fails if a view or component uses a raw Tailwind palette class or a literal colour. `DemoDataTest` seeds 30 days of demo data and asserts that `demo:verify` passes, that the same day gives the same data, and that `demo:clear` removes it.

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
| Equipment and maintenance (equipment, plans, work orders, downtime, line warnings) | Complete |
| Reports | Planned (sidebar placeholder) |
| Business Calendar (holidays, school terms, promotions, temperature from model climatology) | Complete |
| In-app help (Help centre, page-title help, welcome tour, empty states) | Complete |

### Known limitations
- `sales_data_covered` defaults to `false`. `sales:close-day` marks a day covered once no order dated that day is still open, and forecasts need 14 consecutive covered days.
- **School terms and movable holidays are entered by hand.** School terms need entering each year, and movable holidays (Good Friday, Easter Monday, Eid) once each is announced.
- **Holidays aren't moved:** a holiday that falls on a Sunday isn't moved to the Monday automatically; add the observed day as a one-off.
- Invoices are internal only: there's no eTIMS/KRA integration, no credit notes (corrections are void then reissue), and invoices aren't emailed.
- **Dates between midnight and 03:00 Nairobi time:** timestamps are stored in UTC and "today" on documents and reports follows UTC, so an order or invoice created in those three hours gets the previous day's date, and revenue days are grouped in UTC. Scheduled jobs already use the organisation timezone.
- **Products, prices and production lines** come from the seeders; there is no screen to add or reprice a product yet.
- **A production schedule can't be edited or cancelled** once created.
- **Stock from a run is added by hand** with Stock In. It must match the product the run bottled, but the quantity isn't checked against the units logged.
- Below the `lg` breakpoint the sidebar becomes a slide-out menu, and wide tables scroll horizontally inside their cards.
- In a hidden browser tab, dashboard polling slows down (Livewire throttles it) rather than stopping completely.
