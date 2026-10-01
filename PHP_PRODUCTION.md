# DATAPOINT Invoicing System - PHP Production Deployment Guide

This guide details how to deploy the **DATAPOINT Business Management & Invoicing System** in PHP for production environments.

---

## 1. Architecture Overview

- **Language & Runtime:** PHP 8.1+ (tested on PHP 8.3)
- **Web Server:** Nginx, Apache (mod_php or php-fpm), or Docker container
- **Database:** PostgreSQL (recommended for production), MySQL, or SQLite
- **Document Root:** `php/public/`
- **Application Structure:**
  ```text
  php/
  ├── public/              # Web document root
  │   ├── index.php        # Central routing controller & entry point
  │   ├── .htaccess        # Apache rewrite rules & security headers
  │   └── static/          # CSS, JS, Images, Favicon
  ├── routes/              # Modular API & controller route handlers
  ├── services/            # Business logic (Auth, Tax, PDF, WhatsApp, AI Agent)
  ├── templates/           # Full Tailwind CSS UI views
  ├── storage/             # Runtime storage & SQLite database location
  ├── bootstrap.php        # Application initialization & database connection
  ├── .env.production      # Production environment settings template
  └── .env                 # Active environment variables
  ```

---

## 2. Production Deployment Options

### Option A: Docker (Recommended)

1. Clone or copy the project files to your server:
   ```bash
   git clone <repo-url> /opt/dptech
   cd /opt/dptech
   ```

2. Copy production environment file:
   ```bash
   cp php/.env.production php/.env
   # Edit php/.env to configure SECRET_KEY, ADMIN_PASSWORD, and database credentials
   ```

3. Launch with Docker Compose:
   ```bash
   docker compose up -d --build
   ```

4. Verify health check:
   ```bash
   curl -I http://localhost:8000/health
   # Returns HTTP 200 {"status":"ok","runtime":"php","database":"ok"}
   ```

---

### Option B: Linux VPS / AWS EC2 with Nginx & PHP-FPM

1. Install PHP 8.3 and extensions:
   ```bash
   # Ubuntu / Debian
   sudo apt update
   sudo apt install -y php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-pgsql php8.3-mysql \
                       php8.3-curl php8.3-mbstring php8.3-zip php8.3-bcmath php8.3-xml \
                       nginx git
   ```

2. Clone repository to `/var/www/dptech`:
   ```bash
   sudo git clone <repo-url> /var/www/dptech
   sudo chown -R www-data:www-data /var/www/dptech
   sudo chmod -R 775 /var/www/dptech/php/storage
   ```

3. Configure environment:
   ```bash
   sudo cp /var/www/dptech/php/.env.production /var/www/dptech/php/.env
   # Set strong SECRET_KEY and production database credentials
   ```

4. Configure Nginx:
   ```bash
   sudo cp /var/www/dptech/nginx.conf /etc/nginx/sites-available/dptech
   sudo ln -s /etc/nginx/sites-available/dptech /etc/nginx/sites-enabled/
   sudo nginx -t
   sudo systemctl reload nginx
   ```

---

### Option C: Apache / cPanel / Shared Hosting

1. Upload the contents of `php/` to your server.
2. Set your web hosting **Document Root** to `php/public/`.
3. Keep `php/storage/`, `php/services/`, and `php/routes/` outside the web root (or protected by `.htaccess`).
4. Ensure `php/storage/` has write permissions (`chmod 775`).
5. Set environment variables in cPanel or via `php/.env`.
6. `.htaccess` in `php/public/` handles all URL rewriting automatically.

---

### Option D: Cloud PaaS (Railway / Render / Heroku)

The repository includes a ready-to-use `Procfile`:
```text
web: php -S 0.0.0.0:$PORT -t php/public php/public/index.php
```

1. Connect your GitHub repository to Render, Railway, or Heroku.
2. Add environment variables in the dashboard:
   - `SECRET_KEY`: `<generate-a-random-secret-key>`
   - `DATABASE_URL`: `<your-managed-postgres-url>`
   - `ADMIN_USERNAME`: `admin`
   - `ADMIN_PASSWORD`: `<secure-admin-password>`
3. Deploy!

---

## 3. Local Development Runner

To run locally using PHP:
```bash
php run.php
```
Open **http://localhost:8000** in your browser.

---

## 4. Key Endpoints

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `/health` | `GET` | Application health and database check |
| `/login` | `GET` | User login page |
| `/dashboard` | `GET` | Main management dashboard |
| `/api/auth/login` | `POST` | User authentication & JWT generation |
| `/api/auth/register` | `POST` | User registration |
| `/api/clients` | `GET`, `POST` | Client management |
| `/api/invoices` | `GET`, `POST` | Invoice generation & tax calculation |
| `/api/invoices/{id}/pdf`| `GET` | On-the-fly PDF invoice generation |
| `/api/users` | `GET`, `POST` | User account management (Admin only) |
| `/api/users/{id}/permissions` | `GET`, `PUT` | Granular action-level permissions assignment (Admin only) |
| `/api/companies` | `GET`, `POST` | Multi-company configuration and folder stats |
| `/api/companies/setup` | `POST` | Bulk setup for 3 comparative companies |
| `/api/companies/upload-logo` | `POST` | Company logo upload handler |

---

## 5. Security & Granular Access Control

- **Administrator Privileges:** Only users with role `admin` have access to the `/users` management page and `/api/users*` endpoints.
- **Granular Permissions Matrix:** Administrators can assign module-by-module, action-by-action permissions to each individual user across all 11 modules:
  - `view`: Read-only access to records and lists
  - `create`: Ability to build new records
  - `edit`: Updating existing records
  - `delete`: Deleting or deactivating records
- **Non-Admin Restrictions:** Non-admin staff and managers are strictly checked against their individual assigned permissions via `require_permission_for()`. Any unauthorized API call returns an immediate `403 Forbidden` (`Permission denied: <module>.<action>`).

---

## 6. Multi-Company Quotations & Folders

- **3-Tier Quotation Engine:** Building a quotation automatically produces a 3-tier comparative set:
  - **Company 1 (Default):** Base fixed rates (0% markup)
  - **Company 2:** +2.0% markup on each item unit price
  - **Company 3:** +3.0% markup on each item unit price
- **Organized Folders:**
  - Sidebar features dedicated **Company Folders** under the Estimates tab with live estimate counts.
  - The Estimates page provides a Folder Tab Bar and dedicated company folder view.
  - Users can create synchronized 3-company sets or add individual estimates directly into specific company folders.

---

## 7. Production Database Reset & Deployment Verification

To clear all demo/test transactions and reset to a clean production state:
```bash
php php/scripts/reset_production_database.php
```
This performs:
1. Complete truncation of `estimates`, `estimate_items`, `invoices`, `invoice_items`, `clients`, `products`, `inventory`, `expenses`, `purchases`, `projects`.
2. Resets auto-increment sequences (`sqlite_sequence`) back to 1.
3. Configures 3 pristine production company profiles (*DATAPOINT Technologies*, *TechPoint Solutions*, *Apex Data Systems*).
4. Initializes the pristine Administrator account (`admin` / `admin123` or per `.env`).
5. Cleans up temporary upload files from `php/public/static/uploads/`.

