<?php
/**
 * PHPUnit Bootstrap - Define WordPress constants before tests run
 * Prevents syntax errors when PHPUnit analyzes plugin files
 */

// Define ABSPATH to prevent "exit" statements in plugin classes
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Prevent PHP notices when plugin files are analyzed
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
