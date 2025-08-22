<?php
/**
 * BNA iFrame AJAX Handler
 * Handles AJAX requests for iFrame token generation and management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Iframe_Ajax
 * Manages all AJAX operations related to iFrame checkout functionality
 */
class BNA_Iframe_Ajax {

    private static $instance = null;
    private $api;
    private $validator;
    private $order_handler;

    /**
     * Get singleton instance
     * @return BNA_Iframe_Ajax
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
        $this->api = BNA_Api::get_instance();
        $this->validator = BNA_Validator::get_instance();
        $this->order_handler = BNA_Order::get_instance();
    }

    /**
     * Initialize AJAX hooks
     */
    public function init() {
        // Logged in users
        add_action('wp_ajax_bna_create_iframe_token', array($this, 'create_iframe_token'));
        add_action('wp_ajax_bna_get_iframe_status', array($this, 'get_iframe_status'));
        add_action('wp_ajax_bna_refresh_iframe_token', array($this, 'refresh_iframe_token'));
        
        // Non-logged in users (for guest checkout)
        add_action('wp_ajax_nopriv_bna_create_iframe_token', array($this, 'create_iframe_token'));
        add_action('wp_ajax_nopriv_bna_get_iframe_status', array($this, 'get_iframe_status'));
        add_action('wp_ajax_nopriv_bna_refresh_iframe_token', array($this, 'refresh_iframe_token'));
    }

    /**
     * Create iFrame token for checkout
     */
    public function create_iframe_token() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            // Get and sanitize input data
            $input_data = $this->get_sanitized_input();
            
            // Validate input data
            $validation_result = $this->validate_iframe_data($input_data);
            if (is_wp_error($validation_result)) {
                throw new Exception($validation_result->get_error_message());
            }

            // Prepare customer data
            $customer_data = $this->prepare_customer_data($input_data);
            
            // Prepare order data
            $order_data = $this->prepare_order_data($input_data);

            // Create iFrame token via API
            $token_response = $this->api->create_iframe_token($customer_data, $order_data);
            
            if (is_wp_error($token_response)) {
                throw new Exception('Failed to create iFrame token: ' . $token_response->get_error_message());
            }

            // Validate API response
            if (empty($token_response['token'])) {
                throw new Exception('Invalid token response from API');
            }

            // Store token in session/cache for later use
            $this->store_iframe_token($token_response['token'], $input_data);

            BNA_Logger::info('iFrame token created successfully', array(
                'token_preview' => substr($token_response['token'], 0, 10) . '...',
                'payment_method' => $input_data['payment_method'],
                'customer_type' => $input_data['customer_type']
            ), 'iframe_ajax');

