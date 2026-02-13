<?php

/**
 * Unit tests for WC_Payment_Monitor_Logger class
 * Simplified version that works with PHPUnit
 */
class PluginStructureTest extends PHPUnit\Framework\TestCase {

	public function test_plugin_file_exists() {
		$this->assertFileExists( WC_PAYMENT_MONITOR_PLUGIN_FILE );
	}

	public function test_logger_class_file_exists() {
		$this->assertFileExists( WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/core/class-wc-payment-monitor-logger.php' );
	}

	public function test_database_class_file_exists() {
		$this->assertFileExists( WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/core/class-wc-payment-monitor-database.php' );
	}

	public function test_alerts_class_file_exists() {
		$this->assertFileExists( WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/alerts/class-wc-payment-monitor-alerts.php' );
	}
}
