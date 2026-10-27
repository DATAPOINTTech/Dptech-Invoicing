<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/pdf_service.php';

$images = [
    'php/public/static/img/dptech_footer_banner.jpg',
    'php/public/static/img/dptech_logo_exact.jpg',
    'php/public/static/img/logo.png',
    'php/public/static/img/fbr_logo.png',
    'php/public/static/uploads/logo_6ac6793528d74.png',
    'php/public/static/uploads/logo_6ac6794d40860.png',
];

foreach ($images as $img) {
    $res = parse_image_for_pdf($img);
    if ($res) {
        echo "OK: {$img} -> {$res['width']}x{$res['height']}, filter: {$res['filter']}\n";
    } else {
        echo "FAIL: {$img}\n";
    }
}

