<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA AJAX Handler Class - Updated to save transaction IDs
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
                // Set the main transaction ID
                $order->set_transaction_id($transaction_id);

                // CRITICAL: Save transaction ID for webhook matching
                $order->update_meta_data('_bna_transaction_id', $transaction_id);

                // Also save for referenceUUID matching (BNA might use transaction_id as referenceUUID)
                $order->update_meta_data('_bna_reference_uuid', $transaction_id);

                BNA_Simple_Logger::log("TRANSACTION ID SAVED VIA AJAX", array(
                    'order_id' => $order_id,
                    'transaction_id' => $transaction_id,
                    'saved_to_meta' => array(
                        '_transaction_id' => $transaction_id,
                        '_bna_transaction_id' => $transaction_id,
                        '_bna_reference_uuid' => $transaction_id
                    )
                ));
            }

            $order->update_status('processing', 'Payment completed via BNA. Transaction: ' . $transaction_id);
            $order->save();

            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            BNA_Simple_Logger::log("ORDER COMPLETED VIA AJAX", array(
                'order_id' => $order_id,
                'transaction_id' => $transaction_id,
                'new_status' => $order->get_status()
            ));

            wp_send_json_success('Order completed');

        } catch (Exception $e) {
            BNA_Simple_Logger::log("AJAX ORDER UPDATE FAILED", array(
                'order_id' => $order_id,
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage()
            ));

            wp_send_json_error('Update failed: ' . $e->getMessage());
        }
    }
}