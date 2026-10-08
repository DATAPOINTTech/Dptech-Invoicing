-- ========================================================
-- DATAPOINT Business Management & Invoicing System
-- cPanel MySQL / MariaDB Production Database Dump
-- Exported on: 2026-10-07 17:15:39
-- Compatible with: cPanel phpMyAdmin, MySQL 5.7+, MariaDB 10.3+
-- ========================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`id`, `username`, `email`, `hashed_password`, `full_name`, `phone`, `role`, `is_active`, `permissions`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'info@datapointtechnology.com', '$2y$10$GU4XDoQs5I9ec5vfEIjGiOnbe59JwsMUsRCDyG6iCXlxd5zIZRZMK', 'System Administrator', '+92 316 7788990', 'admin', 1, '{"all":true}', '2026-10-07 10:54:30', '2026-10-07 10:54:30');

-- --------------------------------------------------------
-- Table structure for table `companies`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE IF NOT EXISTS `companies` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `companies`
INSERT INTO `companies` (`id`, `name`, `code`, `logo_url`, `email`, `phone`, `mobile`, `address`, `city`, `ntn`, `strn`, `terms`, `is_default`, `markup_percent`, `sort_order`, `created_at`, `updated_at`, `website`, `whatsapp`) VALUES
(1, 'DATAPOINT Technologies', 'DPT', '/static/img/logo.png', 'sales@datapointtechnology.com', '+92 316 7788990', '+923167788990', 'G 32 Shayas Residnece , Jamshoro Road , Hyderabad , Sindh.', 'Hyderabad', '7192834-5', '17-00-7192-834-11', '1. Payment: Net 30 days from invoice date.\n2. Prices are subject to prevailing government taxes.\n3. Validity: 15 days from issuance.', 1, '0', 1, '2026-10-07 10:54:30', '2026-10-07 16:56:01', NULL, NULL),
(2, 'Pakistan Technocrates Works & Services', 'PT', '/static/uploads/logo_6ac6793528d74.png', 'info@pakistantechnocrates.com', '+92 21 34567891', '+92 300 2345678', 'Suite 204, Commercial Zone, Qasimabad', 'Hyderabad', '8203945-6', '17-00-8203-945-22', '1. Payment: Within 15 days upon milestone completion.\n2. Deliverables covered under 1-year standard warranty.\n3. Validity: 15 days.', '0', 1, 2, '2026-10-07 10:54:30', '2026-10-07 16:56:01', NULL, NULL),
(3, 'M Tech Cybernet and Electronics', 'MTECH', '/static/uploads/logo_6ac6794d40860.png', 'billing@mtechsolutions.com', '+92 21 34567892', '+92 300 3456789', 'Floor 3, Executive Tower, Auto Bhan Road', 'Hyderabad', '9314056-7', '17-00-9314-056-33', '1. Payment due upon delivery and acceptance.\n2. All supplies strictly verified against manufacturer datasheets.\n3. Validity: 15 days.', '0', 2, 3, '2026-10-07 10:54:30', '2026-10-07 16:56:01', NULL, NULL);

