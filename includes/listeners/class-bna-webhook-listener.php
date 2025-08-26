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

    /**
     * Add webhook endpoint to WordPress
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

        add_action('template_redirect', array($this, 'handle_webhook_request'));
    }

    /**
     * Handle webhook request from template redirect
     */
    public function handle_webhook_request() {
        if (get_query_var('bna_webhook')) {
            $this->handle_webhook();
            exit;
        }
    }

    /**
     * Main webhook handler
     */
    public function handle_webhook() {
        $input = file_get_contents('php://input');
        $headers = $this->get_request_headers();

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

    /**
     * Validate webhook data structure
     */
    private function validate_webhook_data($data) {
        $required_fields = array('id', 'status', 'referenceUUID');

        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        $order = $this->find_order_by_reference($data['referenceUUID']);

        if (!$order) {
            return;
        }

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
        }
    }

    /**
     * Find order by reference UUID
     */
    private function find_order_by_reference($reference_uuid) {
        $orders = wc_get_orders(array(
            'meta_key' => '_transaction_id',
            'meta_value' => $reference_uuid,
            'limit' => 1,
        ));

        if (!empty($orders)) {
            return $orders[0];
        }

        $orders = wc_get_orders(array(
            'meta_key' => '_bna_transaction_id',
            'meta_value' => $reference_uuid,
            'limit' => 1,
        ));

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Handle successful payment webhook
     */
    private function handle_webhook_success($order, $data) {
        if ($order->has_status(array('completed', 'processing'))) {
            return;
        }

        $order->payment_complete($data['id']);
        $order->add_order_note('Payment confirmed via BNA webhook. Transaction ID: ' . $data['id']);
    }

    /**
     * Handle failed payment webhook
     */
    private function handle_webhook_failure($order, $data) {
        $message = isset($data['message']) ? $data['message'] : 'Payment failed';
        $order->update_status('failed', 'Payment failed via webhook: ' . $message);
    }

    /**
     * Handle cancelled payment webhook
     */
    private function handle_webhook_cancellation($order, $data) {
        $message = isset($data['message']) ? $data['message'] : 'Payment cancelled';
        $order->update_status('cancelled', 'Payment cancelled via webhook: ' . $message);
    }

    /**
     * Get all request headers
     */
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

    /**
     * Send HTTP response
     */
    private function send_response($code, $message) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array('message' => $message));
        exit;
    }
}
