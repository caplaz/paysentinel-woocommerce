<?php

/**
 * Unit tests for PaySentinel_Logger class
 * Simplified version that works with PHPUnit
 */
class PluginStructureTest extends PHPUnit\Framework\TestCase {

	public function test_plugin_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_FILE );
	}

	public function test_logger_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-logger.php' );
	}

	public function test_database_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-database.php' );
	}

	public function test_alerts_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alerts.php' );
	}
}
