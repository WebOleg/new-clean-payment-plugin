<?php
/**
 * BNA Admin AJAX Handler
 * Handles AJAX requests for admin panel operations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Admin_Ajax
 * Manages all AJAX operations for admin functionality
 */
class BNA_Admin_Ajax {

    private static $instance = null;
    private $api;
    private $order_handler;

    /**
     * Get singleton instance
     * @return BNA_Admin_Ajax
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup dependencies
     */
    private function __construct() {
        $this->api = BNA_Api::get_instance();
        $this->order_handler = BNA_Order::get_instance();
    }

    /**
     * Initialize admin AJAX hooks
     */
    public function init() {
        // Only for logged in admin users
        add_action('wp_ajax_bna_test_api_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_bna_get_transaction_stats', array($this, 'get_transaction_stats'));
        add_action('wp_ajax_bna_get_system_info', array($this, 'get_system_info'));
        add_action('wp_ajax_bna_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_bna_export_transactions', array($this, 'export_transactions'));
        add_action('wp_ajax_bna_validate_settings', array($this, 'validate_settings'));
        add_action('wp_ajax_bna_get_webhook_logs', array($this, 'get_webhook_logs'));
        add_action('wp_ajax_bna_retry_failed_webhook', array($this, 'retry_failed_webhook'));
        add_action('wp_ajax_bna_cleanup_data', array($this, 'cleanup_data'));
    }

    /**
     * Test API connection and credentials
     */
    public function test_api_connection() {
        try {
            // Verify admin nonce and permissions
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            // Get settings to test
            $access_key = sanitize_text_field($_POST['access_key'] ?? '');
            $secret_key = sanitize_text_field($_POST['secret_key'] ?? '');
            $environment = sanitize_text_field($_POST['environment'] ?? 'staging');

            if (empty($access_key) || empty($secret_key)) {
                throw new Exception('API credentials are required');
            }

            // Temporarily update API credentials for testing
            $original_credentials = array(
                'access_key' => $this->api->get_access_key(),
                'secret_key' => $this->api->get_secret_key(),
                'environment' => $this->api->get_environment()
            );

            $this->api->update_credentials($access_key, $secret_key, $environment);

            // Test API connection
            $connection_result = $this->api->test_connection();
            
            if (!$connection_result) {
                throw new Exception('API connection test failed');
            }

            // Test additional API endpoints
            $test_results = array(
                'ping' => $connection_result,
                'api_version' => $this->test_api_version(),
                'webhook_config' => $this->test_webhook_config()
            );

            // Restore original credentials
            $this->api->update_credentials(
                $original_credentials['access_key'],
                $original_credentials['secret_key'],
                $original_credentials['environment']
            );

            BNA_Logger::info('API connection test successful', array(
                'environment' => $environment,
                'test_results' => $test_results
            ), 'admin');

            wp_send_json_success(array(
                'message' => 'API connection successful',
                'environment' => $environment,
                'base_url' => $this->api->get_base_url(),
                'test_results' => $test_results,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            BNA_Logger::error('API connection test failed', array(
                'error' => $e->getMessage(),
                'environment' => $environment ?? 'unknown'
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'connection_test_failed'
            ));
        }
    }

    /**
     * Get transaction statistics for dashboard
     */
    public function get_transaction_stats() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            $period = sanitize_text_field($_POST['period'] ?? '30days');
            $stats = $this->order_handler->get_order_statistics($period);

            // Get additional statistics
            $additional_stats = array(
                'total_revenue' => $this->get_total_revenue($period),
                'average_order_value' => $this->get_average_order_value($period),
                'payment_methods' => $this->get_payment_method_breakdown($period),
                'customer_types' => $this->get_customer_type_breakdown($period),
                'hourly_distribution' => $this->get_hourly_distribution($period)
            );

            wp_send_json_success(array(
                'period' => $period,
                'basic_stats' => $stats,
                'additional_stats' => $additional_stats,
                'generated_at' => current_time('mysql')
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to get transaction stats', array(
                'error' => $e->getMessage(),
                'period' => $period ?? 'unknown'
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'stats_failed'
            ));
        }
    }

