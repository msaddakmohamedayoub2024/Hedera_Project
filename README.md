## The problem
High-school graduates rank their university choices in a national orientation
process. If those choices are silently altered (by an insider, a compromised
account or a database attack), the student has no proof of what they actually
submitted.

## Our solution
INSaf anchors a SHA-256 fingerprint of each confirmed application on the
Hedera Consensus Service. The student's receipt can be checked at any time
against Hedera. If the current data no longer matches the anchored
fingerprint, the system flags the tampering, emails the owner and supports
an incident report to the authorities.

## Why Hedera
- Immutable, consensus-timestamped record that neither the database
  administrator nor an attacker can rewrite.
- Only a fingerprint is published, never personal data.
- Very low fee per message and fast finality, so anchoring every
  application is realistic at national scale.
- Anyone can verify through the public Mirror Node.

Live topic: https://hashscan.io/testnet/topic/<YOUR_TOPIC_ID>

## Demo
- Video: https://drive.google.com/drive/folders/1UyGyTb-5VRMe6gQVrgn0iytoy3dxwPb3?usp=drive_link
- Test account: CIN 12345677 / password insaf2026
- Set `DEMO_MODE=true` in `BackEnd/.env`, then use the
  "Se comporter comme un hacker" button to simulate a tampering attempt.
  The simulation never writes to the real HCS topic; the alert email is
  sent to the account owner.

## Impact and next steps
Ministries and universities could adopt it to guarantee tamper-evident
applications. Next: salted/HMAC fingerprints per student, DID/verifiable
credentials, multi-institution anchoring.

# INSaf

INSaf is a university-preference and application-integrity platform. Students can manage university choices, submit a verified application, and check its integrity against a record anchored to the Hedera Consensus Service (HCS). The application also includes administrator tools for reviewing integrity incidents.

The project is a tamper-evident application demonstration. It is not a complete decentralized identity (DID) or verifiable-credentials system.

## Features

- Student accounts and authenticated Laravel API endpoints.
- A React interface for student profiles, university choices, submissions, and receipt verification.
- Editable preference drafts that are locked during confirmation. A submission is finalized only after the student confirms a one-time code sent to their registered email.
- SHA-256 application fingerprints anchored to a configured Hedera HCS topic, with receipt details and Mirror Node verification.
- Profile contact-change and password-reset verification flows.
- Administrator access, integrity-incident reporting, and private PDF report storage.
- Seed data for programs, universities, a test student, and fictional students for demonstrations.

## Architecture

- **Frontend:** React 19 and Vite 8 in `FrontEnd/`.
- **Backend/API:** Laravel 12 and Sanctum in `BackEnd/`.
- **Database:** MySQL or MariaDB is recommended when multiple developers need to use the same database. Laravel also supports other configured drivers, including SQLite for local development and tests.
- **Hedera sender:** Node.js, npm, and the Hedera JavaScript SDK in `BackEnd/hedera/`.
- **Email:** Laravel mail transport, configured using the backend `.env`, for one-time codes and account-related messages.

The frontend currently sends API requests to `http://127.0.0.1:8000/api`, as configured in `FrontEnd/src/Api.js`. If the backend runs at another address, update that base URL to match.

## Requirements and dependencies

Install the following tools before setting up the project:

- PHP 8.2 or later, with the PHP extensions required by Laravel and the selected database driver.
- Composer.
- Node.js and npm (use a version compatible with Vite 8).
- MySQL 8 or a compatible MySQL/MariaDB service for a shared database.
- A Hedera testnet account and an existing HCS topic for live anchoring. Hedera credentials are not needed to run the frontend or backend locally, but live HCS submissions require them.

The primary dependencies are Laravel 12, Laravel Sanctum, React 19, Vite 8, Axios, and `@hashgraph/sdk`. Composer and npm install the full dependency sets from the manifests and lockfiles.

## Setup

Run these commands from the repository root in PowerShell. Configure `BackEnd/.env` before migrating or seeding.

### 1. Install and configure the backend

```powershell
Set-Location BackEnd
Copy-Item .env.example .env
composer install
```