            // Return success response
            wp_send_json_success(array(
                'token' => $token_response['token'],
                'iframe_url' => $token_response['iframe_url'] ?? '',
                'expires_at' => $token_response['expires_at'] ?? '',
                'session_id' => $token_response['session_id'] ?? '',
                'message' => 'iFrame token created successfully'
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to create iFrame token', array(
                'error' => $e->getMessage(),
                'input_data' => $input_data ?? array()
            ), 'iframe_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'token_creation_failed'
            ));
        }
    }

    /**
     * Get iFrame status and transaction details
     */
    public function get_iframe_status() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $transaction_token = sanitize_text_field($_POST['transaction_token'] ?? '');
            
            if (empty($transaction_token)) {
                throw new Exception('Transaction token is required');
            }

            // Get transaction status from API
            $status_response = $this->api->get_transaction_status($transaction_token);
            
            if (is_wp_error($status_response)) {
                throw new Exception('Failed to get transaction status: ' . $status_response->get_error_message());
            }

            // Check if order exists and update if needed
            $order = $this->order_handler->get_order_by_transaction($transaction_token);
            
            if ($order && isset($status_response['status'])) {
                $this->order_handler->update_order_status(
                    $order->get_id(), 
                    $status_response['status'], 
                    $status_response
                );
            }

            BNA_Logger::info('iFrame status retrieved', array(
                'transaction_token' => substr($transaction_token, 0, 10) . '...',
                'status' => $status_response['status'] ?? 'unknown'
            ), 'iframe_ajax');

            wp_send_json_success(array(
                'status' => $status_response['status'] ?? 'pending',
                'reference_number' => $status_response['reference_number'] ?? '',
                'payment_method' => $status_response['payment_method'] ?? '',
                'amount' => $status_response['amount'] ?? '',
                'currency' => $status_response['currency'] ?? '',
                'message' => $status_response['message'] ?? '',
                'order_id' => $order ? $order->get_id() : null
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to get iFrame status', array(
                'error' => $e->getMessage(),
                'transaction_token' => substr($transaction_token ?? '', 0, 10) . '...'
            ), 'iframe_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'status_retrieval_failed'
            ));
        }
    }

    /**
     * Refresh expired iFrame token
     */
    public function refresh_iframe_token() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $old_token = sanitize_text_field($_POST['old_token'] ?? '');
            
            if (empty($old_token)) {
                throw new Exception('Old token is required for refresh');
            }

            // Get stored token data
            $stored_data = $this->get_stored_iframe_data($old_token);
            
            if (!$stored_data) {
                throw new Exception('Token data not found, please restart checkout');
            }

            // Create new token with same data
            $customer_data = $stored_data['customer_data'];
            $order_data = $stored_data['order_data'];

            $token_response = $this->api->create_iframe_token($customer_data, $order_data);
            
            if (is_wp_error($token_response)) {
                throw new Exception('Failed to refresh iFrame token: ' . $token_response->get_error_message());
            }

            // Store new token
            $this->store_iframe_token($token_response['token'], $stored_data['input_data']);
            
            // Remove old token
            $this->remove_stored_iframe_data($old_token);

            BNA_Logger::info('iFrame token refreshed successfully', array(
                'old_token_preview' => substr($old_token, 0, 10) . '...',
                'new_token_preview' => substr($token_response['token'], 0, 10) . '...'
            ), 'iframe_ajax');

            wp_send_json_success(array(
                'token' => $token_response['token'],
                'iframe_url' => $token_response['iframe_url'] ?? '',
                'expires_at' => $token_response['expires_at'] ?? '',
                'message' => 'iFrame token refreshed successfully'
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to refresh iFrame token', array(
                'error' => $e->getMessage(),
                'old_token' => substr($old_token ?? '', 0, 10) . '...'
            ), 'iframe_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'token_refresh_failed'
            ));
        }
    }

    /**
     * Get and sanitize input data from POST
     * @return array
     */
    private function get_sanitized_input() {
        return array(
            'payment_method' => sanitize_text_field($_POST['payment_method'] ?? 'card'),
            'customer_type' => sanitize_text_field($_POST['customer_type'] ?? 'Personal'),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'phone_code' => sanitize_text_field($_POST['phone_code'] ?? '+1'),
            'address_1' => sanitize_text_field($_POST['address_1'] ?? ''),
            'address_2' => sanitize_text_field($_POST['address_2'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'state' => sanitize_text_field($_POST['state'] ?? ''),
            'postcode' => sanitize_text_field($_POST['postcode'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? 'CA'),
            'order_total' => floatval($_POST['order_total'] ?? 0),
            'currency' => sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency()),
            'order_items' => $this->sanitize_order_items($_POST['order_items'] ?? array())
        );
    }

    /**
     * Sanitize order items array
     * @param array $items
     * @return array
     */
    private function sanitize_order_items($items) {
        if (!is_array($items)) {
            return array();
        }

        $sanitized_items = array();
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sanitized_items[] = array(
                'id' => intval($item['id'] ?? 0),
                'name' => sanitize_text_field($item['name'] ?? ''),
                'sku' => sanitize_text_field($item['sku'] ?? ''),
                'price' => floatval($item['price'] ?? 0),
                'quantity' => intval($item['quantity'] ?? 1),
                'total' => floatval($item['total'] ?? 0)
            );
        }

        return $sanitized_items;
    }

    /**
     * Validate iFrame creation data
     * @param array $data
     * @return bool|WP_Error
     */
    private function validate_iframe_data($data) {
        $errors = array();

        // Validate required fields
        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!$this->validator->validate_email($data['email'])) {
            $errors[] = 'Invalid email format';
        }

        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        if (empty($data['phone'])) {
            $errors[] = 'Phone number is required';
        } elseif (!$this->validator->validate_phone($data['phone'])) {
            $errors[] = 'Invalid phone number format';
        }

        // Validate amount
        if ($data['order_total'] <= 0) {
            $errors[] = 'Order total must be greater than zero';
        } elseif (!$this->validator->validate_amount($data['order_total'], $data['currency'])) {
            $errors[] = 'Invalid order amount or currency';
        }

        // Validate payment method
        $allowed_methods = array('card', 'eft', 'e-transfer');
        if (!in_array($data['payment_method'], $allowed_methods)) {
            $errors[] = 'Invalid payment method';
        }

        // Validate customer type
        $allowed_types = array('Personal', 'Business');
        if (!in_array($data['customer_type'], $allowed_types)) {
            $errors[] = 'Invalid customer type';
        }

        // Validate order items
        if (empty($data['order_items'])) {
            $errors[] = 'Order items are required';
        }

        if (!empty($errors)) {
            return new WP_Error('validation_failed', implode(', ', $errors));
        }

        return true;
    }

    /**
     * Prepare customer data for API
     * @param array $input_data
     * @return array
     */
    private function prepare_customer_data($input_data) {
        return array(
            'type' => $input_data['customer_type'],
            'email' => $input_data['email'],
            'first_name' => $input_data['first_name'],
            'last_name' => $input_data['last_name'],
            'phone' => $input_data['phone'],
            'phone_code' => $input_data['phone_code'],
            'street_name' => $input_data['address_1'],
            'street_number' => '', // Could be parsed from address_1
            'city' => $input_data['city'],
            'state' => $input_data['state'],
            'country' => $input_data['country'],
            'postcode' => $input_data['postcode']
        );
    }

    /**
     * Prepare order data for API
     * @param array $input_data
     * @return array
     */
    private function prepare_order_data($input_data) {
        return array(
            'items' => $input_data['order_items'],
            'total' => $input_data['order_total'],
            'currency' => $input_data['currency']
        );
    }

    /**
     * Store iFrame token data in transient for later use
     * @param string $token
     * @param array $input_data
     */
    private function store_iframe_token($token, $input_data) {
        $token_data = array(
            'input_data' => $input_data,
            'customer_data' => $this->prepare_customer_data($input_data),
            'order_data' => $this->prepare_order_data($input_data),
            'created_at' => time()
        );

        // Store for 1 hour
        set_transient('bna_iframe_token_' . $token, $token_data, HOUR_IN_SECONDS);
    }

    /**
     * Get stored iFrame token data
     * @param string $token
     * @return array|false
     */
    private function get_stored_iframe_data($token) {
        return get_transient('bna_iframe_token_' . $token);
    }

    /**
     * Remove stored iFrame token data
     * @param string $token
     */
    private function remove_stored_iframe_data($token) {
        delete_transient('bna_iframe_token_' . $token);
    }

    /**
     * Verify nonce for security
     * @param string $action
     * @return bool
     */
    private function verify_nonce($action) {
        $nonce = $_POST['_wpnonce'] ?? $_POST['nonce'] ?? '';
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Clean up expired token data (called by cron or manually)
     */
    public function cleanup_expired_tokens() {
        global $wpdb;

        // Get all BNA iframe token transients
        $transients = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bna_iframe_token_%'"
        );

        $cleaned_count = 0;

        foreach ($transients as $transient) {
            $token = str_replace('_transient_bna_iframe_token_', '', $transient->option_name);
            $data = get_transient('bna_iframe_token_' . $token);

            // If data doesn't exist or is older than 2 hours, remove it
            if (!$data || (time() - $data['created_at']) > (2 * HOUR_IN_SECONDS)) {
                delete_transient('bna_iframe_token_' . $token);
                $cleaned_count++;
            }
        }

        if ($cleaned_count > 0) {
            BNA_Logger::info('Cleaned up expired iframe tokens', array(
                'cleaned_count' => $cleaned_count
            ), 'iframe_ajax');
        }

        return $cleaned_count;
    }

    /**
     * Get iframe token statistics
     * @return array
     */
    public function get_token_statistics() {
        global $wpdb;

        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bna_iframe_token_%'"
        );

        $stats = array(
            'total_tokens' => 0,
            'active_tokens' => 0,
            'expired_tokens' => 0,
            'payment_methods' => array(),
            'customer_types' => array()
        );

        foreach ($transients as $transient) {
            $data = maybe_unserialize($transient->option_value);
            $stats['total_tokens']++;

            if ($data && isset($data['created_at'])) {
                if ((time() - $data['created_at']) <= HOUR_IN_SECONDS) {
                    $stats['active_tokens']++;
                    
                    // Count payment methods
                    $method = $data['input_data']['payment_method'] ?? 'unknown';
                    $stats['payment_methods'][$method] = ($stats['payment_methods'][$method] ?? 0) + 1;
                    
                    // Count customer types
                    $type = $data['input_data']['customer_type'] ?? 'unknown';
                    $stats['customer_types'][$type] = ($stats['customer_types'][$type] ?? 0) + 1;
                } else {
                    $stats['expired_tokens']++;
                }
            }
        }

        return $stats;
    }
}
