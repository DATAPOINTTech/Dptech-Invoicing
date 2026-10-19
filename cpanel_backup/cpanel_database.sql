-- ========================================================
-- DATAPOINT Business Management & Invoicing System
-- cPanel MySQL / MariaDB Production Database Dump
-- Exported on: 2026-10-19 17:07:01
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
(1, 'admin', 'admin@dptech.local', '$2y$10$3xEhL0MmTXsXbpw4AWDAgeR2XJW62OamFvhp05SfxZjBSarUxhFi2', 'System Administrator', '+92223669326', 'admin', 1, '{"all":true}', '2026-10-09 05:05:56', '2026-10-19 17:06:49');

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
(1, 'DATAPOINT Technologies', 'DPT', '/static/img/logo.png', 'info@datapointtechnology.com', '+92223669326', '+923167788990', 'G 32 Shayas Residence Jamshoro Road, Hyderabad Sindh', 'Hyderabad', '7192834-5', '17-00-7192-834-11', '1. Payment: Net 30 days from invoice date.\n2. Prices are subject to prevailing government taxes.\n3. Validity: 15 days from issuance.', 1, '0', 1, '2026-10-09 05:05:56', '2026-10-12 09:57:28', NULL, NULL),
(2, 'Pakistan Technocrates Works & Services', 'PTC', '/static/uploads/logo_6ac6793528d74.png', 'contact@paktechnocrates.com', '+92 21 34567891', '+92 300 2345678', 'Suite 204, Commercial Zone, Qasimabad', 'Hyderabad', '2345678-9', '17-00-2345-678-90', '1. Payment: Within 15 days upon milestone completion.\n2. Supplies strictly in compliance with engineering specifications.\n3. Validity: 15 days.', '0', 1, 2, '2026-10-09 05:05:56', '2026-10-12 09:57:28', NULL, NULL),
(3, 'M tech Cybernet and Electronics', 'MTC', '/static/uploads/logo_6ac6794d40860.png', 'sales@mtechcybernet.com', '+92 21 34567892', '+92 300 3456789', 'Floor 3, Executive Tower, Auto Bhan Road', 'Hyderabad', '3456789-0', '17-00-3456-789-01', '1. Payment due upon delivery and acceptance.\n2. Telecommunications and hardware covered under standard warranty.\n3. Validity: 15 days.', '0', 2, 3, '2026-10-09 05:05:56', '2026-10-12 09:57:28', NULL, NULL);

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
(1, 'Sindh University', 'UoS Jamshoro', 'cctv@usindh.edu.pk', '022-9213181', NULL, 'Jamshoro Campus', NULL, NULL, NULL, NULL, NULL, 1, '2026-10-19 09:14:00', '2026-10-19 09:14:00');

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

