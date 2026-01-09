<?php
echo "Debug test starting...\n";

// Check if files exist
$files = [
    __DIR__ . '/../includes/class-wc-payment-monitor-database.php',
    __DIR__ . '/../includes/class-wc-payment-monitor-logger.php',
    __DIR__ . '/../includes/class-wc-payment-monitor-health.php'
];

foreach ($files as $file) {
    echo "File $file: " . (file_exists($file) ? 'EXISTS' : 'NOT FOUND') . "\n";
}

// Try to include the files
try {
    require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
    echo "Database class included successfully\n";
} catch (Exception $e) {
    echo "Error including database class: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
    echo "Logger class included successfully\n";
} catch (Exception $e) {
    echo "Error including logger class: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';
    echo "Health class included successfully\n";
} catch (Exception $e) {
    echo "Error including health class: " . $e->getMessage() . "\n";
}

echo "Debug test completed\n";