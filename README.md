# InventoryDSS — GS25 Hanoi Branch (Group 3, INS328201)

Inventory & Demand Decision Support Web for the GS25 Hanoi branch. Plain PHP (no
framework) following a `config → includes → services → api → views` architecture,
plus a separate Python (FastAPI) microservice for Demand Forecast.

## 1. Folder structure

```
backend/
  api/        - HTTP clients calling external services (ForecastAPI, NotificationAPI)
  config/     - app_config.php (system constants), database.php (PDO connection)
  core/       - Shared Auth, Middleware, Logger
  models/     - 1 PHP class per DB table, SQL queries only (no business logic)
  services/   - Business logic per role (ManagerService, AdminService, ReorderService...)
frontend/
  admin/ manager/ staff/  - Views per role, sharing components/
  components/ - sidebar.php, header.php, footer.php shared across all pages
  assets/     - CSS/JS/images
forecast-api/ - Python (FastAPI) microservice computing Demand Forecast, independent of the PHP backend
db.sql        - Full schema (DROP + CREATE) + seed/mock data for demos
                (kept internal, NOT committed to this repo — see Section 3)
```

## 2. Environment requirements

- PHP 8.1+ with the `pdo_mysql` extension (tested on XAMPP - Apache + MySQL/MariaDB)
- MySQL/MariaDB 8.x
- Python 3.10+ (only needed to run the real Demand Forecast API; if skipped, the PHP
  system automatically falls back to the rule-based formula per BR-18, see
  `backend/services/ReorderService.php`)

## 3. Setup (local via XAMPP)

1. Clone the repo into your `htdocs` folder, **keeping the folder name unchanged**:
   `InventoryDSS_Group3_INS328201` — `BASE_URL` in `backend/config/app_config.php`
   currently hardcodes this path (`/InventoryDSS_Group3_INS328201/frontend`).
   Renaming the folder will break every link/redirect in the sidebar and header.

   ```
   C:\xampp\htdocs\InventoryDSS_Group3_INS328201\
   ```

2. Create the database and import the schema + seed data:

   ```sql
   CREATE DATABASE project2;
   ```

   > ⚠️ **`db.sql` is intentionally NOT committed to this repository.** It is
   > shared internally within the team (contains realistic-looking seed values,
   > including something resembling an API key in `api_configs` — see Section 6)
   > rather than pushed to a public GitHub repo. Ask a teammate for the current
   > `db.sql` (or export it from an existing local instance:
   > `mysqldump -u root project2 > db.sql`) and place it at the repo root before
   > running the import below.

   ```bash
   mysql -u root project2 < db.sql
   ```

   `db.sql` starts with `DROP TABLE IF EXISTS ...` — it can be re-run safely any
   number of times (fully wipes and recreates from scratch), which is convenient for
   resetting the demo environment.

3. Verify the DB connection settings match your environment in
   `backend/config/database.php` (defaults: host `127.0.0.1`, port `3306`, database
   `project2`, user `root`, no password — standard XAMPP defaults).

   > ⚠️ This file currently **hardcodes connection details directly in code** rather
   > than reading from environment variables. This is a known limitation (see
   > Section 6 — Known gaps), NOT an incorrect assumption made by this README.

4. Access: `http://localhost/InventoryDSS_Group3_INS328201/frontend/login.php`

5. Sample login accounts: see `INSERT INTO accounts (...)` in `db.sql` (passwords are
   hashed — use the plaintext value the team agreed on when seeding, not a default
   "password").

## 4. Running the Demand Forecast API (optional)

Only needed if you want real AI-based suggestions (FR-MGR-10) instead of the
rule-based fallback:

```bash
cd forecast-api
python -m venv .venv
.\.venv\Scripts\Activate.ps1        # Windows
# source .venv/bin/activate         # macOS/Linux
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Create a `forecast-api/.env` file (see `forecast-api/.env.example`), and set the
same `FORECAST_API_KEY` value in the `api_configs` table in the DB (via Admin >
Settings, or a direct UPDATE) so both sides authenticate correctly against each
other.

If this service isn't running, `IntegrationService::getForecastForProduct()`
automatically falls back to `ReorderService::suggestQuantityForProduct()` (BR-18) —
the PHP system still runs fully, it just loses the AI-based suggestion.

## 5. Accounts & permissions

3 fixed roles (`backend/config/app_config.php`): `ROLE_ADMIN` (1), `ROLE_MANAGER`
(2), `ROLE_STAFF` (3). The sidebar menu filters itself by role
(`frontend/components/sidebar.php`), but **this is not a real security mechanism** —
actual access control is enforced by `Middleware::guard([...])` at the top of every
view/API file.

## 6. Known gaps / limitations (until 23/07/2026):

- **Secrets are not separated from code on the PHP side**: `backend/config/database.php`
  hardcodes DB credentials instead of reading from `.env`. `forecast-api` (Python)
  does this correctly (`python-dotenv`); the PHP side does not.
- **`db.sql` is deliberately excluded from this public repo** (see Section 3) because
  its seed data contains a value that resembles a real API key
  (`sk_live_gs25_fbf932` in the `api_configs` table). This is very likely
  demo/mock data, but the naming convention (`sk_live_...`) matches how real API
  keys are conventionally named — treat it as sensitive until confirmed
  otherwise, and do not paste its contents into any public channel (issues, PRs,
  chat logs) when sharing it internally.
- **No versioned migrations** — `db.sql` is a single DROP+CREATE+INSERT script, with
  no schema changes tracked separately over time.
- **No CI, and no pull request has been reviewed on this repo**: `main` has 30+
  commits, but most messages are non-descriptive (`Update`, `Demo`) and none
  reference an issue/requirement ID — commit history is not traceable back to
  FR/BR IDs as of this README.

## 7. Related documentation

Detailed business specs (FR/BR/UI State/API Contract) per feature live in the
team's Activity 2 document (not included in this repo).
