<?php

$logoPath = __DIR__ . '/../public/static/img/fbr_logo.png';
if (!file_exists($logoPath)) {
    echo "FAIL: fbr_logo.png does not exist at $logoPath\n";
    exit(1);
}

$login = file_get_contents(__DIR__ . '/../templates/login.html');
if (strpos($login, 'fbr_logo.png') === false) {
    echo "FAIL: login.html missing fbr_logo.png\n";
    exit(1);
}
if (strpos($login, 'Sales Tax Act, 1990') === false) {
    echo "FAIL: login.html missing Sales Tax Act, 1990\n";
    exit(1);
}
if (strpos($login, 'FBR POS Integrated') === false) {
    echo "FAIL: login.html missing FBR POS Integrated\n";
    exit(1);
}
if (strpos($login, 'Rule 150ZEB') === false) {
    echo "FAIL: login.html missing Rule 150ZEB\n";
    exit(1);
}

$landing = file_get_contents(__DIR__ . '/../templates/landing.html');
if (strpos($landing, 'fbr_logo.png') === false) {
    echo "FAIL: landing.html missing fbr_logo.png\n";
    exit(1);
}
if (strpos($landing, 'Sales Tax Act, 1990') === false) {
    echo "FAIL: landing.html missing Sales Tax Act, 1990\n";
    exit(1);
}
if (strpos($landing, 'Rule 150ZEB') === false) {
    echo "FAIL: landing.html missing Rule 150ZEB\n";
    exit(1);
}
if (strpos($landing, 'Tax Asaan') === false) {
    echo "FAIL: landing.html missing Tax Asaan\n";
    exit(1);
}

$pubLanding = file_get_contents(__DIR__ . '/../public/landing.html');
if (strpos($pubLanding, 'fbr_logo.png') === false) {
    echo "FAIL: public/landing.html missing fbr_logo.png\n";
    exit(1);
}

$index = file_get_contents(__DIR__ . '/../public/index.php');
if (strpos($index, "'/landing' => 'landing.html'") === false) {
    echo "FAIL: public/index.php missing /landing route\n";
    exit(1);
}

echo "SUCCESS: All FBR POS landing page and logo checks passed 100%!\n";
