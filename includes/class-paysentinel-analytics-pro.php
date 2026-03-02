<?php

/**
 * PRO tier advanced analytics
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Analytics_Pro {


	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Health instance
	 */
	private $health;

	/**
	 * License instance
	 */
	private $license;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new PaySentinel_Database();
		$this->logger   = new PaySentinel_Logger();
		$this->health   = new PaySentinel_Health();
		$this->license  = new PaySentinel_License();
	}

	/**
	 * Check if PRO analytics features are available
	 *
	 * @return bool
	 */
	public function is_pro_analytics_available() {
		$tier = $this->license->get_license_tier();
		return in_array( $tier, array( 'pro', 'agency' ), true );
	}

	/**
	 * Get comparative analytics across multiple periods
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return array Comparative analytics data
	 */
	public function get_comparative_analytics( $gateway_id ) {
		if ( ! $this->is_pro_analytics_available() ) {
			return array(
				'error'   => 'pro_feature_required',
				'message' => __( 'Comparative analytics is a PRO feature', 'paysentinel' ),
			);
		}

		$periods   = array( '1hour', '24hour', '7day', '30day', '90day' );
		$analytics = array(
			'gateway_id' => $gateway_id,
			'periods'    => array(),
			'trends'     => array(),
		);

		foreach ( $periods as $period ) {
			$health_status = $this->health->get_health_status( $gateway_id, $period );
			if ( $health_status ) {
				$analytics['periods'][ $period ] = array(
					'success_rate'            => floatval( $health_status->success_rate ),
					'total_transactions'      => intval( $health_status->total_transactions ),
					'failed_transactions'     => intval( $health_status->failed_transactions ),
					'successful_transactions' => intval( $health_status->successful_transactions ),
				);
			}
		}

		// Calculate trends (comparing periods)
		$analytics['trends'] = $this->calculate_trends( $analytics['periods'] );

		return $analytics;
	}

	/**
	 * Calculate trends between periods
	 *
	 * @param array $periods Period data
	 *
	 * @return array Trend calculations
	 */
	private function calculate_trends( $periods ) {
		$trends = array();

		// Compare 24hour vs 7day
		if ( isset( $periods['24hour'], $periods['7day'] ) ) {
			$trends['24h_vs_7d'] = array(
				'success_rate_change' => $periods['24hour']['success_rate'] - $periods['7day']['success_rate'],
				'direction'           => $periods['24hour']['success_rate'] > $periods['7day']['success_rate'] ? 'improving' : 'declining',
			);
		}

		// Compare 7day vs 30day
		if ( isset( $periods['7day'], $periods['30day'] ) ) {
			$trends['7d_vs_30d'] = array(
				'success_rate_change' => $periods['7day']['success_rate'] - $periods['30day']['success_rate'],
				'direction'           => $periods['7day']['success_rate'] > $periods['30day']['success_rate'] ? 'improving' : 'declining',
			);
		}

		// Compare 30day vs 90day
		if ( isset( $periods['30day'], $periods['90day'] ) ) {
			$trends['30d_vs_90d'] = array(
				'success_rate_change' => $periods['30day']['success_rate'] - $periods['90day']['success_rate'],
				'direction'           => $periods['30day']['success_rate'] > $periods['90day']['success_rate'] ? 'improving' : 'declining',
			);
		}

		return $trends;
	}

	/**
	 * Get failure pattern analysis (PRO feature)
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $days       Number of days to analyze (default: 30)
	 *
	 * @return array Failure pattern analysis
	 */
	public function get_failure_pattern_analysis( $gateway_id, $days = 30 ) {
		if ( ! $this->is_pro_analytics_available() ) {
			return array(
				'error'   => 'pro_feature_required',
				'message' => __( 'Failure pattern analysis is a PRO feature', 'paysentinel' ),
			);
		}

		global $wpdb;
		$table_name  = $this->database->get_transactions_table();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days", current_time( 'timestamp' ) ) );

		// Get failure reasons grouped by frequency
		$failure_reasons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT failure_reason, failure_code, COUNT(*) as count 
				FROM `{$table_name}` 
				WHERE gateway_id = %s 
				AND status = 'failed' 
				AND created_at >= %s 
				AND failure_reason IS NOT NULL
				GROUP BY failure_reason, failure_code 
				ORDER BY count DESC 
				LIMIT 10",
				$gateway_id,
				$cutoff_date
			),
			ARRAY_A
		);

		// Get hourly failure distribution
		$hourly_distribution = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR(created_at) as hour, COUNT(*) as failures 
				FROM `{$table_name}` 
				WHERE gateway_id = %s 
				AND status = 'failed' 
				AND created_at >= %s 
				GROUP BY HOUR(created_at) 
				ORDER BY hour ASC",
				$gateway_id,
				$cutoff_date
			),
			ARRAY_A
		);

		// Get daily failure trends
		$daily_trends = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, 
				COUNT(*) as total_transactions,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
				SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_transactions
				FROM `{$table_name}` 
				WHERE gateway_id = %s 
				AND created_at >= %s 
				GROUP BY DATE(created_at) 
				ORDER BY date ASC",
				$gateway_id,
				$cutoff_date
			),
			ARRAY_A
		);

		return array(
			'gateway_id'          => $gateway_id,
			'analysis_period'     => $days,
			'top_failure_reasons' => $failure_reasons,
			'hourly_distribution' => $hourly_distribution,
			'daily_trends'        => $daily_trends,
		);
	}

	/**
	 * Get advanced metrics summary (PRO feature)
	 *
	 * @return array Advanced metrics for all monitored gateways
	 */
	public function get_advanced_metrics_summary() {
		if ( ! $this->is_pro_analytics_available() ) {
			return array(
				'error'   => 'pro_feature_required',
				'message' => __( 'Advanced metrics summary is a PRO feature', 'paysentinel' ),
			);
		}

		$tier  = $this->license->get_license_tier();
		$limit = PaySentinel_License::GATEWAY_LIMITS[ $tier ];

		$settings            = get_option( 'paysentinel_settings', array() );
		$enabled_gateways    = isset( $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] ) ? $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] : array();
		$gateways_to_monitor = array_slice( $enabled_gateways, 0, $limit );

		$summary = array(
			'total_gateways'  => count( $gateways_to_monitor ),
			'gateway_metrics' => array(),
		);

		foreach ( $gateways_to_monitor as $gateway_id ) {
			$comparative = $this->get_comparative_analytics( $gateway_id );
			if ( ! isset( $comparative['error'] ) ) {
				$summary[ PaySentinel_Settings_Constants::GATEWAY_METRICS ][ $gateway_id ] = $comparative;
			}
		}

		return $summary;
	}

	/**
	 * Get extended historical data (PRO feature - up to 90 days)
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $days       Number of days (max 90 for PRO)
	 *
	 * @return array Historical data
	 */
	public function get_extended_history( $gateway_id, $days = 90 ) {
		if ( ! $this->is_pro_analytics_available() ) {
			return array(
				'error'   => 'pro_feature_required',
				'message' => __( 'Extended history is a PRO feature', 'paysentinel' ),
			);
		}

		// Ensure days doesn't exceed PRO limit
		$tier     = $this->license->get_license_tier();
		$max_days = PaySentinel_License::RETENTION_LIMITS[ $tier ];
		$days     = min( $days, $max_days );

		return $this->health->get_health_history( $gateway_id, '24hour', $days );
	}

	/**
	 * Get gateway comparison report (PRO feature)
	 *
	 * @return array Comparison data across all monitored gateways
	 */
	public function get_gateway_comparison() {
		if ( ! $this->is_pro_analytics_available() ) {
			return array(
				'error'   => 'pro_feature_required',
				'message' => __( 'Gateway comparison is a PRO feature', 'paysentinel' ),
			);
		}

		$tier  = $this->license->get_license_tier();
		$limit = PaySentinel_License::GATEWAY_LIMITS[ $tier ];

		$settings            = get_option( 'paysentinel_settings', array() );
		$enabled_gateways    = isset( $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] ) ? $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] : array();
		$gateways_to_compare = array_slice( $enabled_gateways, 0, $limit );

		$comparison = array(
			'gateways' => array(),
			'rankings' => array(),
		);

		foreach ( $gateways_to_compare as $gateway_id ) {
			$health_24h = $this->health->get_health_status( $gateway_id, '24hour' );
			$health_7d  = $this->health->get_health_status( $gateway_id, '7day' );
			$health_30d = $this->health->get_health_status( $gateway_id, '30day' );

			if ( $health_24h ) {
				$comparison['gateways'][ $gateway_id ] = array(
					'24hour' => array(
						'success_rate'       => floatval( $health_24h->success_rate ),
						'total_transactions' => intval( $health_24h->total_transactions ),
					),
					'7day'   => $health_7d ? array(
						'success_rate'       => floatval( $health_7d->success_rate ),
						'total_transactions' => intval( $health_7d->total_transactions ),
					) : null,
					'30day'  => $health_30d ? array(
						'success_rate'       => floatval( $health_30d->success_rate ),
						'total_transactions' => intval( $health_30d->total_transactions ),
					) : null,
				);
			}
		}

		// Rank gateways by 24h success rate
		$rankings = array();
		foreach ( $comparison['gateways'] as $gateway_id => $data ) {
			$rankings[ $gateway_id ] = $data['24hour']['success_rate'];
		}
		arsort( $rankings );
		$comparison['rankings']['by_success_rate'] = array_keys( $rankings );

		return $comparison;
	}
}
