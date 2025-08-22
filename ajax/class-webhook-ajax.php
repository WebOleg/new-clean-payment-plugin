<?php
/**
 * BNA Webhook AJAX Handler
 * Handles webhook notifications from BNA Smart Payment API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Webhook_Ajax
 * Processes webhook notifications for payment status updates
 */
class BNA_Webhook_Ajax {

    private static $instance = null;
    private $order_handler;
    private $api;

    /**
     * Get singleton instance
     * @return BNA_Webhook_Ajax
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
        $this->order_handler = BNA_Order::get_instance();
        $this->api = BNA_Api::get_instance();
    }

    /**
     * Initialize webhook hooks
     */
    public function init() {
        // Webhook endpoint
        add_action('wp_ajax_bna_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_bna_webhook', array($this, 'handle_webhook'));
        
        // WooCommerce API webhook endpoint
        add_action('woocommerce_api_bna_webhook', array($this, 'handle_webhook'));
        
        // Custom webhook endpoint
        add_action('init', array($this, 'add_webhook_endpoint'));
    }

    /**
     * Add custom webhook endpoint
     */
    public function add_webhook_endpoint() {
        add_rewrite_rule(
            '^bna-webhook/?$',
            'index.php?bna_webhook=1',
            'top'
        );
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'bna_webhook';
            return $vars;
        });
        
        add_action('template_redirect', function() {
            if (get_query_var('bna_webhook')) {
                $this->handle_webhook();
                exit;
            }
        });
    }

    /**
     * Main webhook handler
     */
    public function handle_webhook() {
        try {
            // Set proper response headers
            $this->set_webhook_headers();

            // Get raw webhook data
            $raw_body = file_get_contents('php://input');
            $headers = $this->get_request_headers();
            
            if (empty($raw_body)) {
                throw new Exception('Empty webhook payload');
            }

            // Parse webhook data
            $webhook_data = json_decode($raw_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
            }

            // Log incoming webhook
            BNA_Logger::info('Webhook received', array(
                'headers' => $headers,
                'payload_size' => strlen($raw_body),
                'event_type' => $webhook_data['event_type'] ?? 'unknown'
            ), 'webhook');

            // Verify webhook signature
            if (!$this->verify_webhook_signature($raw_body, $headers)) {
                throw new Exception('Invalid webhook signature');
            }

            // Validate webhook structure
            $validation_result = $this->validate_webhook_structure($webhook_data);
            if (is_wp_error($validation_result)) {
                throw new Exception($validation_result->get_error_message());
            }

            // Process webhook based on event type
            $result = $this->process_webhook_event($webhook_data);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Send success response
            $this->send_webhook_response(200, array(
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'event_id' => $webhook_data['event_id'] ?? null
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Webhook processing failed', array(
                'error' => $e->getMessage(),
                'payload' => substr($raw_body ?? '', 0, 500),
                'headers' => $headers ?? array()
            ), 'webhook');

            // Send error response
            $this->send_webhook_response(400, array(
                'status' => 'error',
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * Process webhook event based on type
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function process_webhook_event($webhook_data) {
        $event_type = $webhook_data['event_type'] ?? '';
        
        switch ($event_type) {
            case 'payment.completed':
            case 'payment.approved':
            case 'payment.success':
                return $this->handle_payment_success($webhook_data);
                
            case 'payment.failed':
            case 'payment.declined':
            case 'payment.error':
                return $this->handle_payment_failure($webhook_data);
                
            case 'payment.pending':
                return $this->handle_payment_pending($webhook_data);
                
            case 'payment.cancelled':
                return $this->handle_payment_cancelled($webhook_data);
                
            case 'payment.refunded':
            case 'refund.completed':
                return $this->handle_payment_refunded($webhook_data);
                
            case 'payment.chargeback':
                return $this->handle_payment_chargeback($webhook_data);
                
            default:
                BNA_Logger::warning('Unknown webhook event type', array(
                    'event_type' => $event_type,
                    'webhook_data' => $webhook_data
                ), 'webhook');
                
                return new WP_Error('unknown_event', 'Unknown webhook event type: ' . $event_type);
        }
    }

    /**
     * Handle successful payment webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_success($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        // Find order by transaction token
        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        // Prevent duplicate processing
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            BNA_Logger::info('Order already processed, skipping', array(
                'order_id' => $order->get_id(),
                'current_status' => $order->get_status()
            ), 'webhook');
            return true;
        }

        // Update order status to processing
        $this->order_handler->update_order_status(
            $order->get_id(),
            'completed',
            $webhook_data
        );

        // Add payment complete note
        $order->add_order_note(sprintf(
            'BNA payment completed successfully. Reference: %s',
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        // Reduce stock levels
        wc_reduce_stock_levels($order->get_id());

        // Trigger WooCommerce payment complete actions
        $order->payment_complete($transaction_token);

        BNA_Logger::info('Payment success processed', array(
            'order_id' => $order->get_id(),
            'transaction_token' => substr($transaction_token, 0, 10) . '...',
            'amount' => $webhook_data['amount'] ?? 'unknown'
        ), 'webhook');

        return true;
    }

    /**
     * Handle failed payment webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_failure($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        // Update order status to failed
        $this->order_handler->update_order_status(
            $order->get_id(),
            'failed',
            $webhook_data
        );

        // Add failure note with reason
        $failure_reason = $webhook_data['failure_reason'] ?? $webhook_data['message'] ?? 'Payment failed';
        $order->add_order_note(sprintf(
            'BNA payment failed. Reason: %s. Reference: %s',
            $failure_reason,
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        BNA_Logger::info('Payment failure processed', array(
            'order_id' => $order->get_id(),
            'transaction_token' => substr($transaction_token, 0, 10) . '...',
            'failure_reason' => $failure_reason
        ), 'webhook');

        return true;
    }

    /**
     * Handle pending payment webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_pending($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        // Update order with pending status
        $this->order_handler->update_order_status(
            $order->get_id(),
            'pending',
            $webhook_data
        );

        $order->add_order_note(sprintf(
            'BNA payment is pending. Reference: %s',
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        BNA_Logger::info('Payment pending processed', array(
            'order_id' => $order->get_id(),
            'transaction_token' => substr($transaction_token, 0, 10) . '...'
        ), 'webhook');

        return true;
    }

    /**
     * Handle cancelled payment webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_cancelled($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        // Update order status to cancelled
        $this->order_handler->update_order_status(
            $order->get_id(),
            'cancelled',
            $webhook_data
        );

        $order->add_order_note(sprintf(
            'BNA payment was cancelled. Reference: %s',
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        BNA_Logger::info('Payment cancellation processed', array(
            'order_id' => $order->get_id(),
            'transaction_token' => substr($transaction_token, 0, 10) . '...'
        ), 'webhook');

        return true;
    }

    /**
     * Handle refunded payment webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_refunded($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        $refund_amount = floatval($webhook_data['refund_amount'] ?? $webhook_data['amount'] ?? 0);
        $refund_reason = $webhook_data['refund_reason'] ?? 'BNA API refund';

        // Create WooCommerce refund
        $refund = wc_create_refund(array(
            'order_id' => $order->get_id(),
            'amount' => $refund_amount,
            'reason' => $refund_reason
        ));

        if (is_wp_error($refund)) {
            BNA_Logger::error('Failed to create WooCommerce refund', array(
                'order_id' => $order->get_id(),
                'error' => $refund->get_error_message()
            ), 'webhook');
        }

        // Update order status if fully refunded
        if ($refund_amount >= $order->get_total()) {
            $order->update_status('refunded');
        }

        $order->add_order_note(sprintf(
            'BNA refund processed. Amount: %s %s. Reference: %s',
            $refund_amount,
            $order->get_currency(),
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        BNA_Logger::info('Refund processed', array(
            'order_id' => $order->get_id(),
            'refund_amount' => $refund_amount,
            'transaction_token' => substr($transaction_token, 0, 10) . '...'
        ), 'webhook');

        return true;
    }

    /**
     * Handle chargeback webhook
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function handle_payment_chargeback($webhook_data) {
        $transaction_token = $webhook_data['transaction_token'] ?? '';
        
        if (empty($transaction_token)) {
            return new WP_Error('missing_token', 'Transaction token missing from webhook');
        }

        $order = $this->order_handler->get_order_by_transaction($transaction_token);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for transaction: ' . $transaction_token);
        }

        // Add chargeback note
        $chargeback_amount = $webhook_data['chargeback_amount'] ?? $webhook_data['amount'] ?? 'unknown';
        $order->add_order_note(sprintf(
            'BNA chargeback received. Amount: %s. Reference: %s',
            $chargeback_amount,
            $webhook_data['reference_number'] ?? 'N/A'
        ), false, true);

        // Update order meta with chargeback info
        $order->update_meta_data('_bna_chargeback', array(
            'amount' => $chargeback_amount,
            'date' => current_time('mysql'),
            'reference' => $webhook_data['reference_number'] ?? ''
        ));
        $order->save();

        BNA_Logger::warning('Chargeback processed', array(
            'order_id' => $order->get_id(),
            'chargeback_amount' => $chargeback_amount,
            'transaction_token' => substr($transaction_token, 0, 10) . '...'
        ), 'webhook');

        return true;
    }

    /**
     * Verify webhook signature
     * @param string $payload
     * @param array $headers
     * @return bool
     */
    private function verify_webhook_signature($payload, $headers) {
        $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
        $webhook_secret = $gateway_settings['webhook_secret'] ?? '';
        
        // Skip verification if no secret configured
        if (empty($webhook_secret)) {
            BNA_Logger::warning('Webhook signature verification skipped - no secret configured', array(), 'webhook');
            return true;
        }

        // Get signature from headers
        $signature = $headers['X-BNA-Signature'] ?? $headers['HTTP_X_BNA_SIGNATURE'] ?? '';
        
        if (empty($signature)) {
            BNA_Logger::error('Webhook signature missing from headers', array('headers' => array_keys($headers)), 'webhook');
            return false;
        }

        // Calculate expected signature
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        
        // Compare signatures
        $is_valid = hash_equals($expected_signature, $signature);
        
        if (!$is_valid) {
            BNA_Logger::error('Webhook signature verification failed', array(
                'expected_length' => strlen($expected_signature),
                'received_length' => strlen($signature)
            ), 'webhook');
        }

        return $is_valid;
    }

    /**
     * Validate webhook structure
     * @param array $webhook_data
     * @return bool|WP_Error
     */
    private function validate_webhook_structure($webhook_data) {
        $required_fields = array('event_type', 'transaction_token');
        
        foreach ($required_fields as $field) {
            if (!isset($webhook_data[$field]) || empty($webhook_data[$field])) {
                return new WP_Error('invalid_structure', 'Missing required field: ' . $field);
            }
        }

        return true;
    }

    /**
     * Get request headers
     * @return array
     */
    private function get_request_headers() {
        $headers = array();
        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('HTTP_', '', $key);
                $header = str_replace('_', '-', $header);
                $headers[$header] = $value;
            }
        }
        
        return $headers;
    }

    /**
     * Set webhook response headers
     */
    private function set_webhook_headers() {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
        }
    }

    /**
     * Send webhook response
     * @param int $status_code
     * @param array $data
     */
    private function send_webhook_response($status_code, $data) {
        if (!headers_sent()) {
            http_response_code($status_code);
        }
        
        echo wp_json_encode($data);
        exit;
    }

    /**
     * Get webhook statistics
     * @return array
     */
    public function get_webhook_statistics() {
        global $wpdb;

        // This would require a webhook log table, but for now we can use the logger
        $logs_table = $wpdb->prefix . 'bna_logs';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") != $logs_table) {
            return array('error' => 'Logs table not found');
        }

        $stats = $wpdb->get_results(
            "SELECT 
                COUNT(*) as total_webhooks,
                SUM(CASE WHEN level = 'info' THEN 1 ELSE 0 END) as successful_webhooks,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as failed_webhooks,
                DATE(created_at) as date
             FROM {$logs_table} 
             WHERE source = 'webhook' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC",
            ARRAY_A
        );

        return $stats;
    }
}
