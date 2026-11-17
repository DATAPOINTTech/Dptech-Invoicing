# 🚀 Enterprise White-Label Invoicing & Quotation Suite
### Comprehensive Standalone Hosting, Database & Customization Manual

---

## 📋 Table of Contents
1. [Overview & White Label Architecture](#1-overview--white-label-architecture)
2. [Server Prerequisites & PHP Requirements](#2-server-prerequisites--php-requirements)
3. [Quick Installation Steps](#3-quick-installation-steps)
4. [Database Configuration (MySQL, MariaDB & SQLite)](#4-database-configuration)
5. [First Run & Administrator Account](#5-first-run--administrator-account)
6. [White Label Branding & Settings Customization](#6-white-label-branding--settings-customization)
7. [Multi-Company Quotations & Comparative Bidding](#7-multi-company-quotations)
8. [Messaging Gateways (WhatsApp & SMTP Email)](#8-messaging-gateways)
9. [FBR Pakistan Digital Invoicing / International Tax](#9-tax--compliance-settings)
10. [Web Server Config (cPanel, Apache, Nginx, Docker)](#10-web-server-configuration)
11. [Troubleshooting & Support](#11-troubleshooting)

---

## 1. Overview & White Label Architecture

This package contains a production-ready, white-label Enterprise Invoicing, Multi-Company Quotations, Inventory, and Financial Management Application. It is architected so that **each individual company can be hosted completely independently with its own isolated database**, custom branding, company logo, tax rules, and currency.

### ✨ White-Label Features
- **100% Dynamic Branding**: Company name, sidebar brand headline, tagline, logo, favicon, and currency can be changed via the Web Admin panel or `.env`.
- **Zero Hardcoded Identities**: No vendor branding or hardcoded company names.
- **Separate Database per Company**: Connects to an independent MySQL, MariaDB, PostgreSQL, or SQLite database.
- **Master Admin Tools**: Built-in 1-Click Database Backup (.sql dump directly from browser), diagnostics, and demonstration data reset.
- **Multi-Company Bidding**: Automatically generates 3 competitive tier proposals (Base, +X%, +Y%) with separate company profiles.
- **Automated Migrations**: The application automatically creates all necessary tables and indexes on first boot if connected to an empty database.

---

## 2. Server Prerequisites & PHP Requirements

- **PHP Version**: PHP 8.1, 8.2, or 8.3 (recommended)
- **Web Server**: Apache 2.4+ (with `mod_rewrite`), Nginx 1.20+, LiteSpeed, or cPanel
- **Database**:
  - **MySQL 5.7+ / 8.0+** or **MariaDB 10.3+** (recommended for production)
  - **PostgreSQL 12+** (supported)
  - **SQLite 3** (zero-configuration single-server deployment)
- **Required PHP Extensions**:
  - `pdo`
  - `pdo_mysql` (or `pdo_sqlite` / `pdo_pgsql`)
  - `mbstring`
  - `gd` or `imagick` (for logo and PDF image processing)
  - `curl` (for API gateways)
  - `json`
  - `openssl`

---

## 3. Quick Installation Steps

### Step 1: Upload and Extract
Upload the `whitelabel-invoicing-suite.zip` file to your server or cPanel hosting directory (e.g. `public_html` or a subfolder like `public_html/invoicing`). Extract the contents.

### Step 2: Configure Permissions
Ensure that the following runtime storage directories are writable by the web server user:
```bash
chmod -R 775 php/storage
chmod -R 775 php/public/static/uploads
```
*(On cPanel, permissions of `755` on folders and `644` on files are standard).*

### Step 3: Create `.env` Configuration
Copy the provided `.env.example` file to `.env`:
```bash
cp .env.example .env
```
Open `.env` in a text editor and fill in your database credentials and initial company details.

### Step 4: Access in Web Browser
Navigate to your website URL in any browser:
```
https://yourcompany-invoicing.com/
```
The application will automatically detect the database, generate the schema, create the administrator account, and display the login screen.

---

## 4. Database Configuration

You can configure any database backend inside your `.env` file via the `DATABASE_URL` parameter.

### Option A: MySQL / MariaDB (Recommended for cPanel & Production)
1. In cPanel or phpMyAdmin, create a new MySQL database (e.g., `acme_invoicing`).
2. Create a database user and assign **ALL PRIVILEGES** to that database.
3. In your `.env` file, specify:
```ini
DATABASE_URL=mysql://dbuser:dbpassword@127.0.0.1:3306/acme_invoicing
```
*(Replace `dbuser`, `dbpassword`, and `acme_invoicing` with your actual cPanel MySQL credentials).*

### Option B: SQLite (Zero-Config Plug-and-Play)
Ideal for small businesses or instant VPS hosting without setting up a separate MySQL server:
```ini
DATABASE_URL=sqlite:///./storage/company.db
```
The SQLite database file will be created automatically in `php/storage/company.db`.

### Option C: PostgreSQL
```ini
DATABASE_URL=postgresql://dbuser:dbpassword@127.0.0.1:5432/acme_invoicing
```

---

## 5. First Run & Administrator Account

On first deployment with an empty database, the application automatically registers the initial administrator account based on the values in `.env`:

```ini
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@yourcompany.com
ADMIN_PASSWORD=YourSecurePassword123!
```

> [!IMPORTANT]
> - Ensure your `ADMIN_PASSWORD` is at least 8 characters long.
> - If left blank, a secure temporary password will be written to `php/storage/first_run_admin_password.txt`.
> - After signing in, you can update your administrator password under the user avatar in the bottom-left sidebar or in **Users & Permissions**.

---

## 6. White Label Branding & Settings Customization

Sign in as an administrator and click **System & Branding** in the left sidebar menu (or navigate to `/settings`).

### Customization Options Available:
1. **Application / Company Name**: Name displayed on document headers, printouts, and title bars.
2. **Sidebar Brand Header**: Short brand title (e.g. `ACME` or `GLOBAL`) displayed at the top of the sidebar.
3. **App Tagline**: Slogan or subtitle (e.g. `Enterprise Invoicing Suite`).
4. **Company Logo**: Upload a PNG, JPG, WebP, or SVG logo (live instant preview).
5. **Favicon**: Upload your company browser tab icon.
6. **Default Currency**: Select PKR, USD, EUR, GBP, AED, SAR, CAD, AUD, or INR with custom currency symbol.
7. **Tax Nomenclature**: Custom label (e.g. `Sales Tax (GST)`, `VAT`, or `Tax`) and default percentage.
8. **Contact Information**: Registered corporate address, phone numbers, official WhatsApp helpline, billing email, and website.

All settings are saved directly in the company's dedicated database and take effect immediately across all screens and PDF exports.

---

## 7. Multi-Company Quotations & Comparative Bidding

The platform includes a built-in 3-tier comparative bidding engine under **Companies Setup** (`/companies`):
- **Company 1 (Default / Issuer)**: Standard base pricing (0% markup) rendering your official corporate header.
- **Company 2 (Bidding Partner A)**: Configurable name, logo, and markup (e.g. +2%).
- **Company 3 (Bidding Partner B)**: Configurable name, logo, and markup (e.g. +3%).

When an estimate is created, the system enables 1-click comparative printing so you can produce proposals across three distinct company letterheads.

---

## 8. Messaging Gateways

### WhatsApp PDF Dispatch
The application supports sending estimates and invoices directly to customer WhatsApp numbers with attached PDFs.
- Navigate to **System & Branding > Gateways & Integrations**.
- Enable WhatsApp messaging.
- Select **Provider Mode**:
  - `Local Node.js Baileys Bridge / Web Fallback`: Works immediately without API costs; includes 1-click WhatsApp Web / mobile app dispatch fallback with pre-filled customer message and secure PDF download link.
  - `Meta WhatsApp Business Cloud API`: For direct corporate automated API delivery.

### SMTP Email Configuration
Configure your mail server in **System & Branding > Gateways & Integrations** (or in `.env`):
```ini
EMAIL_HOST=mail.yourcompany.com
EMAIL_PORT=587
EMAIL_USER=invoices@yourcompany.com
EMAIL_PASS=YourEmailPassword
EMAIL_FROM=invoices@yourcompany.com
```

---

## 9. Tax & Regional Compliance Settings

### Pakistan FBR Digital Invoicing & POS Integration
The system includes certified compliance with the Federal Board of Revenue (FBR) Pakistan Sales Tax Act 1990 & Rule 150ZEB:
- If your business is in Pakistan: Enter your 6-digit **FBR POS ID** and **Bearer Token** in **System & Branding > Tax & Compliance**.
- If your business is **outside Pakistan**: Simply set **Integration State** to **Disabled**. All FBR tax badges and verification QR codes will be deactivated cleanly.

---

## 10. Web Server Configuration

### Apache (`.htaccess`)
A pre-configured `.htaccess` file is included in the package. Ensure `mod_rewrite` is enabled on your server:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [L,QSA]
</IfModule>
```

### Nginx Sample Virtual Host
```nginx
server {
    listen 80;
    server_name invoicing.yourcompany.com;
    root /var/www/html/php/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. {
        deny all;
    }
}
```

---

## 11. Maintenance, Backups & Tools

### 1-Click Database Backup
Administrators can download a full `.sql` dump of all tables, schema, and transaction records at any time:
1. Go to **System & Branding > Database & Tools**.
2. Click **Download Database Backup (.sql)**.
3. The server generates a complete SQL dump and streams it directly to your browser.

### Starting Clean (Reset Demonstration Data)
If you want to clear test quotations and invoices before going live with real customers:
1. Go to **System & Branding > Database & Tools**.
2. Click **Reset Demonstration Data**.
3. Enter your administrator password when prompted.
4. All test transactions, invoices, and estimates are cleared to zero while keeping your administrator accounts, product catalog, and branding intact.

---

© Enterprise White-Label Invoicing Suite. Fully autonomous, standalone software.
