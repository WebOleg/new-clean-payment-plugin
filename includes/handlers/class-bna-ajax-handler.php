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
        if (!wp_verify_nonce($_POST['_ajax_nonce'] ?? '', 'bna_ajax_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $transaction_id = sanitize_text_field($_POST['transaction_id'] ?? '');

        if (!$order_id) {
            wp_send_json_error('Invalid order');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
            return;
        }

        if ($order->has_status(array('completed', 'processing'))) {
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

            wp_send_json_success('Order completed');

        } catch (Exception $e) {
            wp_send_json_error('Update failed: ' . $e->getMessage());
        }
    }
}
