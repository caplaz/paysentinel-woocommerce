<?php

/**
 * Database management class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Database {


	/**
	 * Database version
	 */
	public const DB_VERSION = '1.0.2';

	/**
	 * Table names
	 */
	private $transactions_table;
	private $gateway_health_table;
	private $alerts_table;
	private $gateway_connectivity_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;

		$this->transactions_table         = $wpdb->prefix . 'payment_monitor_transactions';
		$this->gateway_health_table       = $wpdb->prefix . 'payment_monitor_gateway_health';
		$this->alerts_table               = $wpdb->prefix . 'payment_monitor_alerts';
		$this->gateway_connectivity_table = $wpdb->prefix . 'payment_monitor_gateway_connectivity';
	}

	/**
	 * Create all database tables
	 */
	public function create_tables() {
		$this->create_transactions_table();
		$this->create_gateway_health_table();
		$this->create_alerts_table();
		$this->create_gateway_connectivity_table();

		// Update database version
		update_option( 'payment_monitor_db_version', self::DB_VERSION );
	}

	/**
	 * Create transactions table
	 */
	private function create_transactions_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->transactions_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            transaction_id VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status ENUM('success', 'failed', 'pending', 'retry') NOT NULL,
            failure_reason TEXT DEFAULT NULL,
            failure_code VARCHAR(50) DEFAULT NULL,
            retry_count TINYINT(3) UNSIGNED DEFAULT 0,
            customer_email VARCHAR(100) DEFAULT NULL,
            customer_ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_gateway_id (gateway_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_gateway_status_created (gateway_id, status, created_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create gateway health table
	 *
	 * This table stores health metrics for different time periods. The period column includes
	 * extended periods (30day, 90day) that are only calculated and populated for PRO and Agency tiers.
	 */
	private function create_gateway_health_table() {
		global $wpdb;

		// Drop legacy unique index if it exists (dbDelta won't do this)
		$index_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM {$this->gateway_health_table} WHERE KEY_NAME = %s",
				'idx_gateway_period'
			)
		);

		if ( ! empty( $index_exists ) ) {
			$wpdb->query( "ALTER TABLE {$this->gateway_health_table} DROP INDEX idx_gateway_period" );
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->gateway_health_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway_id VARCHAR(50) NOT NULL,
            period ENUM('1hour', '24hour', '7day', '30day', '90day') NOT NULL,
            total_transactions INT(11) UNSIGNED DEFAULT 0,
            successful_transactions INT(11) UNSIGNED DEFAULT 0,
            failed_transactions INT(11) UNSIGNED DEFAULT 0,
            success_rate DECIMAL(5,2) DEFAULT 0.00,
            avg_response_time INT(11) UNSIGNED DEFAULT NULL,
            last_failure_at DATETIME DEFAULT NULL,
            calculated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_gateway_period_v2 (gateway_id, period),
            KEY idx_gateway_id (gateway_id),
            KEY idx_calculated_at (calculated_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create alerts table
	 */
	private function create_alerts_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->alerts_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            alert_type ENUM('gateway_down', 'low_success_rate', 'high_failure_count', 'gateway_error') NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            severity ENUM('info', 'warning', 'high', 'critical') NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT DEFAULT NULL,
            is_resolved TINYINT(1) DEFAULT 0,
            resolved_at DATETIME DEFAULT NULL,
            notified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_gateway_id (gateway_id),
            KEY idx_alert_type (alert_type),
            KEY idx_severity (severity),
            KEY idx_is_resolved (is_resolved),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create gateway connectivity table
	 */
	private function create_gateway_connectivity_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->gateway_connectivity_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway_id VARCHAR(50) NOT NULL,
            status ENUM('online', 'offline', 'unconfigured') NOT NULL,
            message TEXT DEFAULT NULL,
            http_code INT(3) UNSIGNED DEFAULT NULL,
            response_time_ms DECIMAL(10,2) UNSIGNED DEFAULT NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_gateway_id (gateway_id),
            KEY idx_status (status),
            KEY idx_checked_at (checked_at),
            KEY idx_gateway_checked (gateway_id, checked_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop all database tables
	 */
	public function drop_tables() {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$this->transactions_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->gateway_health_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->alerts_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->gateway_connectivity_table}" );

		// Remove database version
		delete_option( 'payment_monitor_db_version' );
	}

	/**
	 * Get transactions table name
	 */
	public function get_transactions_table() {
		return $this->transactions_table;
	}

	/**
	 * Get gateway health table name
	 */
	public function get_gateway_health_table() {
		return $this->gateway_health_table;
	}

	/**
	 * Get alerts table name
	 */
	public function get_alerts_table() {
		return $this->alerts_table;
	}

	/**
	 * Get gateway connectivity table name
	 */
	public function get_gateway_connectivity_table() {
		return $this->gateway_connectivity_table;
	}

	/**
	 * Check if tables exist
	 */
	public function tables_exist() {
		global $wpdb;

		$tables = array(
			$this->transactions_table,
			$this->gateway_health_table,
			$this->alerts_table,
		);

		foreach ( $tables as $table ) {
			$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $result !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get database version
	 */
	public function get_db_version() {
		return get_option( 'payment_monitor_db_version', '0.0.0' );
	}

	/**
	 * Get latest transaction for an order
	 *
	 * @param int $order_id Order ID
	 * @return object|null Transaction data or null if not found
	 */
	public function get_latest_transaction_for_order( $order_id ) {
		global $wpdb;

		$table = $this->get_transactions_table();
		$query = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
			$order_id
		);

		return $wpdb->get_row( $query );
	}

	/**
	 * Check if database needs update
	 */
	public function needs_update() {
		return version_compare( $this->get_db_version(), self::DB_VERSION, '<' );
	}

	/**
	 * Run database migrations
	 *
	 * @return bool True on success, false on failure
	 */
	public function run_migrations() {
		$current_version = $this->get_db_version();

		// Migrations array: version => callable
		$migrations = array(
			'0.0.0' => array( $this, 'migrate_to_v1_0_0' ),
			'1.0.0' => array( $this, 'migrate_to_v1_0_1' ),
		);

		foreach ( $migrations as $version => $migration ) {
			if ( version_compare( $current_version, $version, '<=' ) ) {
				if ( ! call_user_func( $migration ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Migrate to version 1.0.0
	 *
	 * @return bool True on success
	 */
	private function migrate_to_v1_0_0() {
		// Create all tables from scratch or update existing ones
		$this->create_tables();
		return true;
	}

	/**
	 * Migrate to version 1.0.1
	 *
	 * @return bool True on success
	 */
	private function migrate_to_v1_0_1() {
		global $wpdb;

		// Drop the unique index to allow history
		$index_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM {$this->gateway_health_table} WHERE KEY_NAME = %s",
				'idx_gateway_period'
			)
		);

		if ( ! empty( $index_exists ) ) {
			$wpdb->query( "ALTER TABLE {$this->gateway_health_table} DROP INDEX idx_gateway_period" );
		}

		// Recreate tables (this will add the new non-unique index via dbDelta)
		$this->create_tables();

		return true;
	}

	/**
	 * Verify table structure and integrity
	 *
	 * @return array Verification result with 'valid' bool and 'errors' array
	 */
	public function verify_table_structure() {
		global $wpdb;

		$errors      = array();
		$tables_info = array(
			$this->transactions_table   => array(
				'required_columns' => array( 'id', 'order_id', 'gateway_id', 'status', 'created_at' ),
				'required_indexes' => array( 'id', 'gateway_id', 'status' ),
			),
			$this->gateway_health_table => array(
				'required_columns' => array( 'id', 'gateway_id', 'period', 'success_rate', 'calculated_at' ),
				'required_indexes' => array( 'id', 'gateway_id' ),
			),
			$this->alerts_table         => array(
				'required_columns' => array( 'id', 'alert_type', 'gateway_id', 'severity', 'created_at' ),
				'required_indexes' => array( 'id', 'gateway_id' ),
			),
		);

		foreach ( $tables_info as $table => $info ) {
			// Check if table exists
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $table_exists ) {
				$errors[] = "Table $table does not exist";
				continue;
			}

			// Check required columns
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			foreach ( $info['required_columns'] as $column ) {
				if ( ! in_array( $column, $columns, true ) ) {
					$errors[] = "Missing column '$column' in table '$table'";
				}
			}

			// Check required indexes
			$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}" );
			foreach ( $info['required_indexes'] as $index ) {
				if ( ! in_array( $index, $indexes, true ) ) {
					$errors[] = "Missing index for column '$index' in table '$table'";
				}
			}
		}

		return array(
			'valid'          => empty( $errors ),
			'errors'         => $errors,
			'tables_checked' => count( $tables_info ),
		);
	}

	/**
	 * Get database statistics
	 *
	 * @return array Statistics for all tables
	 */
	public function get_database_stats() {
		global $wpdb;

		$stats = array();

		// Transactions statistics
		$trans_count           = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->transactions_table}" );
		$stats['transactions'] = array(
			'total_records' => intval( $trans_count ),
			'table_size'    => $wpdb->get_var( "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '{$this->transactions_table}'" ),
		);

		// Gateway health statistics
		$health_count            = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->gateway_health_table}" );
		$stats['gateway_health'] = array(
			'total_records' => intval( $health_count ),
			'table_size'    => $wpdb->get_var( "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '{$this->gateway_health_table}'" ),
		);

		// Alerts statistics
		$alerts_count    = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->alerts_table}" );
		$stats['alerts'] = array(
			'total_records' => intval( $alerts_count ),
			'table_size'    => $wpdb->get_var( "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '{$this->alerts_table}'" ),
		);

		return $stats;
	}

	/**
	 * Clean up old transaction records
	 *
	 * @param int $days Number of days to keep (default: 90)
	 *
	 * @return int Number of records deleted
	 */
	public function cleanup_old_transactions( $days = 90 ) {
		global $wpdb;

		if ( $days < 1 || $days > 365 ) {
			return 0;
		}

		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->transactions_table} WHERE created_at < %s",
				$cutoff_date
			)
		);

		return intval( $deleted );
	}

	/**
	 * Clean up old alert records
	 *
	 * @param int $days Number of days to keep (default: 30)
	 *
	 * @return int Number of records deleted
	 */
	public function cleanup_old_alerts( $days = 30 ) {
		global $wpdb;

		if ( $days < 1 || $days > 365 ) {
			return 0;
		}

		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->alerts_table} WHERE is_resolved = 1 AND resolved_at < %s",
				$cutoff_date
			)
		);

		return intval( $deleted );
	}

	/**
	 * Optimize all database tables
	 *
	 * @return array Optimization results
	 */
	public function optimize_tables() {
		global $wpdb;

		$tables = array(
			$this->transactions_table,
			$this->gateway_health_table,
			$this->alerts_table,
		);

		$results = array();

		foreach ( $tables as $table ) {
			$result            = $wpdb->query( "OPTIMIZE TABLE {$table}" );
			$results[ $table ] = $result !== false;
		}

		return $results;
	}

	/**
	 * Get last database maintenance time
	 *
	 * @return string Last maintenance timestamp or 'never'
	 */
	public function get_last_maintenance() {
		$time = get_option( 'payment_monitor_last_maintenance', false );
		return $time ? date( 'Y-m-d H:i:s', intval( $time ) ) : 'never';
	}

	/**
	 * Record database maintenance
	 *
	 * @return bool True on success
	 */
	public function record_maintenance() {
		return update_option( 'payment_monitor_last_maintenance', time() );
	}
}
