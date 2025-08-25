<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA AJAX Handler - NO WC redirect at all
 */
class BNA_Ajax_Handler {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_bna_update_order_status', array($this, 'update_order_status'));
        add_action('wp_ajax_nopriv_bna_update_order_status', array($this, 'update_order_status'));
        add_action('wp_ajax_bna_manual_redirect', array($this, 'manual_redirect'));
        add_action('wp_ajax_nopriv_bna_manual_redirect', array($this, 'manual_redirect'));
    }

    public function update_order_status() {
        $request_data = $this->validate_request();
        
        if (!$request_data) {
            wp_send_json_error('Invalid request data');
            return;
        }

        $order = wc_get_order($request_data['order_id']);
        if (!$order) {
            wp_send_json_error('Order not found');
            return;
        }

        if ($request_data['status'] === 'success') {
            $this->handle_successful_payment($order, $request_data);
        }

        // НЕ робимо НІЯКОГО redirect тут - тільки оновлюємо статус
        wp_send_json_success(array(
            'message' => 'Order updated',
            'redirect_url' => $order->get_checkout_order_received_url()
        ));
    }

    public function manual_redirect() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
            return;
        }

        wp_send_json_success(array(
            'redirect_url' => $order->get_checkout_order_received_url()
        ));
    }

    private function handle_successful_payment($order, $data) {
        if ($order->has_status(array('completed', 'processing'))) {
            return;
        }

        if ($data['transaction_id']) {
            $order->set_transaction_id($data['transaction_id']);
            $order->update_meta_data('_bna_transaction_id', $data['transaction_id']);
        }

        $note = 'BNA Payment completed successfully.';
        if ($data['message']) {
            $note .= ' Message: ' . $data['message'];
        }
        if ($data['transaction_id']) {
            $note .= ' Transaction ID: ' . $data['transaction_id'];
        }

        // Просто змінюємо статус - НЕ викликаємо payment_complete()
        $order->update_status('processing', $note);
        $order->update_meta_data('_bna_payment_status', 'completed');
        $order->update_meta_data('_bna_payment_date', current_time('mysql'));
        $order->save();

        // Очищаємо корзину
        WC()->cart->empty_cart();
        
        error_log('BNA: Order updated WITHOUT payment_complete()');
    }

    private function validate_request() {
        $required_fields = array('order_id', 'status');
        $data = array();

        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                return false;
            }
            $data[$field] = sanitize_text_field($_POST[$field]);
        }

        $optional_fields = array('transaction_id', 'message');
        foreach ($optional_fields as $field) {
            $data[$field] = isset($_POST[$field]) ? sanitize_text_field($_POST[$field]) : '';
        }

        $data['order_id'] = intval($data['order_id']);
        return $data;
    }
}
