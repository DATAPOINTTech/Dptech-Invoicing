<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$db = database_connection();

echo "Seeding products & live price list catalog...\n";

$products = [
    // 1. CCTV & Surveillance
    [
        'name' => 'Hikvision 4MP ColorVu Network Bullet Camera (DS-2CD1047G2H-LIU)',
        'description' => '4 MP ColorVu fixed bullet network camera with 24/7 colorful imaging, smart hybrid light, built-in mic, IP67 weatherproof',
        'category' => 'CCTV',
        'sku' => 'HK-4MP-CVU-01',
        'unit_price' => 22000.0,
        'cost_price' => 18500.0,
        'unit' => 'No.',
        'image_url' => '/static/img/cctv_camera.png'
    ],
    [
        'name' => 'Hikvision 4MP Smart Hybrid Bullet Camera (DS-2CD1043G2-LIU)',
        'description' => '4 MP Smart Hybrid Light bullet network camera, 4mm lens, human & vehicle detection, H.265+ compression',
        'category' => 'CCTV',
        'sku' => 'HK-4MP-HYB-02',
        'unit_price' => 16500.0,
        'cost_price' => 14000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/cctv_camera.png'
    ],
    [
        'name' => 'Hikvision 64-Channel NVR AcuSense (DS-7764NXI-M4)',
        'description' => '64 channel 1.5U 4K NVR, AcuSense perimeter protection, up to 4 SATA interfaces, 384 Mbps incoming bandwidth',
        'category' => 'CCTV',
        'sku' => 'HK-NVR-64CH-01',
        'unit_price' => 150000.0,
        'cost_price' => 132000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/nvr.png'
    ],
    [
        'name' => 'Hikvision 32-Channel 4K NVR (DS-7732NI-K4)',
        'description' => '32 channel embedded plug and play 4K NVR, supports 4 HDDs up to 40TB storage, HDMI/VGA independent outputs',
        'category' => 'CCTV',
        'sku' => 'HK-NVR-32CH-02',
        'unit_price' => 78000.0,
        'cost_price' => 67000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/nvr.png'
    ],
    [
        'name' => 'Western Digital Purple Pro 8TB Surveillance HDD (WD80PURZ)',
        'description' => '8TB enterprise surveillance hard drive, AllFrame technology, 5600 RPM, 256MB cache, 24/7 reliability',
        'category' => 'CCTV',
        'sku' => 'WD-PUR-8TB',
        'unit_price' => 52000.0,
        'cost_price' => 45000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/hdd.png'
    ],
    [
        'name' => 'Western Digital Purple 4TB Surveillance HDD',
        'description' => '4TB 3.5-inch SATA 6 Gb/s 5400 RPM surveillance drive engineered for high-definition security systems',
        'category' => 'CCTV',
        'sku' => 'WD-PUR-4TB',
        'unit_price' => 28500.0,
        'cost_price' => 24500.0,
        'unit' => 'No.',
        'image_url' => '/static/img/hdd.png'
    ],

    // 2. Networking & Infrastructure
    [
        'name' => 'Huawei 48-Port All-SFP Managed Core Switch',
        'description' => '48-Port full SFP optical core switch, high performance L3 routing, dual redundant AC power supplies, 10G uplink capable',
        'category' => 'Networking',
        'sku' => 'HW-48P-SFP-CORE',
        'unit_price' => 300000.0,
        'cost_price' => 260000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/switch.png'
    ],
    [
        'name' => 'Cisco Catalyst 24-Port Gigabit Managed PoE+ Switch',
        'description' => '24x 10/100/1000 PoE+ ports (370W budget), 4x 1G SFP uplinks, enterprise management, Layer 2+ switching',
        'category' => 'Networking',
        'sku' => 'CS-24P-POE-SW',
        'unit_price' => 125000.0,
        'cost_price' => 108000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/switch.png'
    ],
    [
        'name' => '8-Port Gigabit PoE+ Industrial Field Switch with 2x SFP Uplink',
        'description' => '8x 10/100/1000 Base-T PoE+ ports, 2x Gigabit SFP optical slots, 120W total PoE budget, surge protection 6kV',
        'category' => 'Networking',
        'sku' => 'IND-8P-POE-SFP',
        'unit_price' => 19500.0,
        'cost_price' => 16000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/switch.png'
    ],
    [
        'name' => 'MikroTik CCR2004-16G-2S+ Cloud Core Enterprise Router',
        'description' => '16 Gigabit Ethernet ports, 2x 10G SFP+ cages, Annapurna Labs quad core 1.7GHz CPU, 4GB RAM, RouterOS v7',
        'category' => 'Networking',
        'sku' => 'MT-CCR2004-RTR',
        'unit_price' => 145000.0,
        'cost_price' => 128000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/router.png'
    ],
    [
        'name' => 'Ubiquiti UniFi U6-Pro WiFi 6 High-Performance Access Point',
        'description' => 'Dual-band WiFi 6 AP, up to 5.3 Gbps aggregate throughput, 4x4 MU-MIMO, powered by 802.3at PoE+',
        'category' => 'Networking',
        'sku' => 'UB-U6-PRO-AP',
        'unit_price' => 45000.0,
        'cost_price' => 39000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/ap.png'
    ],
    [
        'name' => 'Enterprise Server Rack 42U Heavy Duty Glass Door',
        'description' => '42U 600x1000mm standard 19-inch server cabinet with perforated mesh/glass door, 4 fans, 8-way PDU, cable management',
        'category' => 'Networking',
        'sku' => 'SR-42U-HVY-RCK',
        'unit_price' => 85000.0,
        'cost_price' => 70000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/rack.png'
    ],
    [
        'name' => 'Network Wall Mount Cabinet 9U / 12U',
        'description' => 'Standard 19-inch 9U glass front door wall mounted network rack with fan tray and 6-socket PDU',
        'category' => 'Networking',
        'sku' => 'WM-9U-NET-RCK',
        'unit_price' => 18500.0,
        'cost_price' => 15000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/rack.png'
    ],
    [
        'name' => 'Schneider / D-Link Cat6 UTP 305M Pure Copper Cable Roll',
        'description' => '305-meter roll 4-pair 23AWG 100% solid bare copper Cat6 network cable with divider, gigabit verified',
        'category' => 'Networking',
        'sku' => 'C6-UTP-305M-COP',
        'unit_price' => 28000.0,
        'cost_price' => 23500.0,
        'unit' => 'Roll',
        'image_url' => '/static/img/cable.png'
    ],

    // 3. Hardware & Computing
    [
        'name' => 'Dell Latitude 5440 Core i7 13th Gen Business Laptop',
        'description' => 'Intel Core i7-1355U, 16GB DDR5 RAM, 512GB PCIe NVMe SSD, 14.0" FHD IPS Anti-Glare, Windows 11 Pro, Backlit KB',
        'category' => 'Laptop',
        'sku' => 'DL-LAT-5440-I7',
        'unit_price' => 245000.0,
        'cost_price' => 220000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/laptop.png'
    ],
    [
        'name' => 'Dell Latitude 3440 Core i5 13th Gen Corporate Laptop',
        'description' => 'Intel Core i5-1335U 10-Core, 8GB DDR4 RAM, 256GB SSD, 14.0" FHD Display, 3-cell battery, 1 Year Warranty',
        'category' => 'Laptop',
        'sku' => 'DL-LAT-3440-I5',
        'unit_price' => 165000.0,
        'cost_price' => 148000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/laptop.png'
    ],
    [
        'name' => 'HP ProDesk 400 G9 Core i7 High-Performance Desktop PC',
        'description' => 'Intel Core i7-13700 16-Core Processor, 16GB RAM, 512GB NVMe SSD, HP USB Keyboard & Mouse, Tower Chassis',
        'category' => 'Desktop',
        'sku' => 'HP-PD-400-I7',
        'unit_price' => 185000.0,
        'cost_price' => 165000.0,
        'unit' => 'Set',
        'image_url' => '/static/img/desktop.png'
    ],
    [
        'name' => 'Custom Core i5 Workstation / Office Desktop Set',
        'description' => 'Core i5 10th/11th Gen, 16GB RAM, 256GB NVMe + 1TB HDD, 22-inch IPS LED Monitor, Case with 500W 80+ PSU',
        'category' => 'Desktop',
        'sku' => 'PC-I5-OFFICE-SET',
        'unit_price' => 88000.0,
        'cost_price' => 76000.0,
        'unit' => 'Set',
        'image_url' => '/static/img/desktop.png'
    ],
    [
        'name' => 'HP LaserJet Pro MFP M428fdw Wireless All-in-One Printer',
        'description' => 'Monochrome laser multifunction printer (Print, Copy, Scan, Fax), duplex printing, dual-band Wi-Fi, 40 ppm',
        'category' => 'Printer',
        'sku' => 'HP-MFP-M428FDW',
        'unit_price' => 135000.0,
        'cost_price' => 120000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/printer.png'
    ],
    [
        'name' => 'Dell 24-Inch IPS Full HD Borderless Monitor (P2422H)',
        'description' => '1920 x 1080 at 60 Hz, IPS technology, height adjustable stand, HDMI, DisplayPort, VGA & USB 3.0 Hub',
        'category' => 'Accessories',
        'sku' => 'DL-P2422H-MON',
        'unit_price' => 48000.0,
        'cost_price' => 42000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/monitor.png'
    ],

    // 4. Power & Services
    [
        'name' => 'APC Smart-UPS 3000VA LCD RM 2U 230V Online UPS (SMT3000RMI2U)',
        'description' => '3000VA / 2700W Line-Interactive Rackmount UPS, pure sine wave output, SmartSlot, LCD status display',
        'category' => 'Power',
        'sku' => 'APC-SMT-3000-UPS',
        'unit_price' => 380000.0,
        'cost_price' => 340000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/ups.png'
    ],
    [
        'name' => 'Homage / Inverex 1200VA / 1000W Inverter UPS',
        'description' => 'Pure sine wave inverter with smart battery charging, over-voltage protection, supports 12V 100Ah-200Ah battery',
        'category' => 'Power',
        'sku' => 'INV-1200VA-UPS',
        'unit_price' => 36000.0,
        'cost_price' => 31000.0,
        'unit' => 'No.',
        'image_url' => '/static/img/ups.png'
    ],
    [
        'name' => 'CCTV Camera Site Installation, Mounting & Testing Service',
        'description' => 'Complete camera mounting, cable termination, conduit dressing, angle tuning, and DVR/NVR configuration per point',
        'category' => 'Services',
        'sku' => 'SRV-CCTV-INST-PT',
        'unit_price' => 2500.0,
        'cost_price' => 1500.0,
        'unit' => 'Point',
        'image_url' => '/static/img/service.png'
    ],
    [
        'name' => 'Network Cabling, Patching & IO Termination Service',
        'description' => 'Cat6 UTP cable pulling, conduit piping, faceplate IO punch-down, patch panel tagging & fluke continuity test',
        'category' => 'Services',
        'sku' => 'SRV-NET-CBL-PT',
        'unit_price' => 1800.0,
        'cost_price' => 1000.0,
        'unit' => 'Node',
        'image_url' => '/static/img/service.png'
    ],
    [
        'name' => 'Annual Corporate IT Maintenance Contract (AMC) per Workstation',
        'description' => 'Preventive hardware maintenance, OS & security updates, printer support, network troubleshooting, monthly visits',
        'category' => 'Services',
        'sku' => 'SRV-AMC-CORP-PC',
        'unit_price' => 3500.0,
        'cost_price' => 1800.0,
        'unit' => 'PC/Month',
        'image_url' => '/static/img/service.png'
    ]
];

