<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Webhook Listener Class - Updated for Real BNA Webhook Structure
 */
class BNA_Webhook_Listener {

    private $endpoint = 'bna_webhook';

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_' . $this->endpoint, array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_' . $this->endpoint, array($this, 'handle_webhook'));
        // Using wp_loaded instead of init
        add_action('wp_loaded', array($this, 'add_webhook_endpoint'));
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

        // DEBUG: Log raw webhook data
        BNA_Simple_Logger::log("RAW WEBHOOK RECEIVED", array(
            'raw_input' => $input,
            'headers' => $headers,
            'method' => $_SERVER['REQUEST_METHOD'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ));

        if (empty($input)) {
            BNA_Simple_Logger::log("WEBHOOK ERROR: Empty payload");
            $this->send_response(400, 'Empty payload');
            return;
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            BNA_Simple_Logger::log("WEBHOOK ERROR: Invalid JSON", array(
                'json_error' => json_last_error_msg(),
                'raw_input' => $input
            ));
            $this->send_response(400, 'Invalid JSON');
            return;
        }

        // DEBUG: Log parsed webhook data
        BNA_Simple_Logger::log("PARSED WEBHOOK DATA", $data);

        if (!$this->validate_webhook_data($data)) {
            BNA_Simple_Logger::log("WEBHOOK ERROR: Invalid webhook data structure", $data);
            $this->send_response(400, 'Invalid webhook data');
            return;
        }

        // Extract transaction data from BNA structure
        $transaction_data = $this->extract_transaction_data($data);

        BNA_Simple_Logger::log("WEBHOOK VALIDATION PASSED", array(
            'event' => $data['event'] ?? 'unknown',
            'transaction_id' => $transaction_data['id'],
            'status' => $transaction_data['status'],
            'referenceUUID' => $transaction_data['referenceUUID']
        ));

        $this->process_webhook($data, $transaction_data);
        $this->send_response(200, 'OK');
    }

    /**
     * Extract transaction data from BNA webhook structure
     */
    private function extract_transaction_data($data) {
        // BNA webhook structure: data.transaction contains the actual transaction
        if (isset($data['data']['transaction'])) {
            return $data['data']['transaction'];
        }

        // Fallback to old structure
        return $data;
    }

    /**
     * Validate webhook data structure
     */
    private function validate_webhook_data($data) {
        // Check for BNA webhook structure
        if (isset($data['event']) && isset($data['data']['transaction'])) {
            $transaction = $data['data']['transaction'];
            $required_fields = array('id', 'status', 'referenceUUID');

            foreach ($required_fields as $field) {
                if (!isset($transaction[$field]) || empty($transaction[$field])) {
                    BNA_Simple_Logger::log("WEBHOOK VALIDATION FAILED - BNA Structure", array(
                        'missing_field' => $field,
                        'transaction_keys' => array_keys($transaction),
                        'event' => $data['event']
                    ));
                    return false;
                }
            }
            return true;
        }

        // Fallback to old structure validation
        $required_fields = array('id', 'status', 'referenceUUID');
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                BNA_Simple_Logger::log("WEBHOOK VALIDATION FAILED - Old Structure", array(
                    'missing_field' => $field,
                    'data_keys' => array_keys($data)
                ));
                return false;
            }
        }
        return true;
    }

    /**
     * Process webhook data
     */
    private function process_webhook($data, $transaction_data) {
        $event = $data['event'] ?? 'unknown';

        BNA_Simple_Logger::log("PROCESSING WEBHOOK", array(
            'event' => $event,
            'transaction_id' => $transaction_data['id'],
            'status' => $transaction_data['status'],
            'referenceUUID' => $transaction_data['referenceUUID']
        ));

        $order = $this->find_order_by_reference($transaction_data['referenceUUID']);

        if (!$order) {
            BNA_Simple_Logger::log("ORDER NOT FOUND", array(
                'referenceUUID' => $transaction_data['referenceUUID'],
                'event' => $event,
                'searched_meta_fields' => array(
                    '_transaction_id',
                    '_bna_transaction_id',
                    '_bna_checkout_token',
                    'referenceUUID'
                )
            ));
            return;
        }

        BNA_Simple_Logger::log("ORDER FOUND", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status(),
            'webhook_event' => $event,
            'transaction_status' => $transaction_data['status']
        ));

        // Handle different BNA events
        switch ($event) {
            case 'transaction.approved':
            case 'transaction.completed':
                $this->handle_webhook_success($order, $transaction_data);
                break;

            case 'transaction.declined':
            case 'transaction.failed':
                $this->handle_webhook_failure($order, $transaction_data);
                break;

            case 'transaction.canceled':
            case 'transaction.cancelled':
                $this->handle_webhook_cancellation($order, $transaction_data);
                break;

            case 'transaction.created':
                // Transaction created - usually PROCESSING status
                $this->handle_transaction_created($order, $transaction_data);
                break;

            default:
                // Handle based on status if event is unknown
                switch (strtoupper($transaction_data['status'])) {
                    case 'APPROVED':
                    case 'COMPLETED':
                        $this->handle_webhook_success($order, $transaction_data);
                        break;

                    case 'DECLINED':
                    case 'FAILED':
                        $this->handle_webhook_failure($order, $transaction_data);
                        break;

                    case 'CANCELLED':
                    case 'CANCELED':
                        $this->handle_webhook_cancellation($order, $transaction_data);
                        break;

                    case 'PROCESSING':
                        $this->handle_transaction_processing($order, $transaction_data);
                        break;

                    default:
                        BNA_Simple_Logger::log("UNKNOWN WEBHOOK EVENT AND STATUS", array(
                            'event' => $event,
                            'status' => $transaction_data['status'],
                            'order_id' => $order->get_id()
                        ));
                }
        }
    }

    /**
     * Enhanced order search by reference UUID
     */
    private function find_order_by_reference($reference_uuid) {
        BNA_Simple_Logger::log("SEARCHING FOR ORDER", array('referenceUUID' => $reference_uuid));

        // Search fields to try in order
        $search_fields = array(
            '_transaction_id',
            '_bna_transaction_id',
            '_bna_checkout_token',
            'referenceUUID'
        );

        foreach ($search_fields as $field) {
            BNA_Simple_Logger::log("SEARCHING BY FIELD", array(
                'field' => $field,
                'value' => $reference_uuid
            ));

            $orders = wc_get_orders(array(
                'meta_key' => $field,
                'meta_value' => $reference_uuid,
                'limit' => 1,
                'status' => 'any'
            ));

            if (!empty($orders)) {
                BNA_Simple_Logger::log("ORDER FOUND BY FIELD", array(
                    'field' => $field,
                    'order_id' => $orders[0]->get_id(),
                    'order_status' => $orders[0]->get_status()
                ));
                return $orders[0];
            }
        }

        // If not found, try searching by order meta directly
        $orders_with_token = wc_get_orders(array(
            'meta_key' => '_bna_checkout_token',
            'meta_compare' => 'EXISTS',
            'limit' => 20,
            'status' => 'any'
        ));

        foreach ($orders_with_token as $order) {
            $token = $order->get_meta('_bna_checkout_token');
            BNA_Simple_Logger::log("CHECKING ORDER TOKEN", array(
                'order_id' => $order->get_id(),
                'token' => $token,
                'looking_for' => $reference_uuid
            ));

            if ($token === $reference_uuid) {
                BNA_Simple_Logger::log("ORDER FOUND BY TOKEN MATCH", array(
                    'order_id' => $order->get_id()
                ));
                return $order;
            }
        }

        BNA_Simple_Logger::log("ORDER NOT FOUND ANYWHERE", array(
            'referenceUUID' => $reference_uuid,
            'total_orders_with_bna_token' => count($orders_with_token)
        ));

        return null;
    }

    /**
     * Handle transaction created event
     */
    private function handle_transaction_created($order, $transaction_data) {
        BNA_Simple_Logger::log("PROCESSING TRANSACTION CREATED WEBHOOK", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status(),
            'transaction_id' => $transaction_data['id'],
            'transaction_status' => $transaction_data['status']
        ));

        // Store transaction ID for future reference
        $order->update_meta_data('_bna_transaction_id', $transaction_data['id']);
        $order->add_order_note('BNA transaction created. Transaction ID: ' . $transaction_data['id'] . '. Status: ' . $transaction_data['status']);
        $order->save();
    }

    /**
     * Handle transaction processing status
     */
    private function handle_transaction_processing($order, $transaction_data) {
        BNA_Simple_Logger::log("PROCESSING TRANSACTION PROCESSING WEBHOOK", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status(),
            'transaction_id' => $transaction_data['id']
        ));

        // Update transaction ID and add note
        $order->update_meta_data('_bna_transaction_id', $transaction_data['id']);
        $order->add_order_note('BNA transaction processing. Transaction ID: ' . $transaction_data['id']);
        $order->save();
    }

    /**
     * Handle successful payment webhook
     */
    private function handle_webhook_success($order, $transaction_data) {
        BNA_Simple_Logger::log("PROCESSING SUCCESSFUL PAYMENT WEBHOOK", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status(),
            'transaction_id' => $transaction_data['id']
        ));

        if ($order->has_status(array('completed', 'processing'))) {
            BNA_Simple_Logger::log("ORDER ALREADY COMPLETED", array(
                'order_id' => $order->get_id(),
                'status' => $order->get_status()
            ));
            return;
        }

        try {
            $order->payment_complete($transaction_data['id']);
            $order->add_order_note('Payment confirmed via BNA webhook. Transaction ID: ' . $transaction_data['id']);

            BNA_Simple_Logger::log("ORDER STATUS UPDATED VIA WEBHOOK", array(
                'order_id' => $order->get_id(),
                'new_status' => $order->get_status(),
                'transaction_id' => $transaction_data['id']
            ));

        } catch (Exception $e) {
            BNA_Simple_Logger::log("ERROR UPDATING ORDER STATUS", array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Handle failed payment webhook
     */
    private function handle_webhook_failure($order, $transaction_data) {
        BNA_Simple_Logger::log("PROCESSING FAILED PAYMENT WEBHOOK", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status()
        ));

        $message = isset($transaction_data['message']) ? $transaction_data['message'] : 'Payment failed';
        $order->update_status('failed', 'Payment failed via webhook: ' . $message . '. Transaction ID: ' . $transaction_data['id']);

        BNA_Simple_Logger::log("ORDER MARKED AS FAILED", array(
            'order_id' => $order->get_id(),
            'message' => $message
        ));
    }

    /**
     * Handle cancelled payment webhook
     */
    private function handle_webhook_cancellation($order, $transaction_data) {
        BNA_Simple_Logger::log("PROCESSING CANCELLED PAYMENT WEBHOOK", array(
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status()
        ));

        $message = isset($transaction_data['message']) ? $transaction_data['message'] : 'Payment cancelled';
        $order->update_status('cancelled', 'Payment cancelled via webhook: ' . $message . '. Transaction ID: ' . $transaction_data['id']);

        BNA_Simple_Logger::log("ORDER MARKED AS CANCELLED", array(
            'order_id' => $order->get_id(),
            'message' => $message
        ));
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
        BNA_Simple_Logger::log("WEBHOOK RESPONSE", array(
            'code' => $code,
            'message' => $message
        ));

        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array('message' => $message));
        exit;
    }
}