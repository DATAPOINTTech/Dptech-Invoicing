<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - cPanel Deployment Backup Generator
 * Generates:
 * 1. cpanel_database.sql (MySQL / MariaDB dump for phpMyAdmin import on cPanel)
 * 2. cpanel_sqlite_backup.db (Portable SQLite database for direct file upload)
 */

require_once __DIR__ . '/../bootstrap.php';

echo "========================================================\n";
echo "  cPanel Database Backup & Export Generator            \n";
echo "========================================================\n\n";

$db = database_connection();

$backupDir = dirname(PHP_APP_ROOT) . DIRECTORY_SEPARATOR . 'cpanel_backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// 1. Copy SQLite database file as portable backup
$sourceDb = default_php_database_path();
$targetSqlite = $backupDir . DIRECTORY_SEPARATOR . 'dptech_cpanel.sqlite';
if (is_file($sourceDb)) {
    copy($sourceDb, $targetSqlite);
    echo "[✓] Created portable SQLite backup: cpanel_backup/dptech_cpanel.sqlite (" . round(filesize($targetSqlite) / 1024, 2) . " KB)\n";
}

// 2. Build full MySQL/MariaDB DDL & DML script
$mysqlTables = [
    'users' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `hashed_password` VARCHAR(200) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` VARCHAR(20) DEFAULT 'staff',
  `is_active` TINYINT(1) DEFAULT 1,
  `permissions` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'companies' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `code` VARCHAR(50) DEFAULT NULL,
  `logo_url` TEXT DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `mobile` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(50) DEFAULT NULL,
  `ntn` VARCHAR(50) DEFAULT NULL,
  `strn` VARCHAR(50) DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `markup_percent` DECIMAL(5,2) DEFAULT 0.00,
  `sort_order` INT DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'clients' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `company` VARCHAR(200) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `mobile` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(50) DEFAULT NULL,
  `province` VARCHAR(50) DEFAULT NULL,
  `ntn` VARCHAR(50) DEFAULT NULL,
  `strn` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'suppliers' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `contact_person` VARCHAR(200) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `mobile` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(50) DEFAULT NULL,
  `ntn` VARCHAR(50) DEFAULT NULL,
  `strn` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'products' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `products` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `sku` VARCHAR(50) DEFAULT NULL,
  `unit_price` DECIMAL(15,2) DEFAULT 0.00,
  `cost_price` DECIMAL(15,2) DEFAULT 0.00,
  `unit` VARCHAR(20) DEFAULT 'pcs',
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_inclusive` TINYINT(1) DEFAULT 0,
  `hs_code` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `min_stock_level` DECIMAL(12,2) DEFAULT 0.00,
  `max_stock_level` DECIMAL(12,2) DEFAULT 0.00,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'inventory' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `inventory` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL UNIQUE,
  `quantity` DECIMAL(12,2) DEFAULT 0.00,
  `warehouse` VARCHAR(100) DEFAULT 'Main',
  `location` VARCHAR(100) DEFAULT NULL,
  `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'stock_movements' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL,
  `movement_type` VARCHAR(50) NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'price_list' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `price_list` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(300) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `unit` VARCHAR(20) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL,
  `currency` VARCHAR(10) DEFAULT 'PKR',
  `effective_date` DATE NOT NULL,
  `source` VARCHAR(200) DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'projects' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `client_id` INT DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'planning',
  `budget` DECIMAL(15,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'estimates' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `estimates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `estimate_no` VARCHAR(50) NOT NULL UNIQUE,
  `client_id` INT NOT NULL,
  `project_id` INT DEFAULT NULL,
  `title` VARCHAR(200) DEFAULT NULL,
  `estimate_date` DATE NOT NULL,
  `valid_until` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'draft',
  `subtotal` DECIMAL(15,2) DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) DEFAULT 0.00,
  `terms_conditions` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `company_id` INT DEFAULT NULL,
  `company_name` VARCHAR(200) DEFAULT NULL,
  `company_logo` TEXT DEFAULT NULL,
  `company_phone` VARCHAR(50) DEFAULT NULL,
  `company_email` VARCHAR(100) DEFAULT NULL,
  `company_address` TEXT DEFAULT NULL,
  `company_ntn` VARCHAR(50) DEFAULT NULL,
  `company_strn` VARCHAR(50) DEFAULT NULL,
  `batch_id` VARCHAR(50) DEFAULT NULL,
  `markup_percent` DECIMAL(5,2) DEFAULT 0.00,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_est_company` (`company_id`),
  KEY `idx_est_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'estimate_items' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `estimate_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `estimate_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL,
  `unit` VARCHAR(20) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_price` DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_est_item_est` (`estimate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'invoices' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
  `client_id` INT NOT NULL,
  `estimate_id` INT DEFAULT NULL,
  `project_id` INT DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'draft',
  `subtotal` DECIMAL(15,2) DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `withholding_tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `withholding_tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `fed_rate` DECIMAL(5,2) DEFAULT 0.00,
  `fed_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) DEFAULT 0.00,
  `amount_paid` DECIMAL(15,2) DEFAULT 0.00,
  `balance_due` DECIMAL(15,2) DEFAULT 0.00,
  `payment_terms` VARCHAR(200) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `terms_conditions` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `company_id` INT DEFAULT NULL,
  `company_name` VARCHAR(200) DEFAULT NULL,
  `company_logo` TEXT DEFAULT NULL,
  `company_phone` VARCHAR(50) DEFAULT NULL,
  `company_email` VARCHAR(100) DEFAULT NULL,
  `company_address` TEXT DEFAULT NULL,
  `company_ntn` VARCHAR(50) DEFAULT NULL,
  `company_strn` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'invoice_items' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `invoice_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL,
  `unit` VARCHAR(20) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_price` DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_item_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'payments' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `invoice_id` INT NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'purchase_invoices' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `purchase_invoices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
  `supplier_id` INT DEFAULT NULL,
  `supplier_name` VARCHAR(200) NOT NULL,
  `supplier_ntn` VARCHAR(50) DEFAULT NULL,
  `supplier_address` TEXT DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `received_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'draft',
  `subtotal` DECIMAL(15,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `total_amount` DECIMAL(15,2) DEFAULT 0.00,
  `amount_paid` DECIMAL(15,2) DEFAULT 0.00,
  `balance_due` DECIMAL(15,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'purchase_items' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `purchase_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `product_name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quantity` DECIMAL(12,2) NOT NULL,
  `unit` VARCHAR(20) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT 17.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_price` DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pi_item_pi` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'purchase_payments' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `purchase_payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `purchase_id` INT NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pp_pi` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'expenses' => [
        'ddl' => "CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `expense_no` VARCHAR(50) DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT 'other',
  `description` TEXT NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `vendor_name` VARCHAR(200) DEFAULT NULL,
  `receipt_ref` VARCHAR(100) DEFAULT NULL,
  `project_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
];

// Open MySQL export file
$mysqlFile = $backupDir . DIRECTORY_SEPARATOR . 'cpanel_database.sql';
$out = "-- ========================================================\n";
$out .= "-- DATAPOINT Business Management & Invoicing System\n";
$out .= "-- cPanel MySQL / MariaDB Production Database Dump\n";
$out .= "-- Exported on: " . date('Y-m-d H:i:s') . "\n";
$out .= "-- Compatible with: cPanel phpMyAdmin, MySQL 5.7+, MariaDB 10.3+\n";
$out .= "-- ========================================================\n\n";
$out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$out .= "SET NAMES utf8mb4;\n";
$out .= "SET time_zone = '+00:00';\n\n";

foreach ($mysqlTables as $tblName => $tblMeta) {
    $out .= "-- --------------------------------------------------------\n";
    $out .= "-- Table structure for table `{$tblName}`\n";
    $out .= "-- --------------------------------------------------------\n";
    $out .= "DROP TABLE IF EXISTS `{$tblName}`;\n";
    $out .= $tblMeta['ddl'] . "\n\n";

    // Query data from SQLite
    if (db_table_exists($db, $tblName)) {
        $stmt = $db->query("SELECT * FROM `{$tblName}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $out .= "-- Dumping data for table `{$tblName}`\n";
            $cols = array_keys($rows[0]);
            $quotedCols = array_map(fn($c) => "`{$c}`", $cols);
            $out .= "INSERT INTO `{$tblName}` (" . implode(', ', $quotedCols) . ") VALUES\n";

            $rowSqls = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($cols as $col) {
                    $v = $row[$col];
                    if ($v === null) {
                        $vals[] = "NULL";
                    } elseif (is_numeric($v) && !str_starts_with((string)$v, '+') && !str_starts_with((string)$v, '0')) {
                        $vals[] = (string)$v;
                    } else {
                        // Escape string for SQL
                        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v);
                        $escaped = str_replace(["\r", "\n"], ["\\r", "\\n"], $escaped);
                        $vals[] = "'{$escaped}'";
                    }
                }
                $rowSqls[] = "(" . implode(', ', $vals) . ")";
            }

            $out .= implode(",\n", $rowSqls) . ";\n\n";
        }
    }
}

