<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Debug AJAX Handler
 *
 * Handles AJAX requests for debug functionality
 */
class BNA_Debug_Ajax_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_bna_test_debug_logging', array($this, 'test_debug_logging'));
        add_action('wp_ajax_bna_test_api_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_bna_export_debug_logs', array($this, 'export_debug_logs'));
    }

    /**
     * Test debug logging functionality
     *
     * @return void
     */
    public function test_debug_logging() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'bna_debug_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Test various debug log levels
        BNA_Debug_Helper::log('Debug test started', array(
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('Y-m-d H:i:s'),
            'test_data' => array(
                'string' => 'test string',
                'number' => 12345,
                'boolean' => true,
                'array' => array('item1', 'item2', 'item3')
            )
        ), 'DEBUG');

        BNA_Debug_Helper::log('Info level test message', array(
            'info_type' => 'manual_test'
        ), 'INFO');

        BNA_Debug_Helper::log('Warning level test message', array(
            'warning_type' => 'manual_test'
        ), 'WARNING');

        BNA_Debug_Helper::log('API test log entry', array(
            'endpoint' => '/test/endpoint',
            'method' => 'POST',
            'payload' => array('test' => 'data')
        ), 'API');

        wp_send_json_success('Debug logging test completed successfully');
    }

    /**
     * Test API connection
     *
     * @return void
     */
    public function test_api_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'bna_debug_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Get BNA Gateway settings
        $gateway = new BNA_Gateway();
        $config = array(
            'mode' => $gateway->get_option('mode', 'development'),
            'iframe_id' => $gateway->get_option('iframe_id'),
            'access_key' => $gateway->get_option('access_key'),
            'secret_key' => $gateway->get_option('secret_key')
        );

        BNA_Debug_Helper::log('API connection test started', array(
            'config' => array_merge($config, array('secret_key' => '***HIDDEN***')),
            'test_type' => 'manual_admin_test'
        ), 'API');

        // Test API connection
        try {
            $api_helper = new BNA_Api_Helper($config);
            $api_url = $api_helper->get_api_url();

            // Test basic API health check if available
            $response = wp_remote_get($api_url, array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($config['access_key'] . ':' . $config['secret_key']),
                    'Accept' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                BNA_Debug_Helper::log('API connection test failed', array(
                    'error' => $response->get_error_message(),
                    'api_url' => $api_url
                ), 'ERROR');

                wp_send_json_error('API connection failed: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            BNA_Debug_Helper::log('API connection test completed', array(
                'response_code' => $response_code,
                'api_url' => $api_url,
                'response_size' => strlen($response_body) . ' bytes',
                'connection_successful' => $response_code < 500
            ), 'API');

            if ($response_code >= 500) {
                wp_send_json_error('API server error (code: ' . $response_code . ')');
            }

            wp_send_json_success('API connection test completed (response code: ' . $response_code . ')');

        } catch (Exception $e) {
            BNA_Debug_Helper::log('API connection test exception', array(
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString()
            ), 'ERROR');

            wp_send_json_error('API test failed: ' . $e->getMessage());
        }
    }

    /**
     * Export debug logs
     *
     * @return void
     */
    public function export_debug_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (!wp_verify_nonce($_GET['_ajax_nonce'], 'bna_debug_nonce')) {
            wp_die('Security check failed');
        }

        $debug_stats = BNA_Debug_Helper::get_debug_stats();
        $log_file = $debug_stats['log_file_path'];

        if (!file_exists($log_file)) {
            wp_die('Debug log file not found');
        }

        $filename = 'bna-debug-' . date('Y-m-d-H-i-s') . '.log';

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($log_file));

        readfile($log_file);
        exit;
    }
}
