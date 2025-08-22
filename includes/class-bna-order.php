<?php
/**
 * BNA Order Class
 * Handles WooCommerce order processing and BNA transaction management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Order
 * Manages order creation, updates, and BNA transaction data
 */
class BNA_Order {

    private static $instance = null;

    /**
     * Get singleton instance
     * @return BNA_Order
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup hooks
     */
    private function __construct() {
        add_action('woocommerce_checkout_order_processed', array($this, 'process_bna_order'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'handle_status_change'), 10, 4);
    }

    /**
     * Process BNA order after WooCommerce order creation
     * @param int $order_id
     * @param array $posted_data
     * @param WC_Order $order
     */
    public function process_bna_order($order_id, $posted_data, $order) {
        if ($order->get_payment_method() !== 'bna_gateway') {
            return;
        }

        BNA_Logger::info('Processing BNA order', array('order_id' => $order_id), 'order');
        
        $this->save_order_meta($order, $posted_data);
        $this->create_transaction_record($order);
    }

    /**
     * Create new order with BNA payment data
     * @param array $order_data
     * @param array $customer_data
     * @return int|WP_Error Order ID or error
     */
    public function create_order($order_data, $customer_data) {
        try {
            $order = wc_create_order();
            
            if (is_wp_error($order)) {
                return $order;
            }

            // Set customer
            $this->set_order_customer($order, $customer_data);
            
            // Add products
            $this->add_order_items($order, $order_data['items']);
            
            // Set addresses
            $this->set_order_addresses($order, $customer_data);
            
            // Set payment method
            $order->set_payment_method('bna_gateway');
            $order->set_payment_method_title('BNA Smart Payment');
            
            // Set totals
            $order->set_total($order_data['total']);
            
            // Save order
            $order->save();
            
            BNA_Logger::info('Order created successfully', array(
                'order_id' => $order->get_id(),
                'total' => $order_data['total']
            ), 'order');
            
            return $order->get_id();
            
        } catch (Exception $e) {
            BNA_Logger::error('Failed to create order', array(
                'error' => $e->getMessage()
            ), 'order');
            
            return new WP_Error('order_creation_failed', $e->getMessage());
        }
    }

