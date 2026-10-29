# Quick PayMoney

Quick PayMoney is a Laravel 12 account portal and administration foundation for a USDT-to-INR service. It provides separate customer and administrator access, account controls, audited exchange-request workflows, and a manually configured reference rate.

> **Financial scope:** This application does not custody assets, hold wallet balances, provide a live pricing feed, move funds, or settle transactions. The exchange rate is informational. Do not represent a workflow status as a completed payment or settlement.

## Features

- Customer registration, sign-in, password recovery, profile, and password management.
- Separate admin authentication guard and admin login, with mandatory authenticator-based 2FA.
- Local-only demo 2FA code for development when `ADMIN_2FA_DEMO=true`, `APP_ENV=local`, and `APP_DEBUG=true`. Demo codes are never exposed or accepted in production.
- Admin user directory with search, status filters, paginated results, profile details, and audited role/status updates.
- Exchange-request review and controlled status transitions, with audit history and in-app user notifications.
- Manually configured USDT/INR reference rate stored as a fixed-precision decimal and protected by audit logging.
- Session protections, login throttling, account status checks, one-time/replay-protected TOTP codes, and role-separated authentication.
- Responsive, branded admin dashboard and customer-facing account portal.

## Tech stack

- **Backend:** PHP 8.2+, Laravel 12
- **Database:** MySQL 8+ (required for running the admin seeder); SQLite is used by the automated test suite
- **Frontend:** Blade, CSS, Bootstrap 5.3, Bootstrap Icons, Vite 6, vanilla JavaScript
- **Authentication security:** Laravel sessions and password hashing, separate customer/admin guards, TOTP two-factor authentication
- **Dependencies:** Composer and npm

## Requirements

- PHP 8.2 or later with the PHP extensions required by Laravel (including PDO MySQL for MySQL deployments)
- Composer
- Node.js and npm
- MySQL 8+ or a compatible MySQL server
- SMTP or another configured mail transport for password recovery

## Local installation

### 1. Clone the repository

```bash
git clone https://github.com/<YOUR_GITHUB_USERNAME>/<YOUR_REPOSITORY>.git
cd <YOUR_REPOSITORY>
```

### 2. Install dependencies

```bash
composer install
npm ci
```

### 3. Configure the environment

Copy the example environment file and generate an application key:

```bash
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Edit `.env` and configure the local URL, MySQL database, admin password, and mail transport. For a local setup:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=quickpaymoney
DB_USERNAME=your_local_mysql_user
DB_PASSWORD=your_local_mysql_password

ADMIN_PASSWORD=replace-this-with-a-strong-unique-password
ADMIN_2FA_DEMO=true
SESSION_SECURE_COOKIE=false
```

Create the `quickpaymoney` database and replace the example database credentials and `ADMIN_PASSWORD` placeholder with your own values. Use a unique admin password with at least 12 characters, uppercase and lowercase letters, a number, and a symbol. Keep `.env` private; it is excluded from Git. `ADMIN_2FA_DEMO` only works when both `APP_ENV=local` and `APP_DEBUG=true`; turn it off when not needed.

### 4. Apply database migrations and provision the admin account

```bash
php artisan migrate
php artisan db:seed --class=AdminSeeder
```

The seeder creates `Quick PayMoney Admin` at `admin@quickpaymoney.com`, marks the account active and email-verified, and hashes the password from `ADMIN_PASSWORD`. It is idempotent and does not overwrite an existing admin password. It refuses to promote a customer account and only writes to MySQL outside automated tests.

### 5. Build assets and start the application

```bash
npm run build
php artisan serve
```

Open the local site at `http://127.0.0.1:8000`. For frontend development with Vite hot reload, run `npm run dev` in a separate terminal.

### Admin access

- **Admin sign-in:** `http://127.0.0.1:8000/admin/login`
- **Admin dashboard:** `http://127.0.0.1:8000/admin/dashboard`
- **Admin email:** `admin@quickpaymoney.com`
- **Admin password:** the value you set as `ADMIN_PASSWORD` in your private `.env` file.

On first sign-in, complete the authenticator setup. In local development only, the displayed demo code may be used when the local demo-mode conditions above are satisfied. Real authenticator codes continue to work.

## Screenshots

Add genuine screenshots of the running application to `docs/screenshots/` and update the links below. Do not include screenshots containing passwords, demo codes, authenticator secrets, personal data, or other private information.

| Page | Screenshot |
| --- | --- |
| Public homepage | `docs/screenshots/homepage.png` |
| Admin dashboard | `docs/screenshots/admin-dashboard.png` |
| User management | `docs/screenshots/admin-users.png` |
| Exchange management | `docs/screenshots/admin-exchanges.png` |

## Development and tests

Run the Laravel test suite:

```bash
php artisan test
```

Build production frontend assets:

```bash
npm run build
```

Check available application routes:

```bash
php artisan route:list --except-vendor
```

The automated tests use an isolated SQLite database. They do not require or modify the local MySQL database.

## Production and security notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, an HTTPS `APP_URL`, and production database/mail credentials using a secret manager or deployment environment.
- Use MySQL, HTTPS, and secure cookies (`SESSION_SECURE_COOKIE=true`). Do not set `SESSION_SECURE_COOKIE=false` in production.
- Set `ADMIN_2FA_DEMO=false` in production. Even if set to `true`, demo codes are guarded by local environment and debug checks.
- Keep `DEPOSITS_DEMO_APPROVAL_ENABLED=false` unless one-click demo approvals are explicitly intended. Deposits default to non-demo, and only rows explicitly marked `is_demo` can credit the separate `demo_usdt_balance` and demo ledger. The demo balance is not used by exchange or withdrawal workflows. To explicitly classify a pending deposit in a controlled environment, use Tinker with its exact deposit ID, for example:

  ```php
  \App\Models\Deposit::where('deposit_id', 'DEP...')->where('status', 'pending')->update(['is_demo' => true]);
  ```

- Real deposit approvals require both an independent verification timestamp and a server-recorded `blockchain_verification_source`; a customer-submitted transaction ID or prior manual checklist is not independent verification. No independent blockchain-verification integration is currently configured, so real deposits remain unapprovable until that integration records verified evidence.
- Never commit `.env`, database exports, credentials, private keys, logs, or user data. If a secret was committed previously, rotate it; removing it in a later commit does not remove it from Git history.
- Restrict and monitor `/admin`, retain access to the configured authenticator, and establish an operational account recovery process.
- Before enabling any real exchange, custody, payment, KYC/AML, or settlement functionality, obtain the required business rules, approvals, legal review, and verified integrations. Those capabilities are not implemented here.

## License

No project-specific license is currently declared. Add a `LICENSE` file before granting public reuse rights.
# quickpaymoney-fintech-platform