$out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
$out .= "-- End of Database Dump\n";

file_put_contents($mysqlFile, $out);
echo "[✓] Created cPanel MySQL/phpMyAdmin dump: cpanel_backup/cpanel_database.sql (" . round(strlen($out) / 1024, 2) . " KB)\n";

// 3. Create .env template for cPanel deployment
$cpanelEnvFile = $backupDir . DIRECTORY_SEPARATOR . '.env.cpanel';
$cpanelEnv = <<<'ENV'
# ========================================================
# DATAPOINT Invoicing - cPanel Production Environment Configuration
# Copy this file to .env in your cPanel document root
# ========================================================

# OPTION A: MySQL on cPanel (Recommended - use credentials from cPanel -> MySQL Databases)
DATABASE_URL=mysql://cpaneluser_dbuser:StrongPassword123!@127.0.0.1:3306/cpaneluser_dptech

# OPTION B: SQLite on cPanel (If you prefer not using MySQL)
# DATABASE_URL=sqlite:///./storage/dptech.db

# Security Key - Generate a unique random secret
SECRET_KEY=dptech_cpanel_prod_8f3a9e217d84b5c6e0123456789abcdef0123456789
ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=1440

# Initial Admin Credentials (Already configured in database dump)
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@dptech.local
ADMIN_PASSWORD=admin123

