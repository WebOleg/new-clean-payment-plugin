<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Webhook Listener Class
 */
class BNA_Webhook_Listener {

    private $endpoint = 'bna_webhook';

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_' . $this->endpoint, array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_' . $this->endpoint, array($this, 'handle_webhook'));
        add_action('init', array($this, 'add_webhook_endpoint'));
    }

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

        add_action('template_redirect', array($this, 'handle_webhook_request'));
    }

    public function handle_webhook_request() {
        if (get_query_var('bna_webhook')) {
            $this->handle_webhook();
            exit;
        }
    }

    public function handle_webhook() {
        $input = file_get_contents('php://input');
        $headers = $this->get_request_headers();
        
        error_log('BNA Webhook received: ' . $input);
        error_log('BNA Webhook headers: ' . json_encode($headers));

        if (empty($input)) {
            $this->send_response(400, 'Empty payload');
            return;
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->send_response(400, 'Invalid JSON');
            return;
        }

        if (!$this->validate_webhook_data($data)) {
            $this->send_response(400, 'Invalid webhook data');
            return;
        }

        $this->process_webhook($data);
        $this->send_response(200, 'OK');
    }

    private function validate_webhook_data($data) {
        $required_fields = array('id', 'status', 'referenceUUID');
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                error_log('BNA Webhook: Missing field ' . $field);
                return false;
            }
        }

        return true;
    }

    private function process_webhook($data) {
        // Find order by reference UUID or transaction ID
        $order = $this->find_order_by_reference($data['referenceUUID']);
        
        if (!$order) {
            error_log('BNA Webhook: Order not found for reference ' . $data['referenceUUID']);
            return;
        }

        error_log('BNA Webhook: Processing for order ' . $order->get_id());

        // Process based on webhook event type
        switch (strtolower($data['status'])) {
            case 'approved':
            case 'completed':
                $this->handle_webhook_success($order, $data);
                break;
                
            case 'declined':
            case 'failed':
                $this->handle_webhook_failure($order, $data);
                break;
                
            case 'cancelled':
            case 'canceled':
                $this->handle_webhook_cancellation($order, $data);
                break;
                
            default:
                error_log('BNA Webhook: Unknown status ' . $data['status']);
        }
    }

    private function find_order_by_reference($reference_uuid) {
        // Search by transaction ID
        $orders = wc_get_orders(array(
            'meta_key' => '_transaction_id',
            'meta_value' => $reference_uuid,
            'limit' => 1,
        ));

        if (!empty($orders)) {
            return $orders[0];
        }

        // Search by BNA transaction ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_transaction_id', 
            'meta_value' => $reference_uuid,
            'limit' => 1,
        ));

        return !empty($orders) ? $orders[0] : null;
    }

    private function handle_webhook_success($order, $data) {
        if ($order->has_status(array('completed', 'processing'))) {
            return;
        }

        $order->payment_complete($data['id']);
        $order->add_order_note('Payment confirmed via BNA webhook. Transaction ID: ' . $data['id']);
        
        error_log('BNA Webhook: Payment completed for order ' . $order->get_id());
    }

    private function handle_webhook_failure($order, $data) {
        $message = isset($data['message']) ? $data['message'] : 'Payment failed';
        $order->update_status('failed', 'Payment failed via webhook: ' . $message);
        
        error_log('BNA Webhook: Payment failed for order ' . $order->get_id());
    }

    private function handle_webhook_cancellation($order, $data) {
        $message = isset($data['message']) ? $data['message'] : 'Payment cancelled';
        $order->update_status('cancelled', 'Payment cancelled via webhook: ' . $message);
        
        error_log('BNA Webhook: Payment cancelled for order ' . $order->get_id());
    }

    private function get_request_headers() {
        $headers = array();
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) === 'HTTP_') {
                    $header = str_replace('_', '-', substr($key, 5));
                    $headers[$header] = $value;
                }
            }
        }
        
        return $headers;
    }

    private function send_response($code, $message) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array('message' => $message));
        exit;
    }
}
