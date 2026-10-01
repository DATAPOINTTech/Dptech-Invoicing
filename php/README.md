# PHP migration path

This directory is an independent PHP runtime. It does not replace or modify the
FastAPI application under `app/`. The Python and PHP source trees are separate:

```text
app/                 # Python/FastAPI application
php/                 # PHP application
  public/            # web-server document root
  services/          # PHP business services
  bootstrap.php      # PHP runtime bootstrap
```

## Requirements

- PHP 8.1 or newer
- PDO SQLite for the local database, or PDO PostgreSQL for PostgreSQL
- `pdftotext` for PDF invoice imports
- `ZipArchive` and `DOM` for DOCX imports

## Run locally

From the `php/` directory:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

The PHP app reads its own `php/.env` file first and keeps its runtime data in
`php/storage/dptech.db`. If that local database is missing, the app copies the
existing repository database into the PHP storage folder automatically so the
standalone PHP app still works without the Python project.

The PHP health check is available at `http://127.0.0.1:8080/health`.

## Migrated endpoints

- `GET /health`
- `POST /api/auth/login`
- `POST /api/tax/calculate`
- `POST /api/invoices/pdf`
- `GET|POST /api/clients`
- `GET|POST /api/products`
- `GET|POST /api/invoices`
- frontend shell routes: `/login`, `/dashboard`, `/master-data`,
  `/transactions`, and `/reports`

The data routes use the existing `clients`, `products`, `invoices`, and
`invoice_items` tables without migrations. All reads and writes except login
require the same `Authorization: Bearer <token>` header as the Python API.
Staff permissions are read from the existing `users.permissions` JSON column;
administrators and managers retain the Python app's implicit access.

Example tax request:

```json
{
  "items": [{"subtotal": 1000}],
  "discount_percent": 5,
  "tax_rate": 17,
  "apply_wht": true,
  "apply_fed": false
}
```

The PHP service ports in `php/services/` retain the Python service option names
where practical. More API route groups can be migrated onto this entrypoint
incrementally without changing the live FastAPI deployment.

## Uploading to hosting providers

For PHP hosting, upload only the contents of `php/`:

- Set the hosting document root to `php/public/`.
- Keep `php/services/` and `php/bootstrap.php` outside the public document root
  when the provider supports separate private files.
- Copy `php/.env.example` to `php/.env` or use the provider's environment
  configuration.
- Set `DATABASE_URL` to the provider's database and configure `SECRET_KEY`.
- Do not upload the repository `.env` file or the Python `.venv/` directory.

For Python hosting, continue deploying the repository root with `app/`,
`requirements.txt`, and `run.py`; the `php/` directory is not required.

## Database location and upload options

The current local SQLite database is:

```text
dptech.db
```

It is located at the repository root and currently contains the application's
local data. The backup file, when present, is also at the repository root:

```text
dptech.db.backup-YYYYMMDD-HHMMSS
```

The PHP package has a separate target location:

```text
php/storage/dptech.db
```

That directory is empty in a fresh checkout. The PHP runtime's default database
configuration points there through `php/.env.example`:

```env
DATABASE_URL=sqlite:///./storage/dptech.db
```

### Uploading the existing SQLite data

To migrate the current local data to PHP hosting, stop the application, make a
backup, and copy `dptech.db` to `php/storage/dptech.db`. Upload
`php/storage/dptech.db` to the hosting server and ensure the PHP process has
read/write permission on `php/storage/`.

Do not place the database inside `php/public/`; that would make it potentially
downloadable over HTTP. SQLite files are ignored by Git, so they will not be
uploaded by a normal Git deployment.

### Recommended production option

For production or multiple application servers, use the hosting provider's
managed PostgreSQL or MySQL database instead of uploading SQLite. Set the PHP
deployment's `DATABASE_URL` to that provider connection string and keep the
database outside the web root.
