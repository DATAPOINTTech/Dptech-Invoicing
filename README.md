# DATAPOINT Business Management & Invoicing System (PHP)

A high-performance, zero-external-dependency enterprise invoicing, multi-company quoting, inventory control, and payment ledger application built in native PHP.

---

## Key Features

- **Multi-Company Quotations & Invoicing:** Issue estimates and sales tax invoices across multiple brand profiles with custom markups and color themes.
- **Sales Tax Invoices:** Official PDF invoices with FBR compliant layout, NTN/STRN badges, automatic tax & withholding calculations.
- **Payment Ledger:** Record full or partial payments with method, reference, and date tracking. Automatic balance due recalculation.
- **Inventory & Stock Management:** Automated stock tracking with movement logs on invoice generation.
- **Client & Supplier Management:** Comprehensive directory with NTN, STRN, contact, and billing metadata.
- **Direct SMTP Email Dispatch:** Native SSL/TLS authenticated email delivery with graphical PDF attachments.
- **Live Price Scraper:** Web scraping utility to fetch latest component market prices.
- **Zero Heavy Dependencies:** Runs entirely on native PHP 8.1+ with PDO and standard extensions.

---

## Quick Start (Local Development)

### Prerequisites

- PHP 8.1 or higher (PHP 8.2 or 8.3 recommended)
- Standard PHP extensions: `pdo`, `pdo_sqlite`, `openssl`, `curl`, `mbstring`, `fileinfo`

### Run Application

Start the local development server:

```bash
php run.php
```

Or run via PHP built-in server:

```bash
php -S 0.0.0.0:8000 -t php/public php/public/index.php
```

Visit **`http://localhost:8000`** in your browser.

### Default Admin Credentials

- **Username:** `admin` (or `info@datapointtechnology.com`)
- **Password:** `admin123`

---

## Deployment Options

### 1. cPanel Shared / VPS Hosting (Recommended)

1. Upload the files or deploy from git via cPanel Git Version Control.
2. Ensure the Document Root points to `php/public`.
3. To deploy database and configurations, refer to the ready-made backup package in `cpanel_backup/` or unzip `cpanel_backup.zip`.
4. Import `cpanel_backup/cpanel_database.sql` into your cPanel MySQL database via phpMyAdmin.
5. Copy `cpanel_backup/.env.cpanel` to `.env` and set your database connection and SMTP details.

### 2. Docker Deployment

```bash
docker-compose up -d --build
```

Access the application at `http://localhost:8080`.

### 3. Cloud / PaaS (Railway / Render / Heroku)

Deploy via the included `Procfile`:
```
web: php -S 0.0.0.0:$PORT -t php/public php/public/index.php
```

Set environment variables in your cloud dashboard:
```
DATABASE_URL=sqlite:///./php/storage/dptech.db
SECRET_KEY=<your-random-secret-key>
```

---

## Project Structure

```text
├── php/
│   ├── public/              # Document root (index.php, static assets, logos, favicon)
│   ├── routes/              # Modular route handlers (invoices, estimates, auth, clients, etc.)
│   ├── services/            # Core business logic (pdf_service, communication, inventory, scraper)
│   ├── templates/           # Server-side HTML views and responsive UI components
│   ├── storage/             # Application database (dptech.db) and file uploads
│   └── bootstrap.php        # Application bootstrap, routing, and database connection
├── cpanel_backup/           # Ready-to-deploy cPanel MySQL dump and config template
├── run.php                  # Local development server runner
├── index.php                # Root entry point forwarding to php/public/index.php
├── Dockerfile               # Production PHP 8.3 Apache container definition
└── docker-compose.yml       # Containerized environment orchestration
```

---

## License & Support

Developed for **DATAPOINT Technologies**. All rights reserved.
For inquiries, contact: `info@datapointtechnology.com`.