    /**
     * Update order status based on BNA transaction
     * @param int $order_id
     * @param string $transaction_status
     * @param array $transaction_data
     * @return bool
     */
    public function update_order_status($order_id, $transaction_status, $transaction_data = array()) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            BNA_Logger::error('Order not found', array('order_id' => $order_id), 'order');
            return false;
        }

        $wc_status = $this->map_bna_status_to_woocommerce($transaction_status);
        
        if (!$wc_status) {
            BNA_Logger::warning('Unknown transaction status', array(
                'order_id' => $order_id,
                'transaction_status' => $transaction_status
            ), 'order');
            return false;
        }

        $old_status = $order->get_status();
        
        // Update order status
        $order->update_status($wc_status, sprintf(
            'BNA transaction %s. Reference: %s',
            $transaction_status,
            $transaction_data['reference_number'] ?? 'N/A'
        ));

        // Update transaction record
        $this->update_transaction_record($order_id, $transaction_data);

        // Add order notes
        $this->add_transaction_note($order, $transaction_data);

        BNA_Logger::info('Order status updated', array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $wc_status,
            'transaction_status' => $transaction_status
        ), 'order');

        return true;
    }

    /**
     * Save BNA transaction record to database
     * @param int $order_id
     * @param array $transaction_data
     * @return bool
     */
    public function save_transaction($order_id, $transaction_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bna_transactions';

        $data = array(
            'order_id' => $order_id,
            'transaction_token' => $transaction_data['transaction_token'] ?? '',
            'reference_number' => $transaction_data['reference_number'] ?? '',
            'transaction_description' => wp_json_encode($transaction_data),
            'transaction_status' => $transaction_data['status'] ?? 'pending',
            'created_time' => current_time('mysql')
        );

        $result = $wpdb->insert($table_name, $data);

        if ($result === false) {
            BNA_Logger::error('Failed to save transaction', array(
                'order_id' => $order_id,
                'error' => $wpdb->last_error
            ), 'order');
            return false;
        }

        BNA_Logger::info('Transaction saved', array(
            'order_id' => $order_id,
            'transaction_token' => $data['transaction_token']
        ), 'order');

        return true;
    }

    /**
     * Get BNA transaction data for order
     * @param int $order_id
     * @return array|null
     */
    public function get_transaction($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bna_transactions';

        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_time DESC LIMIT 1",
            $order_id
        ), ARRAY_A);

        if ($transaction) {
            $transaction['transaction_description'] = json_decode($transaction['transaction_description'], true);
        }

        return $transaction;
    }

    /**
     * Get order by BNA transaction token
     * @param string $transaction_token
     * @return WC_Order|null
     */
    public function get_order_by_transaction($transaction_token) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bna_transactions';

        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$table_name} WHERE transaction_token = %s LIMIT 1",
            $transaction_token
        ));

        if ($order_id) {
            return wc_get_order($order_id);
        }

        return null;
    }

    /**
     * Handle WooCommerce order status changes
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order $order
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order) {
        if ($order->get_payment_method() !== 'bna_gateway') {
            return;
        }

        BNA_Logger::info('Order status changed', array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status
        ), 'order');

        // Handle specific status changes
        switch ($new_status) {
            case 'cancelled':
                $this->handle_order_cancellation($order);
                break;
            case 'refunded':
                $this->handle_order_refund($order);
                break;
        }
    }

    /**
     * Process order refund
     * @param WC_Order $order
     * @param float $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund($order, $amount, $reason = '') {
        $transaction = $this->get_transaction($order->get_id());
        
        if (!$transaction) {
            return new WP_Error('no_transaction', 'No BNA transaction found for this order');
        }

        // Call BNA API to process refund
        $api = BNA_Api::get_instance();
        $refund_response = $api->process_refund($transaction['transaction_token'], $amount, $reason);

        if (is_wp_error($refund_response)) {
            BNA_Logger::error('Refund failed', array(
                'order_id' => $order->get_id(),
                'error' => $refund_response->get_error_message()
            ), 'order');
            return $refund_response;
        }

        // Update transaction record
        $this->update_transaction_record($order->get_id(), array(
            'refund_amount' => $amount,
            'refund_reason' => $reason,
            'refund_status' => $refund_response['status'] ?? 'processed'
        ));

        BNA_Logger::info('Refund processed', array(
            'order_id' => $order->get_id(),
            'amount' => $amount
        ), 'order');

        return true;
    }

    /**
     * Set order customer data
     * @param WC_Order $order
     * @param array $customer_data
     */
    private function set_order_customer($order, $customer_data) {
        if (!empty($customer_data['user_id'])) {
            $order->set_customer_id($customer_data['user_id']);
        }
        
        $order->set_billing_email($customer_data['email']);
        $order->set_billing_first_name($customer_data['first_name']);
        $order->set_billing_last_name($customer_data['last_name']);
        $order->set_billing_phone($customer_data['phone']);
    }

    /**
     * Add items to order
     * @param WC_Order $order
     * @param array $items
     */
    private function add_order_items($order, $items) {
        foreach ($items as $item_data) {
            $product = wc_get_product($item_data['product_id']);
            
            if (!$product) {
                continue;
            }

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity($item_data['quantity']);
            $item->set_subtotal($item_data['subtotal']);
            $item->set_total($item_data['total']);
            
            $order->add_item($item);
        }
    }

    /**
     * Set order addresses
     * @param WC_Order $order
     * @param array $customer_data
     */
    private function set_order_addresses($order, $customer_data) {
        // Billing address
        $billing_address = array(
            'first_name' => $customer_data['first_name'],
            'last_name' => $customer_data['last_name'],
            'company' => $customer_data['company'] ?? '',
            'email' => $customer_data['email'],
            'phone' => $customer_data['phone'],
            'address_1' => $customer_data['address_1'],
            'address_2' => $customer_data['address_2'] ?? '',
            'city' => $customer_data['city'],
            'state' => $customer_data['state'],
            'postcode' => $customer_data['postcode'],
            'country' => $customer_data['country']
        );
        
        $order->set_address($billing_address, 'billing');
        
        // Shipping address (same as billing if not provided)
        $shipping_address = $customer_data['shipping'] ?? $billing_address;
        $order->set_address($shipping_address, 'shipping');
    }

    /**
     * Save order metadata
     * @param WC_Order $order
     * @param array $posted_data
     */
    private function save_order_meta($order, $posted_data) {
        // Save BNA specific metadata
        $order->update_meta_data('_bna_payment_method', $posted_data['bna_payment_method'] ?? '');
        $order->update_meta_data('_bna_customer_type', $posted_data['bna_customer_type'] ?? 'Personal');
        $order->update_meta_data('_bna_phone_code', $posted_data['bna_phone_code'] ?? '+1');
        
        $order->save_meta_data();
    }

    /**
     * Create initial transaction record
     * @param WC_Order $order
     */
    private function create_transaction_record($order) {
        $transaction_data = array(
            'order_id' => $order->get_id(),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => 'pending',
            'payment_method' => $order->get_meta('_bna_payment_method'),
            'customer_type' => $order->get_meta('_bna_customer_type')
        );

        $this->save_transaction($order->get_id(), $transaction_data);
    }

    /**
     * Update existing transaction record
     * @param int $order_id
     * @param array $transaction_data
     */
    private function update_transaction_record($order_id, $transaction_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bna_transactions';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_time DESC LIMIT 1",
            $order_id
        ), ARRAY_A);

        if ($existing) {
            $description = json_decode($existing['transaction_description'], true);
            $updated_description = array_merge($description, $transaction_data);

            $wpdb->update(
                $table_name,
                array(
                    'transaction_status' => $transaction_data['status'] ?? $existing['transaction_status'],
                    'reference_number' => $transaction_data['reference_number'] ?? $existing['reference_number'],
                    'transaction_description' => wp_json_encode($updated_description)
                ),
                array('id' => $existing['id'])
            );
        }
    }

    /**
     * Add transaction note to order
     * @param WC_Order $order
     * @param array $transaction_data
     */
    private function add_transaction_note($order, $transaction_data) {
        $note = sprintf(
            'BNA Transaction Update: Status: %s, Reference: %s',
            $transaction_data['status'] ?? 'Unknown',
            $transaction_data['reference_number'] ?? 'N/A'
        );

        $order->add_order_note($note, false, true);
    }

    /**
     * Map BNA transaction status to WooCommerce status
     * @param string $bna_status
     * @return string|null
     */
    private function map_bna_status_to_woocommerce($bna_status) {
        $status_map = array(
            'completed' => 'processing',
            'approved' => 'processing',
            'success' => 'processing',
            'declined' => 'failed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'pending' => 'pending'
        );

        return $status_map[strtolower($bna_status)] ?? null;
    }

    /**
     * Handle order cancellation
     * @param WC_Order $order
     */
    private function handle_order_cancellation($order) {
        $transaction = $this->get_transaction($order->get_id());
        
        if ($transaction && in_array($transaction['transaction_status'], array('pending', 'processing'))) {
            // Cancel transaction via BNA API if needed
            BNA_Logger::info('Order cancelled, transaction status updated', array(
                'order_id' => $order->get_id()
            ), 'order');
        }
    }

    /**
     * Handle order refund
     * @param WC_Order $order
     */
    private function handle_order_refund($order) {
        // This is called when order status changes to refunded
        // The actual refund processing should be done via process_refund method
        BNA_Logger::info('Order marked as refunded', array(
            'order_id' => $order->get_id()
        ), 'order');
    }

    /**
     * Get orders by status
     * @param string $status
     * @param int $limit
     * @return array
     */
    public function get_orders_by_status($status, $limit = 10) {
        $args = array(
            'limit' => $limit,
            'payment_method' => 'bna_gateway',
            'status' => $status,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        return wc_get_orders($args);
    }

    /**
     * Get order statistics
     * @param string $period
     * @return array
     */
    public function get_order_statistics($period = '30days') {
        global $wpdb;

        $date_query = '';
        switch ($period) {
            case '7days':
                $date_query = "AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30days':
                $date_query = "AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case '90days':
                $date_query = "AND post_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                break;
        }

        $sql = "
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN pm.meta_value = 'bna_gateway' THEN 1 ELSE 0 END) as bna_orders,
                SUM(CASE WHEN p.post_status = 'wc-processing' AND pm.meta_value = 'bna_gateway' THEN 1 ELSE 0 END) as successful_orders
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
            WHERE p.post_type = 'shop_order' 
            {$date_query}
        ";

        return $wpdb->get_row($sql, ARRAY_A);
    }
}
