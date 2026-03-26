<?php
/**
 * Plugin structure tests.
 *
 * @package PaySentinel
 */

/**
 * Class PluginStructureTest
 */
class PluginStructureTest extends PHPUnit\Framework\TestCase {

	/**
	 * Test plugin file exists.
	 */
	public function test_plugin_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_FILE );
	}

	/**
	 * Test logger class file exists.
	 */
	public function test_logger_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-logger.php' );
	}

	/**
	 * Test database class file exists.
	 */
	public function test_database_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-database.php' );
	}

	/**
	 * Test alerts class file exists.
	 */
	public function test_alerts_class_file_exists() {
		$this->assertFileExists( PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alerts.php' );
	}
}
