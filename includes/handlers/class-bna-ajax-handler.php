<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA AJAX Handler Class
 */
class BNA_Ajax_Handler {

    public function __construct() {
        add_action('wp_ajax_bna_complete_payment', array($this, 'complete_payment'));
        add_action('wp_ajax_nopriv_bna_complete_payment', array($this, 'complete_payment'));
    }

    /**
     * Complete payment AJAX handler
     */
    public function complete_payment() {
        error_log('BNA: complete_payment called');
        error_log('BNA: POST data: ' . print_r($_POST, true));

        if (!wp_verify_nonce($_POST['_ajax_nonce'] ?? '', 'bna_ajax_nonce')) {
            error_log('BNA: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');

        error_log('BNA: Order ID: ' . $order_id . ', Transaction: ' . $transaction_id);

        if (!$order_id) {
            error_log('BNA: Invalid order ID');
            wp_send_json_error('Invalid order');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('BNA: Order not found');
            wp_send_json_error('Order not found');
            return;
        }

        if ($order->has_status(array('completed', 'processing'))) {
            error_log('BNA: Order already completed');
            wp_send_json_success('Order already completed');
            return;
        }

        try {
            if ($transaction_id) {
                $order->set_transaction_id($transaction_id);
            }

            $order->update_status('processing', 'Payment completed via BNA. Transaction: ' . $transaction_id);
            $order->save();

            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            error_log('BNA: Order completed successfully');
            wp_send_json_success('Order completed');

        } catch (Exception $e) {
            error_log('BNA: Error: ' . $e->getMessage());
            wp_send_json_error('Update failed: ' . $e->getMessage());
        }
    }
}