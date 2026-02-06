<?php

/**
 * Diagnostic and Recovery Tools
 *
 * Provides tools for diagnosing and recovering from payment failures
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Diagnostics {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Retry instance
	 */
	private $retry;

	/**
	 * Health instance
	 */
	private $health;

	/**
	 * Connectivity checker instance
	 */
	private $connectivity;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database     = new WC_Payment_Monitor_Database();
		$this->logger       = new WC_Payment_Monitor_Logger();
		$this->retry        = new WC_Payment_Monitor_Retry();
		$this->health       = new WC_Payment_Monitor_Health();
		$this->connectivity = new WC_Payment_Monitor_Gateway_Connectivity();
	}

	/**
	 * Run comprehensive diagnostics
	 *
	 * @return array Diagnostic results
	 */
	public function run_full_diagnostics() {
		return array(
			'timestamp'       => current_time( 'mysql' ),
			'database'        => $this->check_database_health(),
			'gateways'        => $this->check_all_gateways(),
			'recent_failures' => $this->get_recent_failures(),
			'stuck_orders'    => $this->find_stuck_orders(),
			'retry_queue'     => $this->check_retry_queue(),
			'system_info'     => $this->get_system_info(),
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
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
			$count  = 0;

			if ( $exists ) {
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			} else {
				$results['status']   = 'error';
				$results['issues'][] = sprintf( __( 'Table %s does not exist', 'wc-payment-monitor' ), $name );
			}

			$results['tables'][ $name ] = array(
				'exists' => $exists,
				'count'  => $count,
			);

			$results['total_records'] += $count;
		}

		// Check for orphaned records
		$orphaned = $this->find_orphaned_transactions();
		if ( $orphaned > 0 ) {
			$results['issues'][] = sprintf( __( '%d orphaned transaction records found', 'wc-payment-monitor' ), $orphaned );
		}

		// Check table sizes
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) / 1024 / 1024 AS size_mb
				FROM information_schema.TABLES
				WHERE table_schema = %s
				AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix . 'wc_payment_monitor' ) . '%'
			)
		);

		// Handle null or non-numeric values
		$size                     = $size !== null ? floatval( $size ) : 0.0;
		$results['total_size_mb'] = round( $size, 2 );

		if ( $size > 100 ) {
			$results['issues'][] = sprintf( __( 'Database tables are large (%s MB). Consider archiving old records.', 'wc-payment-monitor' ), round( $size, 2 ) );
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
				$results['status']   = 'warning';
				$results['issues'][] = sprintf(
					__( 'Gateway %1$s is offline: %2$s', 'wc-payment-monitor' ),
					$gateway_id,
					$status['message']
				);
			}

			// Check for poor health
			if ( isset( $health_data['24hour']['success_rate'] ) && $health_data['24hour']['success_rate'] < 90 ) {
				$results['status']   = 'warning';
				$results['issues'][] = sprintf(
					__( 'Gateway %1$s has low success rate: %2$.1f%%', 'wc-payment-monitor' ),
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
	 * @param int $limit Number of failures to retrieve
	 *
	 * @return array Recent failures
	 */
	public function get_recent_failures( $limit = 20 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE status = 'failed'
			ORDER BY created_at DESC
			LIMIT %d",
			$limit
		);

		$failures = $wpdb->get_results( $sql );

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

		// Find orders failed more than 24 hours ago with no retry attempts
		$old_failed_orders = $wpdb->get_results(
			"SELECT p.ID, p.post_date
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'shop_order'
			AND p.post_status = 'wc-failed'
			AND p.post_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)
			LIMIT 50"
		);

		if ( ! empty( $old_failed_orders ) ) {
			$issues['old_failed'] = array(
				'count'       => count( $old_failed_orders ),
				'description' => __( 'Orders failed over 24 hours ago', 'wc-payment-monitor' ),
				'orders'      => $old_failed_orders,
			);
		}

		// Find orders stuck in pending payment
		$stuck_pending = $wpdb->get_results(
			"SELECT p.ID, p.post_date
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'shop_order'
			AND p.post_status = 'wc-pending'
			AND p.post_date < DATE_SUB(NOW(), INTERVAL 48 HOUR)
			LIMIT 50"
		);

		if ( ! empty( $stuck_pending ) ) {
			$issues['stuck_pending'] = array(
				'count'       => count( $stuck_pending ),
				'description' => __( 'Orders stuck in pending payment for over 48 hours', 'wc-payment-monitor' ),
				'orders'      => $stuck_pending,
			);
		}

		// Find orders with max retries exhausted
		$table_name  = $this->database->get_transactions_table();
		$max_retries = WC_Payment_Monitor_Retry::MAX_RETRY_ATTEMPTS;

		$exhausted_retries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT order_id, retry_count, failure_reason
				FROM {$table_name}
				WHERE retry_count >= %d
				AND status = 'failed'
				ORDER BY created_at DESC
				LIMIT 50",
				$max_retries
			)
		);

		if ( ! empty( $exhausted_retries ) ) {
			$issues['exhausted_retries'] = array(
				'count'       => count( $exhausted_retries ),
				'description' => __( 'Orders that have exhausted all retry attempts', 'wc-payment-monitor' ),
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

		// Count pending retries
		$pending = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name}
			WHERE status IN ('failed', 'retry')
			AND retry_count < " . WC_Payment_Monitor_Retry::MAX_RETRY_ATTEMPTS
		);

		// Get next scheduled retry
		$next_retry = wp_next_scheduled( 'wc_payment_monitor_process_retries' );

		// Count recent retry attempts
		$recent_retries = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name}
			WHERE retry_count > 0
			AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);

		// Count successful retries
		$successful_retries = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name}
			WHERE status = 'success'
			AND retry_count > 0"
		);

		return array(
			'pending_retries'    => intval( $pending ),
			'next_scheduled'     => $next_retry ? date( 'Y-m-d H:i:s', $next_retry ) : null,
			'recent_retry_count' => intval( $recent_retries ),
			'successful_retries' => intval( $successful_retries ),
			'success_rate'       => $recent_retries > 0 ? round( ( $successful_retries / $recent_retries ) * 100, 2 ) : 0,
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
			'plugin_version'      => WC_PAYMENT_MONITOR_VERSION,
			'timezone'            => wp_timezone_string(),
			'memory_limit'        => WP_MEMORY_LIMIT,
			'debug_mode'          => WP_DEBUG,
		);
	}

	/**
	 * Find orphaned transaction records
	 *
	 * @return int Count of orphaned records
	 */
	private function find_orphaned_transactions() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		$count = $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$table_name} t
			LEFT JOIN {$wpdb->posts} p ON t.order_id = p.ID
			WHERE p.ID IS NULL"
		);

		return intval( $count );
	}

	/**
	 * Clean orphaned records
	 *
	 * @return array Result
	 */
	public function clean_orphaned_records() {
		global $wpdb;

		$table_name   = $this->database->get_transactions_table();
		$alerts_table = $this->database->get_alerts_table();

		// Clean orphaned transaction records
		$deleted_transactions = $wpdb->query(
			"DELETE t FROM {$table_name} t
			LEFT JOIN {$wpdb->posts} p ON t.order_id = p.ID
			WHERE p.ID IS NULL"
		);

		// Clean orphaned alerts (gateway_error alerts referencing deleted orders)
		$deleted_alerts = 0;
		if ( $wpdb->has_cap( 'json_extract' ) ) {
			// MySQL 5.7.8+ or MariaDB 10.2.3+ with native JSON support
			$deleted_alerts = $wpdb->query(
				"DELETE a FROM {$alerts_table} a
				LEFT JOIN {$wpdb->posts} p ON JSON_EXTRACT(a.metadata, '$.order_id') = p.ID
				WHERE a.alert_type = 'gateway_error'
				AND JSON_EXTRACT(a.metadata, '$.order_id') IS NOT NULL
				AND p.ID IS NULL"
			);
		} else {
			// Fallback for older MySQL versions - get alerts and check manually
			$orphaned_alert_ids = array();
			$potential_alerts   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, metadata FROM {$alerts_table} WHERE alert_type = %s",
					'gateway_error'
				)
			);

			foreach ( $potential_alerts as $alert ) {
				$metadata = json_decode( $alert->metadata, true );
				if ( isset( $metadata['order_id'] ) && ! empty( $metadata['order_id'] ) ) {
					$order_exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE ID = %d",
							$metadata['order_id']
						)
					);
					if ( ! $order_exists ) {
						$orphaned_alert_ids[] = $alert->id;
					}
				}
			}

			if ( ! empty( $orphaned_alert_ids ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $orphaned_alert_ids ), '%d' ) );
				$deleted_alerts = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$alerts_table} WHERE id IN ({$placeholders})",
						$orphaned_alert_ids
					)
				);
			}
		}

		$total_deleted = intval( $deleted_transactions ) + intval( $deleted_alerts );

		return array(
			'success'              => true,
			'deleted'              => $total_deleted,
			'transactions_deleted' => intval( $deleted_transactions ),
			'alerts_deleted'       => intval( $deleted_alerts ),
			'message'              => sprintf(
				__( 'Deleted %1$d orphaned records (%2$d transactions, %3$d alerts).', 'wc-payment-monitor' ),
				$total_deleted,
				intval( $deleted_transactions ),
				intval( $deleted_alerts )
			),
		);
	}

	/**
	 * Force retry for a specific order
	 *
	 * @param int $order_id Order ID
	 *
	 * @return array Result
	 */
	public function force_retry_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => __( 'Order not found.', 'wc-payment-monitor' ),
			);
		}

		// Attempt the retry
		$result = $this->retry->attempt_retry( $order_id );

		return array(
			'success' => $result['success'],
			'message' => $result['message'],
			'details' => $result,
		);
	}

	/**
	 * Reset gateway health metrics
	 *
	 * @param string $gateway_id Gateway ID (empty for all)
	 *
	 * @return array Result
	 */
	public function reset_gateway_health( $gateway_id = '' ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		if ( empty( $gateway_id ) ) {
			$deleted = $wpdb->query( "TRUNCATE TABLE {$table_name}" );
			$message = __( 'Reset health metrics for all gateways.', 'wc-payment-monitor' );
		} else {
			$deleted = $wpdb->delete( $table_name, array( 'gateway_id' => $gateway_id ) );
			$message = sprintf( __( 'Reset health metrics for gateway: %s', 'wc-payment-monitor' ), $gateway_id );
		}

		// Recalculate health
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
	 * @param int $days Age in days
	 *
	 * @return array Result
	 */
	public function archive_old_transactions( $days = 90 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// For now, we'll just delete old records
		// In a production system, you might want to export to a separate archive table
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name}
				WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
				AND status = 'success'",
				$days
			)
		);

		return array(
			'success' => true,
			'deleted' => intval( $deleted ),
			'message' => sprintf(
				__( 'Archived (deleted) %1$d successful transactions older than %2$d days.', 'wc-payment-monitor' ),
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
			'message' => __( 'Successfully recalculated health metrics for all gateways.', 'wc-payment-monitor' ),
		);
	}

	/**
	 * Test gateway connectivity
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return array Result
	 */
	public function test_gateway_connectivity( $gateway_id ) {
		$result = $this->connectivity->check_gateway( $gateway_id );

		if ( null === $result ) {
			return array(
				'success' => false,
				'message' => __( 'Gateway not found or not supported.', 'wc-payment-monitor' ),
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
	 * @param int $days Number of days to analyze
	 *
	 * @return array Analysis results
	 */
	public function analyze_payment_failures( $days = 7 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Failures by gateway
		$by_gateway = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gateway_id, COUNT(*) as count
				FROM {$table_name}
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY gateway_id
				ORDER BY count DESC",
				$days
			)
		);

		// Failures by reason
		$by_reason = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT failure_code, COUNT(*) as count
				FROM {$table_name}
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY failure_code
				ORDER BY count DESC
				LIMIT 10",
				$days
			)
		);

		// Hourly failure pattern
		$hourly_pattern = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR(created_at) as hour, COUNT(*) as count
				FROM {$table_name}
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY HOUR(created_at)
				ORDER BY hour",
				$days
			)
		);

		// Total failures
		$total_failures = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE status = 'failed'
				AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
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
}
