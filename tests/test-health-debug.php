<?php
echo "Starting debug test...\n";

echo "Loading database class...\n";
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
echo "Database class loaded\n";

echo "Loading logger class...\n";
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
echo "Logger class loaded\n";

echo "Loading health class...\n";
require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';
echo "Health class loaded\n";

echo "All classes loaded successfully!\n";