$insProd = $db->prepare('
    INSERT INTO products (name, description, category, sku, unit_price, cost_price, unit, tax_rate, tax_inclusive, is_active, min_stock_level, max_stock_level, created_at, updated_at)
    VALUES (:name, :desc, :cat, :sku, :uprice, :cprice, :unit, 17.0, 0, 1, 5, 50, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');

$insPrice = $db->prepare('
    INSERT INTO price_list (name, description, category, unit, unit_price, currency, effective_date, source, image_url, is_active, created_at, updated_at)
    VALUES (:name, :desc, :cat, :unit, :uprice, "PKR", CURRENT_DATE, "DPTech Catalog", :img, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');

$prodCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($prodCount === 0) {
    foreach ($products as $p) {
        $insProd->execute([
            'name' => $p['name'],
            'desc' => $p['description'],
            'cat' => $p['category'],
            'sku' => $p['sku'],
            'uprice' => $p['unit_price'],
            'cprice' => $p['cost_price'],
            'unit' => $p['unit']
        ]);
        $prodId = (int) $db->lastInsertId();

        // Also add initial stock movement and inventory
        $db->prepare('INSERT INTO inventory (product_id, quantity, warehouse, location, last_updated) VALUES (?, 15, "Main Warehouse", "A-1", CURRENT_TIMESTAMP)')
           ->execute([$prodId]);
    }
    echo "✓ Seeded " . count($products) . " products into products table.\n";
} else {
    echo "• Products table already has {$prodCount} rows.\n";
}

$priceCount = (int) $db->query('SELECT COUNT(*) FROM price_list')->fetchColumn();
if ($priceCount === 0) {
    foreach ($products as $p) {
        $insPrice->execute([
            'name' => $p['name'],
            'desc' => $p['description'],
            'cat' => $p['category'],
            'unit' => $p['unit'],
            'uprice' => $p['unit_price'],
            'img' => $p['image_url'] ?? null
        ]);
    }
    echo "✓ Seeded " . count($products) . " records into price_list table.\n";
} else {
    echo "• Price_list table already has {$priceCount} rows.\n";
}

echo "Seeding completed successfully.\n";