-- --------------------------------------------------------
-- Table structure for table `clients`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE IF NOT EXISTS `clients` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `clients`
INSERT INTO `clients` (`id`, `name`, `company`, `email`, `phone`, `mobile`, `address`, `city`, `province`, `ntn`, `strn`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Sindh University', 'UoS Jamshoro', 'cctv@usindh.edu.pk', '022-9213181', NULL, 'Jamshoro Campus', NULL, NULL, NULL, NULL, NULL, 1, '2026-10-07 12:15:28', '2026-10-07 12:15:28');

-- --------------------------------------------------------
-- Table structure for table `suppliers`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE IF NOT EXISTS `suppliers` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `products`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `inventory`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL UNIQUE,
  `quantity` DECIMAL(12,2) DEFAULT 0.00,
  `warehouse` VARCHAR(100) DEFAULT 'Main',
  `location` VARCHAR(100) DEFAULT NULL,
  `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `stock_movements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `price_list`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `price_list`;
CREATE TABLE IF NOT EXISTS `price_list` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `projects`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `projects`;
CREATE TABLE IF NOT EXISTS `projects` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `estimates`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `estimates`;
CREATE TABLE IF NOT EXISTS `estimates` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `estimates`
INSERT INTO `estimates` (`id`, `estimate_no`, `client_id`, `project_id`, `title`, `estimate_date`, `valid_until`, `status`, `subtotal`, `discount_percent`, `discount_amount`, `tax_rate`, `tax_amount`, `total_amount`, `terms_conditions`, `notes`, `created_by`, `created_at`, `updated_at`, `company_id`, `company_name`, `company_logo`, `company_phone`, `company_email`, `company_address`, `company_ntn`, `company_strn`, `batch_id`, `markup_percent`) VALUES
(1, 'EST-TEST-1791375328', 1, NULL, 'ACTIVE COMPONENTS-ZONE-1', '2026-06-04', '2026-06-19', 'converted', 260000, '0', '0', 18, 46800, 306800, 'Terms valid for 15 days', 'Testing notes', 1, '2026-10-07 12:15:28', '2026-10-07 12:15:28', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, NULL, '0'),
(2, 'EST-TEST-1791375385', 1, NULL, 'ACTIVE COMPONENTS-ZONE-1', '2026-06-04', '2026-06-19', 'converted', 260000, '0', '0', 18, 46800, 306800, 'Terms valid for 15 days', 'Testing notes', 1, '2026-10-07 12:16:25', '2026-10-07 12:16:25', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, NULL, '0'),
(6, 'EST-TEST-1791375661', 1, NULL, 'ACTIVE COMPONENTS-ZONE-1', '2026-06-04', '2026-06-19', 'converted', 260000, '0', '0', 18, 46800, 306800, 'Terms valid for 15 days', 'Testing notes', 1, '2026-10-07 12:21:01', '2026-10-07 12:21:01', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, NULL, '0'),
(13, 'EST-202610-00007', 1, NULL, 'CCTV', '2026-10-07', '2026-10-22', 'draft', 150000, '0', '0', 18, 27000, 177000, '1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Delivery: As per agreed timeline', '', 1, '2026-10-07 17:01:35', '2026-10-07 17:01:35', 1, 'DATAPOINT Technologies', '/static/img/logo.png', '+92 316 7788990', 'sales@datapointtechnology.com', 'G 32 Shayas Residnece , Jamshoro Road , Hyderabad , Sindh.', '7192834-5', '17-00-7192-834-11', 'GRP-20261007-351BC7', '0'),
(14, 'EST-202610-00014', 1, NULL, 'CCTV', '2026-10-07', '2026-10-22', 'draft', 151500, '0', '0', 18, 27270, 178770, '1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Delivery: As per agreed timeline', '', 1, '2026-10-07 17:01:35', '2026-10-07 17:01:35', 2, 'Pakistan Technocrates Works & Services', '/static/uploads/logo_6ac6793528d74.png', '+92 21 34567891', 'info@pakistantechnocrates.com', 'Suite 204, Commercial Zone, Qasimabad', '8203945-6', '17-00-8203-945-22', 'GRP-20261007-351BC7', 1),
(15, 'EST-202610-00015', 1, NULL, 'CCTV', '2026-10-07', '2026-10-22', 'approved', 153000, '0', '0', 18, 27540, 180540, '1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Delivery: As per agreed timeline', '', 1, '2026-10-07 17:01:35', '2026-10-07 17:02:10', 3, 'M Tech Cybernet and Electronics', '/static/uploads/logo_6ac6794d40860.png', '+92 21 34567892', 'billing@mtechsolutions.com', 'Floor 3, Executive Tower, Auto Bhan Road', '9314056-7', '17-00-9314-056-33', 'GRP-20261007-351BC7', 2);

-- --------------------------------------------------------
-- Table structure for table `estimate_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `estimate_items`;
CREATE TABLE IF NOT EXISTS `estimate_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `estimate_items`
INSERT INTO `estimate_items` (`id`, `estimate_id`, `product_id`, `description`, `quantity`, `unit`, `unit_price`, `tax_rate`, `tax_amount`, `total_price`, `model_make`) VALUES
(1, 1, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(2, 1, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(3, 2, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(4, 2, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(8, 6, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(9, 6, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(16, 13, NULL, '1043 Camera', 12, 'pcs', 12500, 18, 27000, 177000, 'HIKVISION'),
(17, 14, NULL, '1043 Camera', 12, 'pcs', 12625, 18, 27270, 178770, 'HIKVISION'),
(18, 15, NULL, '1043 Camera', 12, 'pcs', 12750, 18, 27540, 180540, 'HIKVISION');

-- --------------------------------------------------------
-- Table structure for table `invoices`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE IF NOT EXISTS `invoices` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `invoices`
INSERT INTO `invoices` (`id`, `invoice_no`, `client_id`, `estimate_id`, `project_id`, `invoice_date`, `due_date`, `status`, `subtotal`, `discount_percent`, `discount_amount`, `tax_rate`, `tax_amount`, `withholding_tax_rate`, `withholding_tax_amount`, `fed_rate`, `fed_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_terms`, `notes`, `terms_conditions`, `created_by`, `created_at`, `updated_at`, `company_id`, `company_name`, `company_logo`, `company_phone`, `company_email`, `company_address`, `company_ntn`, `company_strn`, `title`) VALUES
(1, 'INV-202610-00001', 1, 1, NULL, '2026-10-07', '2026-06-19', 'draft', 260000, '0', '0', 18, 46800, '0', '0', '0', '0', 306800, '0', 306800, 'Terms valid for 15 days', 'Testing notes', 'Terms valid for 15 days', 1, '2026-10-07 12:15:28', '2026-10-07 12:15:28', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, 'ACTIVE COMPONENTS-ZONE-1'),
(2, 'INV-202610-00002', 1, 2, NULL, '2026-10-07', '2026-06-19', 'partially_paid', 260000, '0', '0', 18, 46800, '0', '0', '0', '0', 306800, 150000, 156800, 'Terms valid for 15 days', 'Testing notes', 'Terms valid for 15 days', 1, '2026-10-07 12:16:25', '2026-10-07 12:16:25', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, 'ACTIVE COMPONENTS-ZONE-1'),
(4, 'INV-202610-00003', 1, 6, NULL, '2026-10-07', '2026-06-19', 'partially_paid', 260000, '0', '0', 18, 46800, '0', '0', '0', '0', 306800, 150000, 156800, 'Terms valid for 15 days', 'Testing notes', 'Terms valid for 15 days', 1, '2026-10-07 12:21:01', '2026-10-07 12:21:01', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, 'ACTIVE COMPONENTS-ZONE-1'),
(6, 'INV-202610-00005', 1, NULL, NULL, '2026-10-07', '2026-11-06', 'draft', 264000, 5, 13200, 18, 45144, 4, 10032, '0', '0', 285912, '0', 285912, 'Net 30 Days', 'Invoice created via automated test.', NULL, 1, '2026-10-07 13:21:42', '2026-10-07 13:21:42', 1, 'DATAPOINT Technologies', '/static/img/logo.png', '+92 316 7788990', 'info@datapointtechnology.com', 'G 32 Shayas Residnece , Jamshoro Road , Hyderabad , Sindh.', '7192834-5', '17-00-7192-834-11', 'NETWORKING GEAR & CAMERAS - FLOOR 1 (UPDATED)');

-- --------------------------------------------------------
-- Table structure for table `invoice_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE IF NOT EXISTS `invoice_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `invoice_items`
INSERT INTO `invoice_items` (`id`, `invoice_id`, `product_id`, `description`, `quantity`, `unit`, `unit_price`, `tax_rate`, `tax_amount`, `total_price`, `model_make`) VALUES
(1, 1, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(2, 1, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(3, 2, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(4, 2, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(6, 4, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(7, 4, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4'),
(12, 6, NULL, 'Hikvision IP Camera 4MP Bullet', 4, 'pcs', 12500, 18, 9000, 59000, 'DS-2CD2043G2-I'),
(13, 6, NULL, 'Cisco 24-Port Gigabit PoE+ Managed Switch', 1, 'pcs', 145000, 18, 26100, 171100, 'CBS350-24P-4G'),
(14, 6, NULL, 'Cat6 UTP Ethernet Cable 305m Roll', 2, 'roll', 22000, 18, 7920, 51920, 'Schneider Actassi'),
(15, 6, NULL, 'Professional Installation & Termination Service', 1, 'job', 25000, 18, 4500, 29500, 'Labor / On-site');

-- --------------------------------------------------------
-- Table structure for table `payments`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE IF NOT EXISTS `payments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payments`
INSERT INTO `payments` (`id`, `invoice_id`, `amount`, `payment_date`, `payment_method`, `reference_no`, `notes`, `created_by`, `created_at`) VALUES
(1, 2, 150000, '2026-10-07', 'bank_transfer', 'TXN-998877', 'Advance partial payment', 1, '2026-10-07 12:16:25'),
(4, 4, 150000, '2026-10-07', 'bank_transfer', 'TXN-998877', 'Advance partial payment', 1, '2026-10-07 12:21:01');

-- --------------------------------------------------------
-- Table structure for table `purchase_invoices`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `purchase_invoices`;
CREATE TABLE IF NOT EXISTS `purchase_invoices` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `purchase_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE IF NOT EXISTS `purchase_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `purchase_payments`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `purchase_payments`;
CREATE TABLE IF NOT EXISTS `purchase_payments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `expenses`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE IF NOT EXISTS `expenses` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
-- End of Database Dump