Edit `BackEnd/.env` and set a secure application key after configuring the database:

```powershell
php artisan key:generate
```

Configure the database connection. To connect to a shared database, every developer must use the same host, port, database name, and authorized database credentials:

```dotenv
DB_CONNECTION=mysql
DB_HOST=<shared-database-host>
DB_PORT=3306
DB_DATABASE=<shared-database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<database-password>
```

For a local-only setup, use your local database service and its credentials instead.

### 2. Configure the test account and email

`TEST_USER_EMAIL` in `BackEnd/.env` is the email address assigned to the test student created by the seeder. Set it to the inbox that should receive the test account's verification messages:

```dotenv
TEST_USER_EMAIL=your-email@example.com
```

The seeded test account uses CIN `12345677` and password `insaf2026`. Change the test password before using any non-disposable environment.

Configure Laravel's outgoing mail settings with credentials from your email provider. For SMTP, for example:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<smtp-account-email>
MAIL_PASSWORD=<smtp-app-password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<verified-sender-email>
MAIL_FROM_NAME="INSaf"
```

`MAIL_USERNAME` and `MAIL_FROM_ADDRESS` configure the sending account and sender identity; `TEST_USER_EMAIL` is the seeded student's destination address. The default log mailer does not deliver email.

### 3. Initialize the database

For a new, empty database, run migrations and seed the demonstration records once:

```powershell
php artisan migrate --seed
```

The seeder creates the test account and demonstration data. Do not run `php artisan migrate:fresh --seed` against a shared or production database: `migrate:fresh` drops all tables and data. Other developers connecting to an already initialized shared database should not re-seed it.

When changing `TEST_USER_EMAIL`, remember that it affects the account created by the seeder; changing the variable does not modify an account that already exists in the database. On an existing shared database, sign in using the email and credentials of an account already present there.

### 4. Configure Hedera for live anchoring (optional)

For live HCS submissions, configure the backend topic and Mirror Node in `BackEnd/.env`:

```dotenv
HEDERA_NETWORK=testnet
HEDERA_TOPIC_ID=<existing-testnet-topic-id>
HEDERA_MIRROR_NODE_URL=https://testnet.mirrornode.hedera.com
DEMO_MODE=false
```

Set up the Hedera sender's local environment:

```powershell
Set-Location BackEnd\hedera
Copy-Item .env.example .env
npm install
```

In `BackEnd/hedera/.env`, set the testnet account and ECDSA private key:

```dotenv
HEDERA_ACCOUNT_ID=<testnet-account-id>
HEDERA_PRIVATE_KEY=<testnet-ecdsa-private-key>
HEDERA_NETWORK=testnet
```

Keep `.env` files and private keys out of source control. Use testnet for development; live transactions may incur network fees. The backend tests mock Hedera calls and do not submit transactions.

## Run the application

Start the Laravel API in one terminal:

```powershell
Set-Location BackEnd
php artisan serve --host=127.0.0.1 --port=8000
```

Start the React/Vite frontend in a second terminal:

```powershell
Set-Location FrontEnd
npm install
npm run dev
```

Open the local URL printed by Vite. For features that send email codes, ensure the Laravel mail settings are configured and the recipient inbox can receive mail.

## Tests and production build

Run backend tests:

```powershell
Set-Location BackEnd
php artisan test
```

Build and lint the frontend:

```powershell
Set-Location FrontEnd
npm run build
npm run lint
```

## Shared database notes

All users can work with the same database only when the backend is configured to connect to the same shared database server and schema. The email setting does not identify or connect to a database; `TEST_USER_EMAIL` only controls the email stored for the test account when that account is initially seeded. Share database access securely, use individual application accounts where possible, and never publish database passwords or mail/Hedera secrets.

## Security and configuration

- Keep real credentials in local `.env` files; never commit `.env`, database passwords, SMTP passwords, or Hedera private keys.
- Use a dedicated test database for demonstrations and backups before applying migrations to important data.
- Do not use the seeded test credentials in a public or production environment.
- Set production environment values appropriately, including `APP_ENV=production` and `APP_DEBUG=false`.
