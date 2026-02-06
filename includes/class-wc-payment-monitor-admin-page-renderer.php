<?php
/**
 * Admin Page Renderer
 *
 * Handles rendering of admin pages for the Payment Monitor plugin.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WC_Payment_Monitor_Admin_Page_Renderer
 *
 * Renders admin pages and handles page-specific logic.
 */
class WC_Payment_Monitor_Admin_Page_Renderer
{
    /**
     * Database instance
     *
     * @var WC_Payment_Monitor_Database
     */
    private $database;

    /**
     * License instance
     *
     * @var WC_Payment_Monitor_License
     */
    private $license;

    /**
     * Settings handler instance
     *
     * @var WC_Payment_Monitor_Admin_Settings_Handler
     */
    private $settings_handler;

    /**
     * Constructor
     *
     * @param WC_Payment_Monitor_Database              $database Database instance.
     * @param WC_Payment_Monitor_License                $license License instance.
     * @param WC_Payment_Monitor_Admin_Settings_Handler $settings_handler Settings handler instance.
     */
    public function __construct($database, $license, $settings_handler)
    {
        $this->database = $database;
        $this->license = $license;
        $this->settings_handler = $settings_handler;
    }

    /**
     * Render dashboard page
     *
     * Renders the main dashboard page for the Payment Monitor plugin with license info,
     * SMS quota display, and the React-based dashboard component.
     */
    public function render_dashboard_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }

        // Get license info for header display
        $tier = $this->license->get_license_tier();
        $quota = $this->license->get_sms_quota();
        $tier_labels = [
            'free' => __('Free', 'wc-payment-monitor'),
            'starter' => __('Starter', 'wc-payment-monitor'),
            'pro' => __('Pro', 'wc-payment-monitor'),
            'agency' => __('Agency', 'wc-payment-monitor'),
        ];
        $tier_colors = [
            'free' => '#6c757d',
            'starter' => '#0073aa',
            'pro' => '#46b450',
            'agency' => '#9b51e0',
        ];
        $tier_label = isset($tier_labels[$tier]) ? $tier_labels[$tier] : ucfirst($tier);
        $tier_color = isset($tier_colors[$tier]) ? $tier_colors[$tier] : '#0073aa';

        ?>
		<div class="wrap">
			<?php if (isset($_GET['message'])): ?>
				<div class="notice notice-<?php echo esc_attr(isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'info'); ?> is-dismissible">
					<p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
				</div>
			<?php endif; ?>

			<div class="dashboard-banner-area" style="margin-bottom: 20px;">
				<div style="display: flex; gap: 10px; margin-bottom: 15px;">
					<!-- SMS Quota Display -->
					<?php if ($quota && isset($quota['sms_remaining'], $quota['sms_limit'])): ?>
						<?php
                        $usage_percent = ($quota['sms_limit'] > 0) ? (($quota['sms_limit'] - $quota['sms_remaining']) / $quota['sms_limit']) * 100 : 0;
					    $quota_color = '#46b450'; // green
					    if ($usage_percent >= 80) {
					        $quota_color = '#dc3232'; // red
					    } elseif ($usage_percent >= 60) {
					        $quota_color = '#f0b849'; // yellow
					    }
					    ?>
						<div style="background: #fff; border: 1px solid #ccd0d4; padding: 6px 12px; border-radius: 4px; font-size: 13px; display: inline-flex; align-items: center;">
							<span class="dashicons dashicons-smartphone" style="font-size: 16px; width: 16px; height: 16px; margin-right: 5px; color: #646970;"></span>
							<span style="color: #646970; margin-right: 5px;"><?php esc_html_e('SMS Quota:', 'wc-payment-monitor'); ?></span>
							<span style="font-weight: 600; color: <?php echo esc_attr($quota_color); ?>;">
								<?php echo esc_html($quota['sms_remaining']); ?>/<?php echo esc_html($quota['sms_limit']); ?>
							</span>
						</div>
					<?php endif; ?>

					<!-- Quota Exceeded Warning -->
					<?php
                    $quota_exceeded = get_option('wc_payment_monitor_quota_exceeded', false);
			        if ($quota_exceeded):
			            ?>
						<div style="background: #dc3232; color: white; padding: 6px 12px; border-radius: 4px; font-size: 13px; display: inline-flex; align-items: center;">
							<span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px; margin-right: 5px;"></span>
							<?php esc_html_e('SMS Quota Exceeded', 'wc-payment-monitor'); ?>
							<a href="https://paysentinel.caplaz.com/upgrade" target="_blank" style="color: white; text-decoration: underline; margin-left: 10px;">
								<?php esc_html_e('Upgrade', 'wc-payment-monitor'); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>

				<h1 style="margin: 0;"><?php esc_html_e('Payment Monitor Dashboard', 'wc-payment-monitor'); ?></h1>
			</div>

			<div id="wc-payment-monitor-root"></div>
		</div>
		<?php
    }

    /**
     * Render gateway health page
     */
    public function render_health_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }

        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Gateway Health', 'wc-payment-monitor'); ?></h1>
			<p><?php esc_html_e('Real-time health metrics for all payment gateways.', 'wc-payment-monitor'); ?></p>
			<div id="wc-payment-monitor-health-container"></div>
		</div>
		<?php
    }

    /**
     * Render transactions page
     */
    public function render_transactions_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }

        global $wpdb;
        $table_name = $this->database->get_transactions_table();
        $transactions = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 50");

        add_thickbox();
        ?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Transaction Log', 'wc-payment-monitor'); ?></h1>

			<?php if (isset($_GET['message'])): ?>
				<div
					class="notice notice-<?php echo esc_attr(isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'info'); ?> is-dismissible">
					<p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e('View all monitored payment transactions.', 'wc-payment-monitor'); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Date', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Order', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Amount', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Reason', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Actions', 'wc-payment-monitor'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($transactions)): ?>
						<tr>
							<td colspan="7"><?php esc_html_e('No transactions found.', 'wc-payment-monitor'); ?></td>
						</tr>
					<?php else: ?>
						<?php foreach ($transactions as $t): ?>
							<tr>
								<td><?php echo esc_html($t->created_at); ?></td>
								<td>
									<span class="wc-monitor-status status-<?php echo esc_attr($t->status); ?>" style="
										padding: 3px 8px;
										border-radius: 4px;
										font-weight: 500;
										background: <?php echo $t->status === 'success' ? '#d4edda' : ($t->status === 'failed' ? '#f8d7da' : '#fff3cd'); ?>;
										color: <?php echo $t->status === 'success' ? '#155724' : ($t->status === 'failed' ? '#721c24' : '#856404'); ?>;
									">
										<?php echo esc_html(ucfirst($t->status)); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url(admin_url('post.php?post=' . $t->order_id . '&action=edit')); ?>">
										#<?php echo esc_html($t->order_id); ?>
									</a>
								</td>
								<td><?php echo esc_html($t->amount . ' ' . $t->currency); ?></td>
								<td><?php echo esc_html($t->gateway_id); ?></td>
								<td><?php echo esc_html($t->failure_reason ? $t->failure_reason : '-'); ?></td>
								<td>
									<a href="#TB_inline?width=600&height=440&inlineId=transaction-details-<?php echo $t->id; ?>"
										class="thickbox button button-small tip"
										title="<?php echo esc_attr(sprintf(__('Transaction Details #%d', 'wc-payment-monitor'), $t->id)); ?>"
										style="display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;">
										<span class="dashicons dashicons-visibility"
											style="font-size: 18px; width: 18px; height: 18px;"></span>
									</a>

									<div id="transaction-details-<?php echo $t->id; ?>" style="display:none;">
										<div class="wc-monitor-details-modal" style="padding: 10px 20px;">
											<style>
												.wc-monitor-details-modal .form-table th,
												.wc-monitor-details-modal .form-table td {
													padding: 10px 0 !important;
												}
											</style>
											<table class="form-table" style="margin-top: 0; margin-bottom: 0;">
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Transaction ID', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->transaction_id ? $t->transaction_id : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Order ID', 'wc-payment-monitor'); ?></th>
													<td>#<?php echo esc_html($t->order_id); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->gateway_id); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html(ucfirst($t->status)); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Failure Reason', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->failure_reason ? $t->failure_reason : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Failure Code', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->failure_code ? $t->failure_code : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Retry Count', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->retry_count); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Customer Email', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->customer_email); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Customer IP', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->customer_ip); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Created At', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->created_at); ?></td>
												</tr>
											</table>
										</div>
									</div>

									<?php if (in_array($t->status, ['failed', 'retry'])): ?>
										<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_payment_monitor_retry&order_id=' . $t->order_id), 'wc_payment_monitor_retry_' . $t->order_id)); ?>"
											class="button button-small tip"
											title="<?php esc_attr_e('Retry Payment Now', 'wc-payment-monitor'); ?>"
											style="margin-left: 5px; display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;">
											<span class="dashicons dashicons-update"
												style="font-size: 18px; width: 18px; height: 18px;"></span>
										</a>
										<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_payment_monitor_recovery&order_id=' . $t->order_id), 'wc_payment_monitor_recovery_' . $t->order_id)); ?>"
											class="button button-small tip"
											style="margin-left: 5px; display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;"
											title="<?php esc_attr_e('Send Recovery Email', 'wc-payment-monitor'); ?>">
											<span class="dashicons dashicons-email-alt"
												style="font-size: 18px; width: 18px; height: 18px;"></span>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
    }

    /**
     * Render alerts page
     */
    public function render_alerts_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }

        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Alerts', 'wc-payment-monitor'); ?></h1>
			<p><?php esc_html_e('View all payment monitoring alerts.', 'wc-payment-monitor'); ?></p>
			<div id="wc-payment-monitor-alerts-container"></div>
		</div>
		<?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }

        $tabs = $this->get_settings_tabs();
        $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Payment Monitor Settings', 'wc-payment-monitor'); ?></h1>
			
			<?php if (isset($_GET['message'])): ?>
				<div class="notice notice-<?php echo esc_attr(isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'info'); ?> is-dismissible">
					<p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
				</div>
			<?php endif; ?>

			<?php settings_errors('wc_payment_monitor_options'); ?>
			<?php settings_errors('wc_payment_monitor_license'); ?>
			
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom: 20px;">
				<?php foreach ($tabs as $id => $label): ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=wc-payment-monitor-settings&tab=' . $id)); ?>" 
					   class="nav-tab <?php echo $active_tab === $id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html($label); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php if ('license' === $active_tab): ?>
					<?php $this->settings_handler->render_license_section(); ?>
					
					<div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
						<h3 style="margin-top: 0;"><?php esc_html_e('Subscription Benefits', 'wc-payment-monitor'); ?></h3>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><?php esc_html_e('Real-time monitoring and instant SMS alerts.', 'wc-payment-monitor'); ?></li>
							<li><?php esc_html_e('Slack integration for team notifications.', 'wc-payment-monitor'); ?></li>
							<li><?php esc_html_e('Advanced gateway-specific performance thresholds.', 'wc-payment-monitor'); ?></li>
							<li><?php esc_html_e('Priority support and automatic updates.', 'wc-payment-monitor'); ?></li>
						</ul>
					</div>
				<?php else: ?>
					<form method="post" action="options.php">
						<?php
                        settings_fields('wc_payment_monitor_settings');
        ?>
						<input type="hidden" name="wc_payment_monitor_options[current_tab]" value="<?php echo esc_attr($active_tab); ?>" />
						<?php
        // Only render the active section
        $section_id = 'wc_payment_monitor_' . $active_tab;
        
        // We still need to call do_settings_sections but we only want our specific section
        // Standard WordPress doesn't easily allow rendering just one section of a page 
        // without some hackery or custom rendering.
        // Actually, do_settings_sections($page) renders ALL sections for that page.
        // Let's use a more targeted approach.
        
        global $wp_settings_sections, $wp_settings_fields;
        
        if (isset($wp_settings_sections['wc_payment_monitor_settings'][$section_id])) {
            $section = $wp_settings_sections['wc_payment_monitor_settings'][$section_id];
            
            if ($section['title']) {
                echo "<h2>{$section['title']}</h2>\n";
            }

            if ($section['callback']) {
                call_user_func($section['callback'], $section);
            }

            if (!isset($wp_settings_fields) || !isset($wp_settings_fields['wc_payment_monitor_settings']) || !isset($wp_settings_fields['wc_payment_monitor_settings'][$section_id])) {
                // No fields
            } else {
                echo '<table class="form-table" role="presentation">';
                do_settings_fields('wc_payment_monitor_settings', $section_id);
                echo '</table>';
            }
        }
        
        submit_button();
        ?>
					</form>
				<?php endif; ?>
			</div>
			
			<?php if ('general' === $active_tab): ?>
			<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border: 1px solid #e1e1e1; border-radius: 4px;">
				<h3 style="margin-top: 0; color: #23282d;"><?php esc_html_e('How Payment Monitor Works', 'wc-payment-monitor'); ?></h3>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Monitoring:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Tracks payment gateway success rates and triggers alerts when performance drops below thresholds.', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Alerts:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Sends notifications via email, SMS, or Slack when payment issues are detected.', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Auto-Retry:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Automatically retries failed payments using stored payment methods (excludes fraud/expired cards).', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 0;">
					<strong><?php esc_html_e('License:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Required for premium features like SMS alerts, Slack integration, and advanced analytics.', 'wc-payment-monitor'); ?>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Render diagnostics page
     *
     * Renders the diagnostics tools page including system diagnostics, failure simulator,
     * and maintenance tools for troubleshooting payment issues.
     */
    public function render_diagnostics_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Payment Monitor - Diagnostic Tools', 'wc-payment-monitor'); ?></h1>

			<div style="margin: 20px 0;">
				<p><?php esc_html_e('Use these tools to diagnose and resolve payment issues.', 'wc-payment-monitor'); ?></p>
			</div>

			<div class="wc-payment-monitor-diagnostics">
				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('System Diagnostics', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Run comprehensive health checks and diagnostics', 'wc-payment-monitor'); ?>
							</p>
						</div>
					</div>
					<div class="section-content">
						<button class="button button-primary" id="run-full-diagnostics">
							<?php esc_html_e('Run Full Diagnostics', 'wc-payment-monitor'); ?>
						</button>
						<button class="button" id="recalculate-health">
							<?php esc_html_e('Recalculate Health Metrics', 'wc-payment-monitor'); ?>
						</button>
						<div id="diagnostics-results" style="margin-top: 20px;"></div>
					</div>
				</div>

				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('Failure Simulator (Test Mode)', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Create test orders with simulated payment failures', 'wc-payment-monitor'); ?>
							</p>
						</div>
					</div>
					<div class="section-content">
						<p><strong><?php esc_html_e('Warning:', 'wc-payment-monitor'); ?></strong>
							<?php esc_html_e('These tools create test orders with simulated failures for testing purposes.', 'wc-payment-monitor'); ?>
						</p>

						<label for="failure-scenario"><?php esc_html_e('Failure Scenario:', 'wc-payment-monitor'); ?></label>
						<select id="failure-scenario">
							<option value="card_declined"><?php esc_html_e('Card Declined', 'wc-payment-monitor'); ?></option>
							<option value="insufficient_funds">
								<?php esc_html_e('Insufficient Funds', 'wc-payment-monitor'); ?></option>
							<option value="gateway_timeout"><?php esc_html_e('Gateway Timeout', 'wc-payment-monitor'); ?>
							</option>
							<option value="network_error"><?php esc_html_e('Network Error', 'wc-payment-monitor'); ?></option>
							<option value="fraud_detected"><?php esc_html_e('Fraud Detected', 'wc-payment-monitor'); ?>
							</option>
						</select>

						<label for="failure-gateway"><?php esc_html_e('Gateway:', 'wc-payment-monitor'); ?></label>
						<select id="failure-gateway">
							<option value=""><?php esc_html_e('Random Enabled Gateway', 'wc-payment-monitor'); ?></option>
							<?php
                            $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
        foreach ($available_gateways as $gateway_id => $gateway) {
            printf(
                '<option value="%s">%s</option>',
                esc_attr($gateway_id),
                esc_html(WC_Payment_Monitor::get_friendly_gateway_name($gateway_id))
            );
        }
        ?>
							<input type="number" id="failure-count" value="1" min="1" max="50" />

							<button class="button button-secondary" id="simulate-failure">
								<?php esc_html_e('Simulate Payment Failure', 'wc-payment-monitor'); ?>
							</button>
							<button class="button button-secondary" id="clear-simulated">
								<?php esc_html_e('Clear All Simulated Failures', 'wc-payment-monitor'); ?>
							</button>

							<div id="simulator-results" style="margin-top: 20px;"></div>
					</div>
				</div>

				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('Maintenance Tools', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Clean up and maintain transaction data', 'wc-payment-monitor'); ?></p>
						</div>
					</div>
					<div class="section-content">
						<button class="button" id="clean-orphaned">
							<?php esc_html_e('Clean Orphaned Records', 'wc-payment-monitor'); ?>
						</button>
						<button class="button" id="archive-old">
							<?php esc_html_e('Archive Old Transactions (90+ days)', 'wc-payment-monitor'); ?>
						</button>
						<button class="button button-secondary" id="reset-health">
							<?php esc_html_e('Reset All Health Metrics', 'wc-payment-monitor'); ?>
						</button>
						<div id="maintenance-results" style="margin-top: 20px;"></div>
					</div>
				</div>
			</div>

			<script>
				jQuery(document).ready(function ($) {
					// Helper: Format Gateway Results
					function formatGatewayResults(data) {
						var html = '<div class="card" style="max-width: 100%; margin-top: 10px; padding: 0;">';
						html += '<h3 style="padding: 10px 15px; margin: 0; background: #f8f9fa; border-bottom: 1px solid #ddd;"><?php esc_html_e('Gateway Status Report', 'wc-payment-monitor'); ?></h3>';
						
						if (data.issues && data.issues.length > 0) {
							html += '<div class="notice notice-warning inline" style="margin: 10px 15px;"><p><strong><?php esc_html_e('Issues Found:', 'wc-payment-monitor'); ?></strong></p><ul style="list-style: disc; margin-left: 20px;">';
							data.issues.forEach(function(issue) {
								html += '<li>' + issue + '</li>';
							});
							html += '</ul></div>';
						}

						html += '<table class="widefat striped" style="border: none; box-shadow: none;">';
						html += '<thead><tr>';
						html += '<th><?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Message', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Last Checked', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('24h Success Rate', 'wc-payment-monitor'); ?></th>';
						html += '</tr></thead><tbody>';

						if (data.gateways) {
							Object.keys(data.gateways).forEach(function(key) {
								var gateway = data.gateways[key];
								var statusIcon = gateway.status === 'online' ? '✅' : (gateway.status === 'offline' ? '❌' : '⚠️');
								var statusColor = gateway.status === 'online' ? 'green' : (gateway.status === 'offline' ? 'red' : 'orange');
								
								var successRate = '-';
								if (gateway.health_24h && gateway.health_24h.success_rate !== null) {
									successRate = gateway.health_24h.success_rate + '%';
								}

								html += '<tr>';
								html += '<td><strong>' + (gateway.id) + '</strong></td>';
								html += '<td><span style="color:' + statusColor + ';">' + statusIcon + ' ' + gateway.status.toUpperCase() + '</span></td>';
								html += '<td>' + (gateway.message || '-') + '</td>';
								html += '<td>' + (gateway.last_checked || 'Never') + '</td>';
								html += '<td>' + successRate + '</td>';
								html += '</tr>';
							});
						} else {
							html += '<tr><td colspan="5"><?php esc_html_e('No gateway data available.', 'wc-payment-monitor'); ?></td></tr>';
						}

						html += '</tbody></table></div>';
						return html;
					}

					// Helper: Format Full Diagnostics
					function formatFullDiagnostics(data) {
						var html = '<div style="margin-top: 15px;">';
						html += '<h3><?php esc_html_e('System Health Report', 'wc-payment-monitor'); ?> <span style="font-weight: normal; font-size: 13px; color: #666;">(' + data.timestamp + ')</span></h3>';

						// System Info
						if (data.system_info) {
							html += '<div class="card" style="margin-bottom: 20px; padding: 15px;">';
							html += '<h4 style="margin-top: 0;"><?php esc_html_e('System Information', 'wc-payment-monitor'); ?></h4>';
							html += '<table class="widefat fixed striped" style="width: 100%; border: 1px solid #eee;"><tbody>';
							Object.entries(data.system_info).forEach(function([key, value]) {
								var label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
								html += '<tr><td style="width: 200px; font-weight: 600;">' + label + '</td><td>' + value + '</td></tr>';
							});
							html += '</tbody></table></div>';
						}

						// Database
						if (data.database) {
							var dbStatus = data.database.status === 'healthy' ? '✅ Healthy' : '⚠️ Issues Found';
							html += '<div class="card" style="margin-bottom: 20px; padding: 15px;">';
							html += '<h4 style="margin-top: 0;"><?php esc_html_e('Database Status', 'wc-payment-monitor'); ?>: ' + dbStatus + '</h4>';
							
							if (data.database.issues && data.database.issues.length > 0) {
								html += '<div class="notice notice-warning inline"><ul style="margin: 5px 0 5px 20px; list-style: disc;">';
								data.database.issues.forEach(function(issue) {
									html += '<li>' + issue + '</li>';
								});
								html += '</ul></div>';
							}
							
							if (data.database.tables) {
								html += '<p><strong>Table Status:</strong></p>';
								html += '<ul style="margin-left: 20px; list-style: circle;">';
								Object.entries(data.database.tables).forEach(function([table, info]) {
									html += '<li><strong>' + table + ':</strong> ' + (info.exists ? 'Exists' : 'Missing') + ' (' + info.count + ' records)</li>';
								});
								html += '</ul>';
							}
							html += '</div>';
						}

						// Gateways section
						if (data.gateways) {
							html += formatGatewayResults(data.gateways);
						}
						
						html += '</div>';
						return html;
					}

					// Load available payment gateways on page load
					$.ajax({
						url: wcPaymentMonitor.apiUrl + 'simulator/gateways',
						method: 'GET',
						headers: {
							'X-WP-Nonce': wcPaymentMonitor.restNonce
						},
						success: function (response) {
							if (response.data && response.data.length > 0) {
								response.data.forEach(function (gateway) {
									if (gateway.enabled) {
										$('#failure-gateway').append(
											$('<option></option>')
												.attr('value', gateway.id)
												.text(gateway.title)
										);
									}
								});
							}
						}
					});

					// Add basic JavaScript functionality
					$('#run-full-diagnostics').on('click', function () {
						var $btn = $(this);
						$btn.prop('disabled', true).text('<?php esc_attr_e('Running...', 'wc-payment-monitor'); ?>');

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/full',
							method: 'GET',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#diagnostics-results').html(formatFullDiagnostics(response));
							},
							error: function (xhr) {
								$('#diagnostics-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false).text('<?php esc_attr_e('Run Full Diagnostics', 'wc-payment-monitor'); ?>');
							}
						});
					});

					$('#recalculate-health').on('click', function () {
						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/health/recalculate',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#diagnostics-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#diagnostics-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#simulate-failure').on('click', function () {
						var $btn = $(this);
						var scenario = $('#failure-scenario').val();
						var gateway = $('#failure-gateway').val();
						var count = parseInt($('#failure-count').val());

						$btn.prop('disabled', true).text('<?php esc_attr_e('Simulating...', 'wc-payment-monitor'); ?>');

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'simulator/simulate',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce,
								'Content-Type': 'application/json'
							},
							data: JSON.stringify({
								scenario: scenario,
								gateway_id: gateway,
								count: count
							}),
							success: function (response) {
								var msg = count > 1
									? 'Created ' + response.success + ' test orders with simulated failures'
									: response.message;
								$('#simulator-results').html('<div class="updated"><p>' + msg + '</p></div>');
							},
							error: function (xhr) {
								$('#simulator-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false).text('<?php esc_attr_e('Simulate Payment Failure', 'wc-payment-monitor'); ?>');
							}
						});
					});

					$('#clear-simulated').on('click', function () {
						if (!confirm('<?php esc_attr_e('Are you sure you want to delete all simulated test orders?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'simulator/clear',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#simulator-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#simulator-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#clean-orphaned').on('click', function () {
						if (!confirm('<?php esc_attr_e('Clean orphaned transaction records?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/maintenance/orphaned',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#archive-old').on('click', function () {
						if (!confirm('<?php esc_attr_e('Archive transactions older than 90 days?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/maintenance/archive',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#reset-health').on('click', function () {
						if (!confirm('<?php esc_attr_e('Reset all health metrics? This cannot be undone.', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/health/reset',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});
				});
			</script>

			<style>
				.wc-payment-monitor-diagnostics .diagnostic-section {
					margin-bottom: 20px;
				}

				.wc-payment-monitor-diagnostics .section-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					padding: 20px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 4px;
					margin-bottom: 0;
				}

				.wc-payment-monitor-diagnostics .section-header h2 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 20px;
					font-weight: 600;
				}

				.wc-payment-monitor-diagnostics .section-subtitle {
					margin: 0;
					color: #646970;
					font-size: 14px;
				}

				.wc-payment-monitor-diagnostics .section-content {
					padding: 20px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-top: none;
					border-radius: 0 0 4px 4px;
				}

				.wc-payment-monitor-diagnostics button {
					margin-right: 10px;
					margin-bottom: 10px;
				}

				.wc-payment-monitor-diagnostics label {
					display: inline-block;
					margin: 10px 10px 10px 0;
					font-weight: bold;
				}

				.wc-payment-monitor-diagnostics select,
				.wc-payment-monitor-diagnostics input[type="number"] {
					margin-right: 15px;
				}

				.wc-payment-monitor-diagnostics pre {
					background: #f5f5f5;
					padding: 15px;
					border: 1px solid #ddd;
					border-radius: 3px;
					overflow-x: auto;
					max-height: 500px;
				}
			</style>
		</div>
		<?php
    }

    /**
     * Get settings tabs
     *
     * @return array Settings tabs configuration
     */
    private function get_settings_tabs()
    {
        return [
            'general'       => __('General', 'wc-payment-monitor'),
            'notifications' => __('Notifications', 'wc-payment-monitor'),
            'gateways'      => __('Gateways', 'wc-payment-monitor'),
            'advanced'      => __('Advanced', 'wc-payment-monitor'),
            'license'       => __('License', 'wc-payment-monitor'),
        ];
    }
}
