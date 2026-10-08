# cPanel Deployment Guide - DATAPOINT Invoicing System

This package contains everything needed to deploy the application on any standard cPanel web hosting.

---

## Files in this Backup Package:
1. `cpanel_database.sql` - Complete MySQL/MariaDB database dump with all tables and clean production multi-company setup.
2. `dptech_cpanel.sqlite` - Ready-to-use SQLite database file (if using SQLite instead of MySQL).
3. `.env.cpanel` - Production environment configuration template.

---

## Method 1: Deploy with MySQL on cPanel (Recommended)

### Step 1: Create Database in cPanel
1. Log in to your **cPanel**.
2. Go to **MySQL® Databases**.
3. Create a new database (e.g. `youruser_dptech`).
4. Create a new MySQL user with a strong password (e.g. `youruser_dbadmin`).
5. Add the user to the database and check **ALL PRIVILEGES**.

### Step 2: Import `cpanel_database.sql` via phpMyAdmin
1. In cPanel, click **phpMyAdmin**.
2. Select your newly created database on the left sidebar.
3. Click the **Import** tab at the top.
4. Click **Choose File** and select `cpanel_database.sql`.
5. Click **Go** at the bottom.
6. All 17 tables, company profiles, and administrator accounts will be imported.

### Step 3: Upload Application Code
1. In cPanel, open **File Manager**.
2. Navigate to `public_html` (or your subdomain folder).
3. Upload all files from the `php/` folder (or extract the project zip).
4. Make sure `public/index.php` is pointed to by your domain document root (or place files so `public` or `.htaccess` redirects to the front controller).

### Step 4: Configure `.env`
1. Copy `.env.cpanel` to `.env` in your app root folder.
2. Edit `.env` and set your MySQL credentials:
   ```env
   DATABASE_URL=mysql://youruser_dbadmin:YourPassword@127.0.0.1:3306/youruser_dptech
   ```

---

## Method 2: Deploy with SQLite on cPanel (Zero-Config Database)

If you do not want to set up MySQL in cPanel:
1. Upload `dptech_cpanel.sqlite` into the `storage/` directory on cPanel:
   `storage/dptech.db`
2. Ensure write permissions on `storage/` directory (`chmod 775` or `chmod 777`).
3. In `.env`, set:
   ```env
   DATABASE_URL=sqlite:///./storage/dptech.db
   ```

---

## Login Credentials:
- **URL:** `https://your-domain.com/login`
- **Username:** `admin`
- **Password:** `admin123` (or whatever set in `ADMIN_PASSWORD`)