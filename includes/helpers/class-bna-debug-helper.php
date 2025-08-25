<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Debug Helper Class
 *
 * Comprehensive debugging system for BNA Gateway plugin.
 * Provides detailed logging, payload inspection, and debug output.
 */
class BNA_Debug_Helper {

    /**
     * Debug log file path
     *
     * @var string
     */
    private static $log_file;

    /**
     * Debug mode flag
     *
     * @var bool
     */
    private static $debug_mode;

    /**
     * Initialize debug helper
     *
     * @return void
     */
    public static function init() {
        self::$debug_mode = true; // Завжди увімкнено
        self::$log_file = WP_CONTENT_DIR . '/bna-debug.log';

        // Create log file if it doesn't exist
        if (!file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, "[" . current_time('Y-m-d H:i:s') . "] BNA Debug Log Started\n");
            chmod(self::$log_file, 0666);
        }

        // Додай AJAX обробники (ця частина була пропущена)
        add_action('wp_ajax_bna_get_debug_log', array(__CLASS__, 'ajax_get_debug_log'));
        add_action('wp_ajax_nopriv_bna_get_debug_log', array(__CLASS__, 'ajax_get_debug_log'));
        add_action('wp_ajax_bna_clear_debug_log', array(__CLASS__, 'ajax_clear_debug_log'));
    } // <- Ця закриваюча дужка була пропущена

    /**
     * Log debug message with timestamp and context
     *
     * @param string $message Debug message
     * @param array $context Additional context data
     * @param string $level Log level (INFO, ERROR, WARNING, DEBUG)
     * @return void
     */
    public static function log($message, $context = array(), $level = 'INFO') {
        if (!self::$debug_mode) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = self::format_log_message($timestamp, $level, $message, $context);

        // Write to debug log file
        file_put_contents(self::$log_file, $formatted_message . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Also log to WordPress debug.log
        error_log('BNA_DEBUG [' . $level . ']: ' . $message);

        if (!empty($context)) {
            error_log('BNA_DEBUG_CONTEXT: ' . json_encode($context, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Log API request details
     *
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @param string $method HTTP method
     * @return void
     */
    public static function log_api_request($endpoint, $payload, $method = 'POST') {
        self::log('API REQUEST STARTED', array(
            'endpoint' => $endpoint,
            'method' => $method,
            'payload' => $payload,
            'payload_size' => strlen(json_encode($payload)) . ' bytes'
        ), 'API');
    }

    /**
     * Log API response details
     *
     * @param int $status_code Response status code
     * @param string $response_body Response body
     * @param array $headers Response headers
     * @return void
     */
    public static function log_api_response($status_code, $response_body, $headers = array()) {
        $response_data = json_decode($response_body, true);

        self::log('API RESPONSE RECEIVED', array(
            'status_code' => $status_code,
            'success' => $status_code >= 200 && $status_code < 300,
            'response_body' => $response_data ?: $response_body,
            'response_size' => strlen($response_body) . ' bytes',
            'headers' => $headers
        ), 'API');
    }

    /**
     * Log order processing details
     *
     * @param WC_Order $order WooCommerce order
     * @param string $action Action being performed
     * @param array $additional_data Additional data
     * @return void
     */
    public static function log_order_processing($order, $action, $additional_data = array()) {
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'action' => $action
        );

        if (!empty($additional_data)) {
            $order_data = array_merge($order_data, $additional_data);
        }

        self::log('ORDER PROCESSING', $order_data, 'ORDER');
    }

    /**
     * Log iframe token generation
     *
     * @param string $token Generated token
     * @param array $config Configuration used
     * @return void
     */
    public static function log_iframe_token($token, $config) {
        self::log('IFRAME TOKEN GENERATED', array(
            'token' => $token,
            'token_length' => strlen($token),
            'iframe_id' => $config['iframe_id'] ?? 'NOT_SET',
            'mode' => $config['mode'] ?? 'NOT_SET',
            'api_url' => $config['api_url'] ?? 'NOT_SET'
        ), 'IFRAME');
    }

    /**
     * Log customer data processing
     *
     * @param array $customer_data Customer data
     * @param string $action Action being performed
     * @return void
     */
    public static function log_customer_processing($customer_data, $action) {
        // Remove sensitive data for logging
        $safe_data = $customer_data;
        if (isset($safe_data['phoneNumber'])) {
            $safe_data['phoneNumber'] = substr($safe_data['phoneNumber'], 0, 3) . '***';
        }

        self::log('CUSTOMER PROCESSING', array(
            'action' => $action,
            'customer_data' => $safe_data,
            'has_customer_id' => isset($customer_data['customerId']),
            'has_customer_info' => isset($customer_data['customerInfo'])
        ), 'CUSTOMER');
    }

    /**
     * Format log message for file output
     *
     * @param string $timestamp Timestamp
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     * @return string Formatted message
     */
    private static function format_log_message($timestamp, $level, $message, $context) {
        $formatted = "[$timestamp] [$level] $message";

        if (!empty($context)) {
            $formatted .= "\n" . str_repeat('=', 80);
            $formatted .= "\nCONTEXT DATA:";
            $formatted .= "\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $formatted .= "\n" . str_repeat('=', 80);
        }

        return $formatted;
    }

    /**
     * Get debug log content via AJAX
     *
     * @return void
     */
    public static function ajax_get_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $log_content = '';
        if (file_exists(self::$log_file)) {
            $log_content = file_get_contents(self::$log_file);
            // Get last 100 KB for performance
            if (strlen($log_content) > 102400) {
                $log_content = '...[TRUNCATED]...' . substr($log_content, -102400);
            }
        }

        wp_send_json_success(array(
            'log_content' => $log_content,
            'log_size' => file_exists(self::$log_file) ? filesize(self::$log_file) : 0,
            'last_modified' => file_exists(self::$log_file) ? date('Y-m-d H:i:s', filemtime(self::$log_file)) : 'Never'
        ));
    }

    /**
     * Clear debug log via AJAX
     *
     * @return void
     */
    public static function ajax_clear_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (file_exists(self::$log_file)) {
            unlink(self::$log_file);
        }

        wp_send_json_success('Debug log cleared successfully');
    }

    /**
     * Get debug statistics
     *
     * @return array Debug statistics
     */
    public static function get_debug_stats() {
        $stats = array(
            'debug_mode' => self::$debug_mode,
            'log_file_exists' => file_exists(self::$log_file),
            'log_file_size' => file_exists(self::$log_file) ? filesize(self::$log_file) : 0,
            'log_file_path' => self::$log_file,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
        );

        return $stats;
    }

    /**
     * Get debug viewer HTML
     *
     * @return string HTML content for debug viewer
     */
    public static function get_debug_viewer_html() {
        ob_start();
        ?>
        <div id="bna-debug-viewer" style="margin: 20px 0; font-family: monospace;">
            <h3>BNA Gateway Debug Viewer</h3>

            <div style="margin: 10px 0;">
                <button id="bna-refresh-log" class="button">Refresh Log</button>
                <button id="bna-clear-log" class="button">Clear Log</button>
                <span id="bna-log-info" style="margin-left: 20px; color: #666;"></span>
            </div>

            <div id="bna-log-content" style="background: #f1f1f1; padding: 15px; border: 1px solid #ccc; height: 400px; overflow-y: auto; white-space: pre-wrap; font-size: 12px;"></div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function refreshLog() {
                    $.post(ajaxurl, {
                        action: 'bna_get_debug_log'
                    }, function(response) {
                        if (response.success) {
                            $('#bna-log-content').text(response.data.log_content);
                            $('#bna-log-info').text('Size: ' + response.data.log_size + ' bytes | Last modified: ' + response.data.last_modified);
                        }
                    });
                }

                $('#bna-refresh-log').click(refreshLog);

                $('#bna-clear-log').click(function() {
                    if (confirm('Are you sure you want to clear the debug log?')) {
                        $.post(ajaxurl, {
                            action: 'bna_clear_debug_log'
                        }, function(response) {
                            if (response.success) {
                                refreshLog();
                                alert('Debug log cleared successfully');
                            }
                        });
                    }
                });

                // Auto-refresh every 10 seconds
                setInterval(refreshLog, 10000);

                // Initial load
                refreshLog();
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
