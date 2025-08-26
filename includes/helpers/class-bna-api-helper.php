<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Helper Class
 */
class BNA_Api_Helper {

    private $config;
    private $config_manager;
    private $api_url;

    public function __construct($config) {
        $this->config = $config;
        $this->config_manager = new BNA_Config_Manager();
        $this->api_url = $this->determine_api_url();
    }

    /**
     * Determine API URL based on configuration
     */
    private function determine_api_url() {
        $mode = $this->config['mode'] ?? 'development';

        if (!$this->config_manager->is_valid_mode($mode)) {
            $mode = 'development';
        }

        return $this->config_manager->get_api_url($mode);
    }

    /**
     * Get checkout token from BNA API
     */
    public function get_checkout_token($order) {
        $email = $order->get_billing_email();

        // Search for existing customer
        $customer_id = $this->find_customer_by_email($email);

        if (!$customer_id) {
            $customer_id = $this->get_stored_customer_id($email);
        }

        // Build payload based on customer data
        if ($customer_id) {
            $payload = $this->build_payload_with_customer_id($order, $customer_id);
        } else {
            $payload = $this->build_payload_with_customer_info($order);
        }

        // Make the API request
        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response) {
            return false;
        }

        // Handle customer exists error
        if (($response['code'] === 400 || $response['code'] === 409) && !$customer_id) {
            if ($this->is_customer_exists_error($response['body'])) {
                $found_customer_id = $this->find_customer_by_email($email);
                if ($found_customer_id) {
                    $this->store_customer_id($email, $found_customer_id);
                    $payload = $this->build_payload_with_customer_id($order, $found_customer_id);
                    $response = $this->make_api_request('/v1/checkout', $payload);
                } else {
                    return $this->retry_with_minimal_data($order);
                }
            }
        }

        // Handle response
        if ($response['code'] !== 200) {
            return false;
        }

        $data = json_decode($response['body'], true);

        if (!isset($data['token'])) {
            return false;
        }

        // Store customer ID if created
        if (!$customer_id && isset($data['customerId'])) {
            $this->store_customer_id($email, $data['customerId']);
        }

        return $data['token'];
    }

    /**
     * Find customer by email using BNA API
     */
    private function find_customer_by_email($email) {
        $response = wp_remote_get($this->api_url . '/v1/customers?email=' . urlencode($email), array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return false;
        }

        $data = json_decode($response_body, true);

        if (!$data || !isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
            return false;
        }

        if (isset($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }

        return false;
    }

    /**
     * Make API request to BNA
     */
    private function make_api_request($endpoint, $payload) {
        $full_url = $this->api_url . $endpoint;

        $response = wp_remote_post($full_url, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->get_user_agent()
            ),
            'body' => json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        return array(
            'code' => $response_code,
            'body' => $response_body
        );
    }

    /**
     * Build payload with customer ID
     */
    private function build_payload_with_customer_id($order, $customer_id) {
        return array(
            'iframeId' => $this->config['iframe_id'],
            'customerId' => $customer_id,
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );
    }

    /**
     * Build payload with customer info
     */
    private function build_payload_with_customer_info($order) {
        $customer_info = $this->build_customer_info($order);

        return array(
            'iframeId' => $this->config['iframe_id'],
            'customerInfo' => $customer_info,
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );
    }

    /**
     * Get user agent for API requests
     */
    private function get_user_agent() {
        $mode = $this->config['mode'] ?? 'development';
        return 'BNA-WooCommerce-Plugin/1.0.5 (' . $mode . ')';
    }

    /**
     * Build customer info array
     */
    private function build_customer_info($order) {
        return array(
            'email' => $order->get_billing_email(),
            'type' => 'Personal',
            'phoneCode' => '+1',
            'phoneNumber' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()) ?: '1234567890',
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Name',
            'birthDate' => '1990-01-01',
            'address' => array(
                'streetName' => $order->get_billing_address_1() ?: 'Main Street',
                'streetNumber' => $order->get_billing_address_2() ?: '1',
                'apartment' => '',
                'city' => $order->get_billing_city() ?: 'City',
                'province' => $order->get_billing_state() ?: 'State',
                'country' => $order->get_billing_country() ?: 'US',
                'postalCode' => $order->get_billing_postcode() ?: '12345'
            )
        );
    }

    /**
     * Build order items array
     */
    private function build_order_items($order) {
        return array(
            array(
                'amount' => floatval($order->get_total()),
                'description' => 'Order #' . $order->get_order_number(),
                'price' => floatval($order->get_total()),
                'quantity' => 1,
                'sku' => 'ORDER-' . $order->get_id()
            )
        );
    }

    /**
     * Retry with minimal data
     */
    private function retry_with_minimal_data($order) {
        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );

        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response || $response['code'] !== 200) {
            return false;
        }

        $data = json_decode($response['body'], true);
        return isset($data['token']) ? $data['token'] : false;
    }

    /**
     * Check if error indicates customer already exists
     */
    private function is_customer_exists_error($response_body) {
        $data = json_decode($response_body, true);
        if (!$data || !isset($data['message'])) {
            return false;
        }

        $message = strtolower($data['message']);
        return strpos($message, 'already exists') !== false ||
               strpos($message, 'duplicate') !== false ||
               strpos($message, 'user already exists') !== false ||
               strpos($message, 'customer already exists') !== false;
    }

    /**
     * Get stored customer ID
     */
    private function get_stored_customer_id($email) {
        return get_option('bna_customer_' . md5($email), false);
    }

    /**
     * Store customer ID locally
     */
    private function store_customer_id($email, $customer_id) {
        update_option('bna_customer_' . md5($email), $customer_id, false);
    }

    public function get_api_url() { 
        return $this->api_url; 
    }

    public function get_mode() { 
        return $this->config['mode'] ?? 'development'; 
    }

    public function is_production() { 
        return $this->get_mode() === 'production'; 
    }

    public function is_development() { 
        return $this->get_mode() === 'development'; 
    }

    public function get_environment_info() {
        $mode = $this->get_mode();
        return array(
            'mode' => $mode,
            'mode_label' => $this->config_manager->get_mode_label($mode),
            'api_url' => $this->api_url,
            'is_production' => $this->is_production()
        );
    }
}