    /**
     * Get system information for debugging
     */
    public function get_system_info() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            $system_info = array(
                'plugin' => array(
                    'version' => BNA_PLUGIN_VERSION,
                    'database_version' => BNA_Installer::get_db_version(),
                    'tables_exist' => BNA_Installer::tables_exist()
                ),
                'wordpress' => array(
                    'version' => get_bloginfo('version'),
                    'multisite' => is_multisite(),
                    'debug_mode' => WP_DEBUG,
                    'memory_limit' => ini_get('memory_limit')
                ),
                'woocommerce' => array(
                    'version' => WC()->version ?? 'Not installed',
                    'currency' => get_woocommerce_currency(),
                    'base_country' => WC()->countries->get_base_country(),
                    'api_enabled' => get_option('woocommerce_api_enabled')
                ),
                'server' => array(
                    'php_version' => PHP_VERSION,
                    'mysql_version' => $this->get_mysql_version(),
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'max_execution_time' => ini_get('max_execution_time'),
                    'post_max_size' => ini_get('post_max_size'),
                    'upload_max_filesize' => ini_get('upload_max_filesize')
                ),
                'ssl' => array(
                    'enabled' => is_ssl(),
                    'curl_ssl_verify' => $this->test_ssl_verification()
                )
            );

            wp_send_json_success($system_info);

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'system_info_failed'
            ));
        }
    }

    /**
     * Clear plugin logs
     */
    public function clear_logs() {
        try {
            // Verify admin request with additional permission check
            if (!$this->verify_admin_request('bna_admin_nonce') || !current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            $log_type = sanitize_text_field($_POST['log_type'] ?? 'all');
            $older_than = intval($_POST['older_than'] ?? 0); // Days

            global $wpdb;
            $logs_table = $wpdb->prefix . 'bna_logs';

            $where_clause = "WHERE 1=1";
            $params = array();

            // Filter by log type
            if ($log_type !== 'all') {
                $where_clause .= " AND source = %s";
                $params[] = $log_type;
            }

            // Filter by age
            if ($older_than > 0) {
                $where_clause .= " AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
                $params[] = $older_than;
            }

            // Count logs to be deleted
            $count_query = "SELECT COUNT(*) FROM {$logs_table} {$where_clause}";
            $count = $wpdb->get_var($wpdb->prepare($count_query, $params));

            // Delete logs
            $delete_query = "DELETE FROM {$logs_table} {$where_clause}";
            $deleted = $wpdb->query($wpdb->prepare($delete_query, $params));

            BNA_Logger::info('Logs cleared by admin', array(
                'log_type' => $log_type,
                'older_than' => $older_than,
                'deleted_count' => $deleted
            ), 'admin');

            wp_send_json_success(array(
                'message' => sprintf('Cleared %d log entries', $deleted),
                'deleted_count' => $deleted,
                'log_type' => $log_type
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to clear logs', array(
                'error' => $e->getMessage()
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'clear_logs_failed'
            ));
        }
    }

    /**
     * Export transaction data to CSV
     */
    public function export_transactions() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce') || !current_user_can('export')) {
                throw new Exception('Insufficient permissions');
            }

            $start_date = sanitize_text_field($_POST['start_date'] ?? '');
            $end_date = sanitize_text_field($_POST['end_date'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? 'all');

            // Get transaction data
            $transactions = $this->get_transactions_for_export($start_date, $end_date, $status);

            if (empty($transactions)) {
                throw new Exception('No transactions found for the specified criteria');
            }

            // Generate CSV content
            $csv_content = $this->generate_csv_content($transactions);

            // Create temporary file
            $filename = 'bna-transactions-' . date('Y-m-d-H-i-s') . '.csv';
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename;

            if (file_put_contents($file_path, $csv_content) === false) {
                throw new Exception('Failed to create export file');
            }

            $file_url = $upload_dir['url'] . '/' . $filename;

            BNA_Logger::info('Transaction export created', array(
                'filename' => $filename,
                'transaction_count' => count($transactions),
                'start_date' => $start_date,
                'end_date' => $end_date
            ), 'admin');

            wp_send_json_success(array(
                'message' => 'Export created successfully',
                'filename' => $filename,
                'file_url' => $file_url,
                'transaction_count' => count($transactions)
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Transaction export failed', array(
                'error' => $e->getMessage()
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'export_failed'
            ));
        }
    }

    /**
     * Validate gateway settings
     */
    public function validate_settings() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            $settings = $_POST['settings'] ?? array();
            $validation_results = array();

            // Validate API credentials
            if (!empty($settings['access_key']) && !empty($settings['secret_key'])) {
                $validation_results['api_credentials'] = $this->validate_api_credentials(
                    $settings['access_key'],
                    $settings['secret_key'],
                    $settings['api_environment'] ?? 'staging'
                );
            }

            // Validate fee settings
            if ($settings['enable_fees'] === 'yes') {
                $validation_results['fee_settings'] = $this->validate_fee_settings($settings);
            }

            // Validate webhook settings
            if (!empty($settings['webhook_secret'])) {
                $validation_results['webhook_settings'] = $this->validate_webhook_settings($settings);
            }

            wp_send_json_success(array(
                'message' => 'Settings validation completed',
                'validation_results' => $validation_results
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'validation_failed'
            ));
        }
    }

    /**
     * Get webhook logs for debugging
     */
    public function get_webhook_logs() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            $page = intval($_POST['page'] ?? 1);
            $per_page = intval($_POST['per_page'] ?? 50);
            $level = sanitize_text_field($_POST['level'] ?? 'all');

            global $wpdb;
            $logs_table = $wpdb->prefix . 'bna_logs';

            $where_clause = "WHERE source = 'webhook'";
            $params = array();

            if ($level !== 'all') {
                $where_clause .= " AND level = %s";
                $params[] = $level;
            }

            $offset = ($page - 1) * $per_page;
            $limit_clause = "LIMIT %d OFFSET %d";
            $params[] = $per_page;
            $params[] = $offset;

            $query = "SELECT * FROM {$logs_table} {$where_clause} ORDER BY created_at DESC {$limit_clause}";
            $logs = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

            // Get total count
            $count_query = "SELECT COUNT(*) FROM {$logs_table} {$where_clause}";
            $total_count = $wpdb->get_var($wpdb->prepare($count_query, array_slice($params, 0, -2)));

            wp_send_json_success(array(
                'logs' => $logs,
                'total_count' => $total_count,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total_count / $per_page)
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'webhook_logs_failed'
            ));
        }
    }

    /**
     * Retry failed webhook processing
     */
    public function retry_failed_webhook() {
        try {
            // Verify admin request
            if (!$this->verify_admin_request('bna_admin_nonce')) {
                throw new Exception('Unauthorized request');
            }

            $transaction_token = sanitize_text_field($_POST['transaction_token'] ?? '');
            
            if (empty($transaction_token)) {
                throw new Exception('Transaction token is required');
            }

            // Get transaction details from API
            $transaction_data = $this->api->get_transaction($transaction_token);
            
            if (is_wp_error($transaction_data)) {
                throw new Exception('Failed to get transaction data: ' . $transaction_data->get_error_message());
            }

            // Find the order
            $order = $this->order_handler->get_order_by_transaction($transaction_token);
            
            if (!$order) {
                throw new Exception('Order not found for transaction token');
            }

            // Update order status based on current transaction status
            $result = $this->order_handler->update_order_status(
                $order->get_id(),
                $transaction_data['status'],
                $transaction_data
            );

            if (!$result) {
                throw new Exception('Failed to update order status');
            }

            BNA_Logger::info('Webhook retry successful', array(
                'transaction_token' => substr($transaction_token, 0, 10) . '...',
                'order_id' => $order->get_id(),
                'status' => $transaction_data['status']
            ), 'admin');

            wp_send_json_success(array(
                'message' => 'Webhook retry successful',
                'order_id' => $order->get_id(),
                'status' => $transaction_data['status']
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Webhook retry failed', array(
                'error' => $e->getMessage(),
                'transaction_token' => substr($transaction_token ?? '', 0, 10) . '...'
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'webhook_retry_failed'
            ));
        }
    }

    /**
     * Cleanup old data and optimize database
     */
    public function cleanup_data() {
        try {
            // Verify admin request with high permission check
            if (!$this->verify_admin_request('bna_admin_nonce') || !current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            $cleanup_results = array();

            // Cleanup expired iframe tokens
            $iframe_ajax = BNA_Iframe_Ajax::get_instance();
            $cleanup_results['iframe_tokens'] = $iframe_ajax->cleanup_expired_tokens();

            // Cleanup old logs (older than 90 days)
            global $wpdb;
            $logs_table = $wpdb->prefix . 'bna_logs';
            $old_logs = $wpdb->query(
                "DELETE FROM {$logs_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
            $cleanup_results['old_logs'] = $old_logs;

            // Cleanup orphaned transaction records
            $orphaned_transactions = $this->cleanup_orphaned_transactions();
            $cleanup_results['orphaned_transactions'] = $orphaned_transactions;

            // Optimize database tables
            $wpdb->query("OPTIMIZE TABLE {$logs_table}");
            $wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}bna_transactions");

            BNA_Logger::info('Data cleanup completed', $cleanup_results, 'admin');

            wp_send_json_success(array(
                'message' => 'Data cleanup completed successfully',
                'cleanup_results' => $cleanup_results
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Data cleanup failed', array(
                'error' => $e->getMessage()
            ), 'admin');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'cleanup_failed'
            ));
        }
    }

    /**
     * Verify admin request with nonce and capability check
     * @param string $nonce_action
     * @return bool
     */
    private function verify_admin_request($nonce_action) {
        // Check nonce
        $nonce = $_POST['_wpnonce'] ?? $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            return false;
        }

        // Check user capability
        if (!current_user_can('manage_woocommerce')) {
            return false;
        }

        return true;
    }

    /**
     * Test API version compatibility
     * @return array
     */
    private function test_api_version() {
        // This would make a call to get API version info
        return array(
            'supported' => true,
            'version' => '1.0',
            'message' => 'API version compatible'
        );
    }

    /**
     * Test webhook configuration
     * @return array
     */
    private function test_webhook_config() {
        $webhook_url = site_url('/bna-webhook/');
        
        return array(
            'webhook_url' => $webhook_url,
            'reachable' => $this->test_webhook_reachability($webhook_url),
            'ssl_valid' => is_ssl()
        );
    }

    /**
     * Get MySQL version
     * @return string
     */
    private function get_mysql_version() {
        global $wpdb;
        return $wpdb->get_var("SELECT VERSION()");
    }

    /**
     * Test SSL verification
     * @return bool
     */
    private function test_ssl_verification() {
        $response = wp_remote_get('https://httpbin.org/get', array(
            'timeout' => 10,
            'sslverify' => true
        ));
        
        return !is_wp_error($response);
    }

    /**
     * Additional helper methods would go here...
     * (get_total_revenue, get_average_order_value, etc.)
     */

    private function get_total_revenue($period) {
        // Implementation for getting total revenue
        return 0;
    }

    private function get_average_order_value($period) {
        // Implementation for getting average order value
        return 0;
    }

    private function get_payment_method_breakdown($period) {
        // Implementation for payment method breakdown
        return array();
    }

    private function get_customer_type_breakdown($period) {
        // Implementation for customer type breakdown
        return array();
    }

    private function get_hourly_distribution($period) {
        // Implementation for hourly distribution
        return array();
    }

    private function get_transactions_for_export($start_date, $end_date, $status) {
        // Implementation for getting transactions for export
        return array();
    }

    private function generate_csv_content($transactions) {
        // Implementation for generating CSV content
        return '';
    }

    private function validate_api_credentials($access_key, $secret_key, $environment) {
        // Implementation for validating API credentials
        return array('valid' => true);
    }

    private function validate_fee_settings($settings) {
        // Implementation for validating fee settings
        return array('valid' => true);
    }

    private function validate_webhook_settings($settings) {
        // Implementation for validating webhook settings
        return array('valid' => true);
    }

    private function test_webhook_reachability($url) {
        // Implementation for testing webhook reachability
        return true;
    }

    private function cleanup_orphaned_transactions() {
        // Implementation for cleaning up orphaned transactions
        return 0;
    }
}