# Primary Company Details
COMPANY_NAME=DATAPOINT Technologies
COMPANY_ADDRESS=G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh
COMPANY_PHONE=+923167788990
COMPANY_MOBILE=+923167788990
COMPANY_EMAIL=info@datapointtechnology.com

# Email / SMTP Configuration
EMAIL_HOST=mail.datapointtechnology.com
EMAIL_PORT=587
EMAIL_USER=info@datapointtechnology.com
EMAIL_PASS=YourEmailPasswordHere
ENV;

file_put_contents($cpanelEnvFile, $cpanelEnv);
echo "[✓] Created cPanel configuration template: cpanel_backup/.env.cpanel\n";

// 4. Create README with simple step-by-step cPanel instructions
$readmeFile = $backupDir . DIRECTORY_SEPARATOR . 'README_CPANEL_DEPLOYMENT.md';
$readme = <<<'MD'
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
MD;

file_put_contents($readmeFile, $readme);
echo "[✓] Created Step-by-Step Guide: cpanel_backup/README_CPANEL_DEPLOYMENT.md\n";

echo "\n========================================================\n";
echo "  cPanel Backup Package Generated Successfully!        \n";
echo "  Location: f:\\AgenticAi\\Projects\\Dptech-Invoicing\\cpanel_backup\\\n";
echo "========================================================\n";
