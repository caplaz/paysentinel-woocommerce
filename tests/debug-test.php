<?php
echo "Debug test starting...\n";

// Include required classes
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
echo "Database class loaded\n";

require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
echo "Logger class loaded\n";

require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';
echo "Health class loaded\n";

echo "All classes loaded successfully\n";