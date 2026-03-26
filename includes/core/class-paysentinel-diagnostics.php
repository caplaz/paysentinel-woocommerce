<?php
/**
 * Diagnostic and Recovery Tools
 *
 * Provides tools for diagnosing and recovering from payment failures
 *
 * @package PaySentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Diagnostics.
 */
class PaySentinel_Diagnostics {

	/**
	 * Database instance
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Logger instance
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;

	/**
	 * Retry instance
	 *
	 * @var PaySentinel_Retry
	 */
	private $retry;

	/**
	 * Health instance
	 *
	 * @var PaySentinel_Health
	 */
	private $health;

	/**
	 * Connectivity checker instance
	 *
	 * @var PaySentinel_Gateway_Connectivity
	 */
	private $connectivity;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database     = new PaySentinel_Database();
		$this->logger       = new PaySentinel_Logger();
		$this->retry        = new PaySentinel_Retry();
		$this->health       = new PaySentinel_Health();
		$this->connectivity = new PaySentinel_Gateway_Connectivity();
	}

	/**
	 * Run comprehensive diagnostics
	 *
	 * @return array Diagnostic results
	 */
	public function run_full_diagnostics() {
		return array(
			'timestamp'          => current_time( 'mysql' ),
			'database'           => $this->check_database_health(),
			'gateways'           => $this->check_all_gateways(),
			'recent_failures'    => $this->get_recent_failures(),
			'stuck_orders'       => $this->find_stuck_orders(),
			'retry_queue'        => $this->check_retry_queue(),
			'simulated_failures' => $this->check_simulated_failures(),
			'system_info'        => $this->get_system_info(),
		);
	}

	/**
	 * Check database health
	 *
	 * @return array Database health status
	 */
	public function check_database_health() {
		global $wpdb;

		$tables = array(
			'transactions'   => $this->database->get_transactions_table(),
			'gateway_health' => $this->database->get_gateway_health_table(),
			'alerts'         => $this->database->get_alerts_table(),
		);

		$results = array(
			'status'        => 'healthy',
			'tables'        => array(),
			'total_records' => 0,
			'issues'        => array(),
		);

		foreach ( $tables as $name => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$count  = 0;

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			} else {
				$results['status'] = 'error';
				/* translators: %s: database table name */
				$results['issues'][] = sprintf( __( 'Table %s does not exist', 'paysentinel' ), $table );
			}

			$results['tables'][ $name ] = array(
				'exists' => $exists,
				'count'  => $count,
			);

			$results['total_records'] += $count;
		}

		// Check for orphaned records.
		$orphaned = $this->find_orphaned_transactions();
		if ( $orphaned > 0 ) {
			/* translators: %d: number of orphaned records */
			$results['issues'][] = sprintf( __( '%d orphaned transaction records found', 'paysentinel' ), $orphaned );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) / 1024 / 1024 AS size_mb
				FROM information_schema.TABLES
				WHERE table_schema = %s
				AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix . 'paysentinel' ) . '%'
			)
		);

		// Handle null or non-numeric values.
		$size                     = $size !== null ? floatval( $size ) : 0.0;
		$results['total_size_mb'] = round( $size, 2 );

		if ( $size > 100 ) {
			/* translators: %s: table size in MB */
			$results['issues'][] = sprintf( __( 'Database tables are large (%s MB). Consider archiving old records.', 'paysentinel' ), round( $size, 2 ) );
		}

		return $results;
	}

	/**
	 * Check all payment gateways
	 *
	 * @return array Gateway status
	 */
	public function check_all_gateways() {
		$connectivity_results = $this->connectivity->check_all_gateways();
		$results              = array(
			'status'           => 'healthy',
			'total_gateways'   => $connectivity_results['checked_gateways'],
			'online_gateways'  => count( $connectivity_results['online_gateways'] ),
			'offline_gateways' => count( $connectivity_results['offline_gateways'] ),
			'gateways'         => array(),
			'issues'           => array(),
		);

		foreach ( $connectivity_results['results'] as $gateway_id => $status ) {
			$health_data = $this->health->get_gateway_health( $gateway_id );

			$gateway_result = array(
				'id'           => $gateway_id,
				'status'       => $status['status'],
				'message'      => $status['message'],
				'last_checked' => isset( $status['last_checked_at'] ) ? $status['last_checked_at'] : null,
				'health_24h'   => isset( $health_data['24hour'] ) ? $health_data['24hour'] : null,
			);

			$results['gateways'][ $gateway_id ] = $gateway_result;

			if ( 'offline' === $status['status'] ) {
				$results['status'] = 'warning';
				/* translators: 1: gateway ID, 2: offline message */
				$results['issues'][] = sprintf(
					/* translators: %s: placeholder */
					__( 'Gateway %1$s is offline: %2$s', 'paysentinel' ),
					$gateway_id,
					$status['error']
				);
			}

			// Check for poor health.
			if ( isset( $health_data['24hour']['success_rate'] ) && $health_data['24hour']['success_rate'] < 90 ) {
				$results['status'] = 'warning';
				/* translators: 1: gateway ID, 2: success rate percentage */
				$results['issues'][] = sprintf(
					/* translators: %s: placeholder */
					__( 'Gateway %1$s has low success rate: %2$.1f%%', 'paysentinel' ),
					$gateway_id,
					$health_data['24hour']['success_rate']
				);
			}
		}

		return $results;
	}

	/**
	 * Get recent payment failures
	 *
	 * @param int $limit Number of failures to retrieve.
	 *
	 * @return array Recent failures
	 */
	public function get_recent_failures( $limit = 20 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		$sql = $wpdb->prepare(
			"SELECT * FROM %i
			WHERE status = 'failed'
			ORDER BY created_at DESC
			LIMIT %d",
			$table_name,
			$limit
		);

		$failures = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'count'    => count( $failures ),
			'failures' => $failures,
		);
	}

	/**
	 * Find stuck/problematic orders
	 *
	 * @return array Stuck orders
	 */
	public function find_stuck_orders() {
		global $wpdb;

		$issues = array();

		// Find orders failed more than 24 hours ago with no retry attempts.
		$failed_orders = wc_get_orders(
			array(
				'status'       => 'failed',
				'date_created' => '<' . ( time() - DAY_IN_SECONDS ),
				'limit'        => 50,
			)
		);

		$old_failed_orders = array();
		foreach ( $failed_orders as $order ) {
			$date                = $order->get_date_created();
			$old_failed_orders[] = (object) array(
				'ID'        => $order->get_id(),
				'post_date' => $date ? $date->date( 'Y-m-d H:i:s' ) : '',
			);
		}

		if ( ! empty( $old_failed_orders ) ) {
			$issues['old_failed'] = array(
				'count'       => count( $old_failed_orders ),
				'description' => __( 'Orders failed over 24 hours ago', 'paysentinel' ),
				'orders'      => $old_failed_orders,
			);
		}

		// Find orders stuck in pending payment.
		$pending_orders = wc_get_orders(
			array(
				'status'       => 'pending',
				'date_created' => '<' . ( time() - ( 2 * DAY_IN_SECONDS ) ),
				'limit'        => 50,
			)
		);

		$stuck_pending = array();
		foreach ( $pending_orders as $order ) {
			$date            = $order->get_date_created();
			$stuck_pending[] = (object) array(
				'ID'        => $order->get_id(),
				'post_date' => $date ? $date->date( 'Y-m-d H:i:s' ) : '',
			);
		}

		if ( ! empty( $stuck_pending ) ) {
			$issues['stuck_pending'] = array(
				'count'       => count( $stuck_pending ),
				'description' => __( 'Orders stuck in pending payment for over 48 hours', 'paysentinel' ),
				'orders'      => $stuck_pending,
			);
		}

		// Find orders with max retries exhausted.
		$table_name  = $this->database->get_transactions_table();
		$max_retries = PaySentinel_Retry::MAX_RETRY_ATTEMPTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exhausted_retries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT order_id, retry_count, failure_reason
				FROM %i
				WHERE retry_count >= %d
				AND status = 'failed'
				ORDER BY created_at DESC
				LIMIT 50",
				$table_name,
				$max_retries
			)
		);

		if ( ! empty( $exhausted_retries ) ) {
			$issues['exhausted_retries'] = array(
				'count'       => count( $exhausted_retries ),
				'description' => __( 'Orders that have exhausted all retry attempts', 'paysentinel' ),
				'orders'      => $exhausted_retries,
			);
		}

		return $issues;
	}

	/**
	 * Check retry queue status
	 *
	 * @return array Retry queue information
	 */
	public function check_retry_queue() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Count pending retries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE status IN ('failed', 'retry')
				AND retry_count < %d",
				$table_name,
				PaySentinel_Retry::MAX_RETRY_ATTEMPTS
			)
		);

		// Get next scheduled retry.
		$next_retry = wp_next_scheduled( 'paysentinel_process_retries' );

		// Transactions older than 30 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_records = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE created_at < %s',
				$table_name,
				gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
			)
		);

		// Missing failure reasons.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$missing_reasons = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE (failure_reason IS NULL OR failure_reason = '') AND status = 'failed'",
				$table_name
			)
		);

		// Transactions with max retries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_retries = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE retry_count >= %d',
				$table_name,
				PaySentinel_Retry::MAX_RETRY_ATTEMPTS
			)
		);

		// Count recent retry attempts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_retries = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE retry_count > 0
				AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)',
				$table_name
			)
		);

		// Count successful retries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$successful_retries = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE status = 'success'
				AND retry_count > 0",
				$table_name
			)
		);

		$issues = array();
		if ( $old_records > 0 ) {
			/* translators: %d: number of old records */
			$issues[] = sprintf( __( '%d transactions older than 30 days found. Consider archiving.', 'paysentinel' ), $old_records );
		}
		if ( $missing_reasons > 0 ) {
			/* translators: %d: number of transactions with missing failure reasons */
			$issues[] = sprintf( __( '%d failed transactions with missing failure reasons found.', 'paysentinel' ), $missing_reasons );
		}
		if ( $max_retries > 0 ) {
			/* translators: %d: number of transactions that exhausted retries */
			$issues[] = sprintf( __( '%d transactions have exhausted all retry attempts.', 'paysentinel' ), $max_retries );
		}

		return array(
			'pending_retries'    => intval( $pending ),
			'next_scheduled'     => $next_retry ? gmdate( 'Y-m-d H:i:s', $next_retry ) : null,
			'recent_retry_count' => intval( $recent_retries ),
			'successful_retries' => intval( $successful_retries ),
			'success_rate'       => $recent_retries > 0 ? round( ( $successful_retries / $recent_retries ) * 100, 2 ) : 0,
			'issues'             => $issues,
		);
	}

	/**
	 * Get system information
	 *
	 * @return array System info
	 */
	public function get_system_info() {
		global $wp_version;

		return array(
			'wordpress_version'   => $wp_version,
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'Not installed',
			'php_version'         => PHP_VERSION,
			'plugin_version'      => PAYSENTINEL_VERSION,
			'timezone'            => wp_timezone_string(),
			'memory_limit'        => WP_MEMORY_LIMIT,
			'debug_mode'          => WP_DEBUG,
		);
	}

	/**
	 * Find orphaned transaction records
	 *
	 * Uses wc_get_order() to check order existence, which works with both
	 * legacy (wp_posts) and HPOS (wp_woocommerce_orders) storage models.
	 * A direct JOIN against wp_posts would incorrectly flag every record as
	 * orphaned when HPOS is active because orders are not in wp_posts.
	 *
	 * @return int Count of orphaned records
	 */
	private function find_orphaned_transactions() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		// Get all distinct order IDs from our transactions table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT order_id FROM %i WHERE order_id > 0',
				$table_name
			)
		);

		$orphaned_count = 0;
		foreach ( $order_ids as $order_id ) {
			// wc_get_order() works with both HPOS and legacy storage.
			if ( ! wc_get_order( $order_id ) ) {
				++$orphaned_count;
			}
		}

		return $orphaned_count;
	}

	/**
	 * Clean orphaned records
	 *
	 * Uses wc_get_order() to verify order existence, which works with both
	 * legacy (wp_posts) and HPOS (wp_woocommerce_orders) storage models.
	 * IMPORTANT: A direct LEFT JOIN against wp_posts would delete ALL records
	 * under HPOS because HPOS orders are not stored in wp_posts.
	 *
	 * @return array Result
	 */
	public function clean_orphaned_records() {
		global $wpdb;

		$table_name   = $this->database->get_transactions_table();
		$alerts_table = $this->database->get_alerts_table();

		// Find orphaned transaction records using wc_get_order() (HPOS-compatible).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE order_id > 0', $table_name )
		);

		$orphaned_order_ids = array();
		foreach ( $order_ids as $order_id ) {
			if ( ! wc_get_order( (int) $order_id ) ) {
				$orphaned_order_ids[] = (int) $order_id;
			}
		}

		$deleted_transactions = 0;
		if ( ! empty( $orphaned_order_ids ) ) {
			$placeholders = implode( ',', array_map( 'intval', $orphaned_order_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted_transactions = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$table_name
				)
			);
		}

		// Clean orphaned alerts (gateway_error alerts referencing deleted orders).
		// Also uses wc_get_order() for HPOS compatibility.
		$deleted_alerts = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$potential_alerts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, metadata FROM %i WHERE alert_type = %s',
				$alerts_table,
				'gateway_error'
			)
		);

		$orphaned_alert_ids = array();
		foreach ( $potential_alerts as $alert ) {
			$metadata = json_decode( $alert->metadata, true );
			if ( isset( $metadata['order_id'] ) && ! empty( $metadata['order_id'] ) ) {
				if ( ! wc_get_order( $metadata['order_id'] ) ) {
					$orphaned_alert_ids[] = $alert->id;
				}
			}
		}

		if ( ! empty( $orphaned_alert_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $orphaned_alert_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted_alerts = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $alerts_table ), $orphaned_alert_ids )
				)
			);
		}

		$total_deleted = intval( $deleted_transactions ) + intval( $deleted_alerts );

		return array(
			'success'              => true,
			'deleted'              => $total_deleted,
			'transactions_deleted' => intval( $deleted_transactions ),
			'alerts_deleted'       => intval( $deleted_alerts ),
			/* translators: 1: total deleted records, 2: deleted transactions, 3: deleted alerts */
			'message'              => sprintf(
				/* translators: %s: placeholder */
				__( 'Deleted %1$d orphaned records (%2$d transactions, %3$d alerts).', 'paysentinel' ),
				$total_deleted,
				intval( $deleted_transactions ),
				intval( $deleted_alerts )
			),
		);
	}

	/**
	 * Force retry for a specific order
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array Result
	 */
	public function force_retry_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => __( 'Order not found.', 'paysentinel' ),
			);
		}

		// Attempt the retry.
		$success = $this->retry->attempt_retry( $order_id );

		return array(
			'success' => $success,
			'message' => $success ? __( 'Retry attempt completed.', 'paysentinel' ) : __( 'Retry attempt failed.', 'paysentinel' ),
			'details' => array( 'success' => $success ),
		);
	}

	/**
	 * Reset gateway health metrics
	 *
	 * @param string $gateway_id Gateway ID (empty for all).
	 *
	 * @return array Result
	 */
	public function reset_gateway_health( $gateway_id = '' ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		if ( empty( $gateway_id ) ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );
			$message = __( 'Reset health metrics for all gateways.', 'paysentinel' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $table_name, array( 'gateway_id' => $gateway_id ) );
			/* translators: %s: gateway ID */
			$message = sprintf( __( 'Reset health metrics for gateway: %s', 'paysentinel' ), $gateway_id );
		}

		// Recalculate health.
		$this->health->calculate_all_gateway_health();

		return array(
			'success' => true,
			'deleted' => $deleted,
			'message' => $message,
		);
	}

	/**
	 * Archive old transactions
	 *
	 * @param int $days Age in days.
	 *
	 * @return array Result
	 */
	public function archive_old_transactions( $days = 90 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// For now, we'll just delete old records.
		// In a production system, you might want to export to a separate archive table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
				WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
				AND status = 'success'",
				$table_name,
				$days
			)
		);

		return array(
			'success' => true,
			'deleted' => intval( $deleted ),
			/* translators: 1: number of archived transactions, 2: number of days */
			'message' => sprintf(
				/* translators: %s: placeholder */
				__( 'Archived (deleted) %1$d successful transactions older than %2$d days.', 'paysentinel' ),
				intval( $deleted ),
				$days
			),
		);
	}

	/**
	 * Recalculate all health metrics
	 *
	 * @return array Result
	 */
	public function recalculate_health_metrics() {
		$this->health->calculate_all_gateway_health();

		return array(
			'success' => true,
			'message' => __( 'Successfully recalculated health metrics for all gateways.', 'paysentinel' ),
		);
	}

	/**
	 * Test gateway connectivity
	 *
	 * @param string $gateway_id Gateway ID.
	 *
	 * @return array Result
	 */
	public function test_gateway_connectivity( $gateway_id ) {
		$result = $this->connectivity->check_gateway( $gateway_id );

		if ( null === $result ) {
			return array(
				'success' => false,
				'message' => __( 'Gateway not found or not supported.', 'paysentinel' ),
			);
		}

		return array(
			'success' => 'online' === $result['status'],
			'status'  => $result['status'],
			'message' => $result['message'],
			'details' => $result,
		);
	}

	/**
	 * Get payment failure analysis
	 *
	 * @param int $days Number of days to analyze.
	 *
	 * @return array Analysis results
	 */
	public function analyze_payment_failures( $days = 7 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Failures by gateway.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_gateway = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gateway_id, COUNT(*) as count
				FROM %i
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY gateway_id
				ORDER BY count DESC",
				$table_name,
				$days
			)
		);

		// Failures by reason.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_reason = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT failure_code, COUNT(*) as count
				FROM %i
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY failure_code
				ORDER BY count DESC
				LIMIT 10",
				$table_name,
				$days
			)
		);

		// Hourly failure pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hourly_pattern = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR(created_at) as hour, COUNT(*) as count
				FROM %i
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY HOUR(created_at)
				ORDER BY hour",
				$table_name,
				$days
			)
		);

		// Total failures.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_failures = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
				$table_name,
				$days
			)
		);

		return array(
			'period'         => $days,
			'total_failures' => intval( $total_failures ),
			'by_gateway'     => $by_gateway,
			'by_reason'      => $by_reason,
			'hourly_pattern' => $hourly_pattern,
		);
	}

	/**
	 * Check simulated failures (test mode diagnostic data)
	 *
	 * Helps diagnose issues with clearing simulated test failures
	 *
	 * @return array Simulated failures diagnostic data
	 */
	public function check_simulated_failures() {
		global $wpdb;

		$results = array(
			'status'          => 'healthy',
			'total_simulated' => 0,
			'storage_model'   => 'unknown',
			'issues'          => array(),
			'details'         => array(),
		);

		// Determine storage model (informational only).
		$using_hpos = false;
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		$results['storage_model'] = $using_hpos ? 'HPOS' : 'Legacy (postmeta)';

		// Source of truth: query the transactions table.
		// This is storage-model agnostic and always reliable.
		$table_name = $this->database->get_transactions_table();

		// Verify the transactions table itself exists — a missing table is the most common cause.
		// of "Cleared 0" because needs_update() returns false when the DB version option is set.
		// from a prior partial activation, even if the tables were never actually created.
		$transactions_table_exists                       = $this->database->tables_exist();
		$results['details']['transactions_table_exists'] = $transactions_table_exists;

		if ( ! $transactions_table_exists ) {
			$results['status']   = 'error';
			$results['issues'][] = 'Plugin database tables are missing. Deactivate and reactivate the plugin, or visit the plugin settings page to trigger table creation.';
			// Trigger table creation immediately so the next simulate call succeeds.
			$this->database->create_tables();
			return $results;
		}

		// @codingStandardsIgnoreStart.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = 'SELECT COUNT(DISTINCT order_id) FROM ' . $table_name . ' WHERE failure_reason LIKE %s AND order_id > 0';
		$simulated_order_count = (int) $wpdb->get_var(
			$wpdb->prepare($count_sql, '[SIMULATED FAILURE]%')
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sample_sql = 'SELECT DISTINCT order_id FROM ' . $table_name . ' WHERE failure_reason LIKE %s AND order_id > 0 LIMIT 5';
		$sample_orders = $wpdb->get_col(
			$wpdb->prepare($sample_sql, '[SIMULATED FAILURE]%')
		);
		// @codingStandardsIgnoreEnd.

		$results['total_simulated']                        = $simulated_order_count;
		$results['details']['simulated_transaction_count'] = $simulated_order_count;

		if ( ! empty( $sample_orders ) ) {
			$results['details']['sample_order_ids'] = array_map( 'intval', $sample_orders );
		}

		// Secondary check: postmeta count (informational).
		// @codingStandardsIgnoreStart.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$postmeta_sql = 'SELECT COUNT(DISTINCT post_id) FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s';
		$postmeta_count = (int) $wpdb->get_var(
			$wpdb->prepare($postmeta_sql, '_paysentinel_simulated_failure')
		);
		// @codingStandardsIgnoreEnd.

		$results['details']['postmeta_count'] = $postmeta_count;

		// Secondary check: HPOS meta table count (informational).
		if ( $using_hpos ) {
			$hpos_meta_table = $wpdb->prefix . 'woocommerce_orders_meta';

			// @codingStandardsIgnoreStart.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)
			) === $hpos_meta_table;
			// @codingStandardsIgnoreEnd.

			$results['details']['hpos_meta_table_exists'] = $table_exists;
			$hpos_count                                   = 0;

			if ( $table_exists ) {
				// @codingStandardsIgnoreStart.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hpos_sql = 'SELECT COUNT(DISTINCT order_id) FROM ' . $hpos_meta_table . ' WHERE meta_key = %s';
				$hpos_count = (int) $wpdb->get_var(
					$wpdb->prepare($hpos_sql, '_paysentinel_simulated_failure')
				);
				// @codingStandardsIgnoreEnd.
			}

			$results['details']['hpos_count'] = $hpos_count;
		}

		return $results;
	}
}