-- Dumping data for table `products`
INSERT INTO `products` (`id`, `name`, `description`, `category`, `sku`, `unit_price`, `cost_price`, `unit`, `tax_rate`, `tax_inclusive`, `hs_code`, `is_active`, `min_stock_level`, `max_stock_level`, `created_at`, `updated_at`) VALUES
(1, 'Hikvision 4MP ColorVu Network Bullet Camera (DS-2CD1047G2H-LIU)', '4 MP ColorVu fixed bullet network camera with 24/7 colorful imaging, smart hybrid light, built-in mic, IP67 weatherproof', 'CCTV', 'HK-4MP-CVU-01', 20350, 18500, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(2, 'Hikvision 4MP Smart Hybrid Bullet Camera (DS-2CD1043G2-LIU)', '4 MP Smart Hybrid Light bullet network camera, 4mm lens, human & vehicle detection, H.265+ compression', 'CCTV', 'HK-4MP-HYB-02', 16500, 14000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(3, 'Hikvision 64-Channel NVR AcuSense (DS-7764NXI-M4)', '64 channel 1.5U 4K NVR, AcuSense perimeter protection, up to 4 SATA interfaces, 384 Mbps incoming bandwidth', 'CCTV', 'HK-NVR-64CH-01', 150000, 132000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(4, 'Hikvision 32-Channel 4K NVR (DS-7732NI-K4)', '32 channel embedded plug and play 4K NVR, supports 4 HDDs up to 40TB storage, HDMI/VGA independent outputs', 'CCTV', 'HK-NVR-32CH-02', 78000, 67000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(5, 'Western Digital Purple Pro 8TB Surveillance HDD (WD80PURZ)', '8TB enterprise surveillance hard drive, AllFrame technology, 5600 RPM, 256MB cache, 24/7 reliability', 'CCTV', 'WD-PUR-8TB', 52000, 45000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(6, 'Western Digital Purple 4TB Surveillance HDD', '4TB 3.5-inch SATA 6 Gb/s 5400 RPM surveillance drive engineered for high-definition security systems', 'CCTV', 'WD-PUR-4TB', 28500, 24500, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(7, 'Huawei 48-Port All-SFP Managed Core Switch', '48-Port full SFP optical core switch, high performance L3 routing, dual redundant AC power supplies, 10G uplink capable', 'Networking', 'HW-48P-SFP-CORE', 300000, 260000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(8, 'Cisco Catalyst 24-Port Gigabit Managed PoE+ Switch', '24x 10/100/1000 PoE+ ports (370W budget), 4x 1G SFP uplinks, enterprise management, Layer 2+ switching', 'Networking', 'CS-24P-POE-SW', 125000, 108000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(9, '8-Port Gigabit PoE+ Industrial Field Switch with 2x SFP Uplink', '8x 10/100/1000 Base-T PoE+ ports, 2x Gigabit SFP optical slots, 120W total PoE budget, surge protection 6kV', 'Networking', 'IND-8P-POE-SFP', 19500, 16000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(10, 'MikroTik CCR2004-16G-2S+ Cloud Core Enterprise Router', '16 Gigabit Ethernet ports, 2x 10G SFP+ cages, Annapurna Labs quad core 1.7GHz CPU, 4GB RAM, RouterOS v7', 'Networking', 'MT-CCR2004-RTR', 145000, 128000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(11, 'Ubiquiti UniFi U6-Pro WiFi 6 High-Performance Access Point', 'Dual-band WiFi 6 AP, up to 5.3 Gbps aggregate throughput, 4x4 MU-MIMO, powered by 802.3at PoE+', 'Networking', 'UB-U6-PRO-AP', 45000, 39000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(12, 'Enterprise Server Rack 42U Heavy Duty Glass Door', '42U 600x1000mm standard 19-inch server cabinet with perforated mesh/glass door, 4 fans, 8-way PDU, cable management', 'Networking', 'SR-42U-HVY-RCK', 85000, 70000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(13, 'Network Wall Mount Cabinet 9U / 12U', 'Standard 19-inch 9U glass front door wall mounted network rack with fan tray and 6-socket PDU', 'Networking', 'WM-9U-NET-RCK', 18500, 15000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(14, 'Schneider / D-Link Cat6 UTP 305M Pure Copper Cable Roll', '305-meter roll 4-pair 23AWG 100% solid bare copper Cat6 network cable with divider, gigabit verified', 'Networking', 'C6-UTP-305M-COP', 28000, 23500, 'Roll', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(15, 'Dell Latitude 5440 Core i7 13th Gen Business Laptop', 'Intel Core i7-1355U, 16GB DDR5 RAM, 512GB PCIe NVMe SSD, 14.0" FHD IPS Anti-Glare, Windows 11 Pro, Backlit KB', 'Laptop', 'DL-LAT-5440-I7', 245000, 220000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(16, 'Dell Latitude 3440 Core i5 13th Gen Corporate Laptop', 'Intel Core i5-1335U 10-Core, 8GB DDR4 RAM, 256GB SSD, 14.0" FHD Display, 3-cell battery, 1 Year Warranty', 'Laptop', 'DL-LAT-3440-I5', 165000, 148000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(17, 'HP ProDesk 400 G9 Core i7 High-Performance Desktop PC', 'Intel Core i7-13700 16-Core Processor, 16GB RAM, 512GB NVMe SSD, HP USB Keyboard & Mouse, Tower Chassis', 'Desktop', 'HP-PD-400-I7', 185000, 165000, 'Set', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(18, 'Custom Core i5 Workstation / Office Desktop Set', 'Core i5 10th/11th Gen, 16GB RAM, 256GB NVMe + 1TB HDD, 22-inch IPS LED Monitor, Case with 500W 80+ PSU', 'Desktop', 'PC-I5-OFFICE-SET', 88000, 76000, 'Set', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(19, 'HP LaserJet Pro MFP M428fdw Wireless All-in-One Printer', 'Monochrome laser multifunction printer (Print, Copy, Scan, Fax), duplex printing, dual-band Wi-Fi, 40 ppm', 'Printer', 'HP-MFP-M428FDW', 135000, 120000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(20, 'Dell 24-Inch IPS Full HD Borderless Monitor (P2422H)', '1920 x 1080 at 60 Hz, IPS technology, height adjustable stand, HDMI, DisplayPort, VGA & USB 3.0 Hub', 'Accessories', 'DL-P2422H-MON', 48000, 42000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(21, 'APC Smart-UPS 3000VA LCD RM 2U 230V Online UPS (SMT3000RMI2U)', '3000VA / 2700W Line-Interactive Rackmount UPS, pure sine wave output, SmartSlot, LCD status display', 'Power', 'APC-SMT-3000-UPS', 380000, 340000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(22, 'Homage / Inverex 1200VA / 1000W Inverter UPS', 'Pure sine wave inverter with smart battery charging, over-voltage protection, supports 12V 100Ah-200Ah battery', 'Power', 'INV-1200VA-UPS', 36000, 31000, 'No.', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(23, 'CCTV Camera Site Installation, Mounting & Testing Service', 'Complete camera mounting, cable termination, conduit dressing, angle tuning, and DVR/NVR configuration per point', 'Services', 'SRV-CCTV-INST-PT', 2500, 1500, 'Point', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(24, 'Network Cabling, Patching & IO Termination Service', 'Cat6 UTP cable pulling, conduit piping, faceplate IO punch-down, patch panel tagging & fluke continuity test', 'Services', 'SRV-NET-CBL-PT', 1800, 1000, 'Node', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(25, 'Annual Corporate IT Maintenance Contract (AMC) per Workstation', 'Preventive hardware maintenance, OS & security updates, printer support, network troubleshooting, monthly visits', 'Services', 'SRV-AMC-CORP-PC', 3500, 1800, 'PC/Month', 17, '0', NULL, 1, 5, 50, '2026-10-09 05:05:56', '2026-10-09 05:05:56');

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

-- Dumping data for table `inventory`
INSERT INTO `inventory` (`id`, `product_id`, `quantity`, `warehouse`, `location`, `last_updated`) VALUES
(1, 1, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(2, 2, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(3, 3, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(4, 4, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(5, 5, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(6, 6, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(7, 7, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(8, 8, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(9, 9, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(10, 10, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(11, 11, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(12, 12, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(13, 13, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(14, 14, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(15, 15, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(16, 16, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(17, 17, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(18, 18, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(19, 19, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(20, 20, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(21, 21, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(22, 22, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(23, 23, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(24, 24, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56'),
(25, 25, 15, 'Main Warehouse', 'A-1', '2026-10-09 05:05:56');

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

-- Dumping data for table `price_list`
INSERT INTO `price_list` (`id`, `name`, `description`, `category`, `unit`, `unit_price`, `currency`, `effective_date`, `source`, `image_url`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Hikvision 4MP ColorVu Network Bullet Camera (DS-2CD1047G2H-LIU)', '4 MP ColorVu fixed bullet network camera with 24/7 colorful imaging, smart hybrid light, built-in mic, IP67 weatherproof', 'CCTV', 'No.', 22000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/cctv_camera.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(2, 'Hikvision 4MP Smart Hybrid Bullet Camera (DS-2CD1043G2-LIU)', '4 MP Smart Hybrid Light bullet network camera, 4mm lens, human & vehicle detection, H.265+ compression', 'CCTV', 'No.', 16500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/cctv_camera.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(3, 'Hikvision 64-Channel NVR AcuSense (DS-7764NXI-M4)', '64 channel 1.5U 4K NVR, AcuSense perimeter protection, up to 4 SATA interfaces, 384 Mbps incoming bandwidth', 'CCTV', 'No.', 150000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/nvr.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(4, 'Hikvision 32-Channel 4K NVR (DS-7732NI-K4)', '32 channel embedded plug and play 4K NVR, supports 4 HDDs up to 40TB storage, HDMI/VGA independent outputs', 'CCTV', 'No.', 78000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/nvr.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(5, 'Western Digital Purple Pro 8TB Surveillance HDD (WD80PURZ)', '8TB enterprise surveillance hard drive, AllFrame technology, 5600 RPM, 256MB cache, 24/7 reliability', 'CCTV', 'No.', 52000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/hdd.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(6, 'Western Digital Purple 4TB Surveillance HDD', '4TB 3.5-inch SATA 6 Gb/s 5400 RPM surveillance drive engineered for high-definition security systems', 'CCTV', 'No.', 28500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/hdd.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(7, 'Huawei 48-Port All-SFP Managed Core Switch', '48-Port full SFP optical core switch, high performance L3 routing, dual redundant AC power supplies, 10G uplink capable', 'Networking', 'No.', 300000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/switch.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(8, 'Cisco Catalyst 24-Port Gigabit Managed PoE+ Switch', '24x 10/100/1000 PoE+ ports (370W budget), 4x 1G SFP uplinks, enterprise management, Layer 2+ switching', 'Networking', 'No.', 125000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/switch.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(9, '8-Port Gigabit PoE+ Industrial Field Switch with 2x SFP Uplink', '8x 10/100/1000 Base-T PoE+ ports, 2x Gigabit SFP optical slots, 120W total PoE budget, surge protection 6kV', 'Networking', 'No.', 19500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/switch.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(10, 'MikroTik CCR2004-16G-2S+ Cloud Core Enterprise Router', '16 Gigabit Ethernet ports, 2x 10G SFP+ cages, Annapurna Labs quad core 1.7GHz CPU, 4GB RAM, RouterOS v7', 'Networking', 'No.', 145000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/router.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(11, 'Ubiquiti UniFi U6-Pro WiFi 6 High-Performance Access Point', 'Dual-band WiFi 6 AP, up to 5.3 Gbps aggregate throughput, 4x4 MU-MIMO, powered by 802.3at PoE+', 'Networking', 'No.', 45000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/ap.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(12, 'Enterprise Server Rack 42U Heavy Duty Glass Door', '42U 600x1000mm standard 19-inch server cabinet with perforated mesh/glass door, 4 fans, 8-way PDU, cable management', 'Networking', 'No.', 85000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/rack.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(13, 'Network Wall Mount Cabinet 9U / 12U', 'Standard 19-inch 9U glass front door wall mounted network rack with fan tray and 6-socket PDU', 'Networking', 'No.', 18500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/rack.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(14, 'Schneider / D-Link Cat6 UTP 305M Pure Copper Cable Roll', '305-meter roll 4-pair 23AWG 100% solid bare copper Cat6 network cable with divider, gigabit verified', 'Networking', 'Roll', 28000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/cable.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(15, 'Dell Latitude 5440 Core i7 13th Gen Business Laptop', 'Intel Core i7-1355U, 16GB DDR5 RAM, 512GB PCIe NVMe SSD, 14.0" FHD IPS Anti-Glare, Windows 11 Pro, Backlit KB', 'Laptop', 'No.', 245000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/laptop.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(16, 'Dell Latitude 3440 Core i5 13th Gen Corporate Laptop', 'Intel Core i5-1335U 10-Core, 8GB DDR4 RAM, 256GB SSD, 14.0" FHD Display, 3-cell battery, 1 Year Warranty', 'Laptop', 'No.', 165000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/laptop.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(17, 'HP ProDesk 400 G9 Core i7 High-Performance Desktop PC', 'Intel Core i7-13700 16-Core Processor, 16GB RAM, 512GB NVMe SSD, HP USB Keyboard & Mouse, Tower Chassis', 'Desktop', 'Set', 185000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/desktop.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(18, 'Custom Core i5 Workstation / Office Desktop Set', 'Core i5 10th/11th Gen, 16GB RAM, 256GB NVMe + 1TB HDD, 22-inch IPS LED Monitor, Case with 500W 80+ PSU', 'Desktop', 'Set', 88000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/desktop.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(19, 'HP LaserJet Pro MFP M428fdw Wireless All-in-One Printer', 'Monochrome laser multifunction printer (Print, Copy, Scan, Fax), duplex printing, dual-band Wi-Fi, 40 ppm', 'Printer', 'No.', 135000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/printer.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(20, 'Dell 24-Inch IPS Full HD Borderless Monitor (P2422H)', '1920 x 1080 at 60 Hz, IPS technology, height adjustable stand, HDMI, DisplayPort, VGA & USB 3.0 Hub', 'Accessories', 'No.', 48000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/monitor.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(21, 'APC Smart-UPS 3000VA LCD RM 2U 230V Online UPS (SMT3000RMI2U)', '3000VA / 2700W Line-Interactive Rackmount UPS, pure sine wave output, SmartSlot, LCD status display', 'Power', 'No.', 380000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/ups.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(22, 'Homage / Inverex 1200VA / 1000W Inverter UPS', 'Pure sine wave inverter with smart battery charging, over-voltage protection, supports 12V 100Ah-200Ah battery', 'Power', 'No.', 36000, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/ups.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(23, 'CCTV Camera Site Installation, Mounting & Testing Service', 'Complete camera mounting, cable termination, conduit dressing, angle tuning, and DVR/NVR configuration per point', 'Services', 'Point', 2500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/service.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(24, 'Network Cabling, Patching & IO Termination Service', 'Cat6 UTP cable pulling, conduit piping, faceplate IO punch-down, patch panel tagging & fluke continuity test', 'Services', 'Node', 1800, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/service.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56'),
(25, 'Annual Corporate IT Maintenance Contract (AMC) per Workstation', 'Preventive hardware maintenance, OS & security updates, printer support, network troubleshooting, monthly visits', 'Services', 'PC/Month', 3500, 'PKR', '2026-10-09', 'DPTech Catalog', '/static/img/service.png', 1, '2026-10-09 05:05:56', '2026-10-09 05:05:56');

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
(8, 'EST-TEST-1792401240', 1, NULL, 'ACTIVE COMPONENTS-ZONE-1', '2026-06-04', '2026-06-19', 'converted', 260000, '0', '0', 18, 46800, 306800, 'Terms valid for 15 days', 'Testing notes', 1, '2026-10-19 09:14:00', '2026-10-19 09:14:00', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, NULL, '0');

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
(8, 8, NULL, 'Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera', 5, 'No.', 22000, 18, 19800, 110000, 'Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera'),
(9, 8, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4');

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
(1, 'INV-202610-00001', 1, 8, NULL, '2026-10-19', '2026-06-19', 'partially_paid', 260000, '0', '0', 18, 46800, '0', '0', '0', '0', 306800, 150000, 156800, 'Terms valid for 15 days', 'Testing notes', 'Terms valid for 15 days', 1, '2026-10-19 09:14:00', '2026-10-19 09:14:00', NULL, 'DATAPOINT Technologies', NULL, NULL, NULL, NULL, '7178396-5', 3277876124452, 'ACTIVE COMPONENTS-ZONE-1');

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
(2, 1, NULL, '64-Channel NVR AcuSense', 1, 'No.', 150000, 18, 27000, 150000, 'Hikvision DS-7764NXI-M4');

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
(1, 1, 150000, '2026-10-19', 'bank_transfer', 'TXN-998877', 'Advance partial payment', 1, '2026-10-19 09:14:00');

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
