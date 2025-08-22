<?php
/**
 * BNA API Class
 * Handles all communication with BNA Smart Payment API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Api
 * Manages API requests to BNA Smart Payment system
 */
class BNA_Api {

    private static $instance = null;
    private $access_key;
    private $secret_key;
    private $environment;
    private $base_url;
    private $timeout = 30;

    /**
     * Get singleton instance
     * @return BNA_Api
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup API configuration
     */
    private function __construct() {
        $settings = get_option('woocommerce_bna_gateway_settings', array());
        
        $this->access_key = $settings['access_key'] ?? '';
        $this->secret_key = $settings['secret_key'] ?? '';
        $this->environment = $settings['api_environment'] ?? 'staging';
        
        $this->set_base_url();
    }

    /**
     * Set base URL based on environment
     */
    private function set_base_url() {
        switch ($this->environment) {
            case 'production':
                $this->base_url = 'https://api.bnasmartpayment.com/v1';
                break;
            case 'staging':
            default:
                $this->base_url = 'https://stage-api-service.bnasmartpayment.com/v1';
                break;
        }
    }

    /**
     * Create iframe token for checkout
     * @param array $customer_data
     * @param array $order_data
     * @return array|WP_Error
     */
    public function create_iframe_token($customer_data, $order_data) {
        $endpoint = '/checkout';
        
        $payload = array(
            'iframeId' => $this->get_iframe_id(),
            'customerInfo' => $this->format_customer_data($customer_data),
            'items' => $this->format_order_items($order_data['items']),
            'subtotal' => round($order_data['total'], 2),
            'currency' => $order_data['currency'] ?? 'CAD'
        );

        $response = $this->make_request('POST', $endpoint, $payload);

        if (is_wp_error($response)) {
            BNA_Logger::error('Failed to create iframe token', array(
                'error' => $response->get_error_message(),
                'payload' => $payload
            ), 'api');
            return $response;
        }

        if (empty($response['token'])) {
            BNA_Logger::error('No token returned from API', array('response' => $response), 'api');
            return new WP_Error('no_token', 'iFrame token not returned by API');
        }

        BNA_Logger::info('iFrame token created successfully', array(
            'token' => substr($response['token'], 0, 10) . '...'
        ), 'api');

        return $response;
    }

    /**
     * Process direct payment (without iframe)
     * @param array $payment_data
     * @return array|WP_Error
     */
    public function process_payment($payment_data) {
        $endpoint = '/transaction/' . $payment_data['payment_method'] . '/sale';
        
        $payload = array(
            'customerId' => $payment_data['customer_id'] ?? null,
            'paymentDetails' => $payment_data['payment_details'],
            'items' => $payment_data['items'],
            'subtotal' => round($payment_data['amount'], 2),
            'currency' => $payment_data['currency'] ?? 'CAD',
            'metadata' => array(
                'order_id' => $payment_data['order_id'],
                'source' => 'wordpress'
            )
        );

        $response = $this->make_request('POST', $endpoint, $payload);

        if (is_wp_error($response)) {
            BNA_Logger::error('Payment processing failed', array(
                'error' => $response->get_error_message(),
                'order_id' => $payment_data['order_id']
            ), 'api');
            return $response;
        }

        BNA_Logger::log_payment(
            $response['id'] ?? 'unknown',
            $response['status'] ?? 'unknown',
            array('order_id' => $payment_data['order_id'])
        );

        return $response;
    }

    /**
     * Get transaction status
     * @param string $transaction_id
     * @return array|WP_Error
     */
    public function get_transaction_status($transaction_id) {
        $endpoint = '/transactions/' . $transaction_id . '/status';
        
        $response = $this->make_request('GET', $endpoint);

        if (is_wp_error($response)) {
            BNA_Logger::error('Failed to get transaction status', array(
                'error' => $response->get_error_message(),
                'transaction_id' => $transaction_id
            ), 'api');
        }

        return $response;
    }

    /**
     * Get transaction details
     * @param string $transaction_id
     * @return array|WP_Error
     */
    public function get_transaction($transaction_id) {
        $endpoint = '/transactions/' . $transaction_id;
        
        $response = $this->make_request('GET', $endpoint);

        if (is_wp_error($response)) {
            BNA_Logger::error('Failed to get transaction details', array(
                'error' => $response->get_error_message(),
                'transaction_id' => $transaction_id
            ), 'api');
        }

        return $response;
    }

    /**
     * Create customer
     * @param array $customer_data
     * @return array|WP_Error
     */
    public function create_customer($customer_data) {
        $endpoint = '/customers';
        
        $payload = $this->format_customer_data($customer_data);

        $response = $this->make_request('POST', $endpoint, $payload);

        if (is_wp_error($response)) {
            BNA_Logger::error('Failed to create customer', array(
                'error' => $response->get_error_message(),
                'email' => $customer_data['email'] ?? 'unknown'
            ), 'api');
        }

        return $response;
    }

    /**
     * Test API connection
     * @return bool
     */
    public function test_connection() {
        $endpoint = '/ping';
        
        $response = $this->make_request('GET', $endpoint);

        if (is_wp_error($response)) {
            BNA_Logger::error('API connection test failed', array(
                'error' => $response->get_error_message()
            ), 'api');
            return false;
        }

        BNA_Logger::info('API connection test successful', array(), 'api');
        return true;
    }

    /**
     * Make HTTP request to API
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array|WP_Error
     */
    private function make_request($method, $endpoint, $data = array()) {
        $url = $this->base_url . $endpoint;
        
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->access_key . ':' . $this->secret_key),
            'Content-Type' => 'application/json',
            'User-Agent' => 'BNA-WordPress-Plugin/' . BNA_PLUGIN_VERSION
        );

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->timeout,
            'sslverify' => true
        );

        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($data);
        }

        // Log API request
        BNA_Logger::log_api_request($url, $data, array(), 0);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log API response
        BNA_Logger::log_api_request($url, $data, $response_body, $response_code);

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = $this->parse_error_response($response_body, $response_code);
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON response from API');
        }

        return $decoded_response;
    }

    /**
     * Format customer data for API
     * @param array $customer_data
     * @return array
     */
    private function format_customer_data($customer_data) {
        return array(
            'type' => $customer_data['type'] ?? 'Personal',
            'email' => $customer_data['email'],
            'firstName' => $customer_data['first_name'],
            'lastName' => $customer_data['last_name'],
            'phoneNumber' => $customer_data['phone'],
            'phoneCode' => $customer_data['phone_code'] ?? '+1',
            'birthDate' => $customer_data['birth_date'] ?? null,
            'address' => array(
                'streetName' => $customer_data['street_name'] ?? '',
                'streetNumber' => $customer_data['street_number'] ?? '',
                'city' => $customer_data['city'],
                'province' => $customer_data['state'],
                'country' => $customer_data['country'],
                'postalCode' => $customer_data['postcode']
            )
        );
    }

    /**
     * Format order items for API
     * @param array $items
     * @return array
     */
    private function format_order_items($items) {
        $formatted_items = array();

        foreach ($items as $item) {
            $formatted_items[] = array(
                'description' => $item['name'],
                'sku' => $item['sku'] ?? $item['id'],
                'price' => round($item['price'], 2),
                'quantity' => $item['quantity'],
                'amount' => round($item['total'], 2)
            );
        }

        return $formatted_items;
    }

    /**
     * Parse error response from API
     * @param string $response_body
     * @param int $response_code
     * @return string
     */
    private function parse_error_response($response_body, $response_code) {
        $decoded = json_decode($response_body, true);
        
        if (isset($decoded['message'])) {
            return $decoded['message'];
        }
        
        if (isset($decoded['error'])) {
            return $decoded['error'];
        }

        // Fallback error messages
        switch ($response_code) {
            case 400:
                return 'Bad request - invalid data sent to API';
            case 401:
                return 'Unauthorized - invalid API credentials';
            case 403:
                return 'Forbidden - access denied';
            case 404:
                return 'Not found - API endpoint does not exist';
            case 500:
                return 'Internal server error - API is currently unavailable';
            default:
                return 'API request failed with status code: ' . $response_code;
        }
    }

    /**
     * Get iframe ID from settings
     * @return string
     */
    private function get_iframe_id() {
        $settings = get_option('woocommerce_bna_gateway_settings', array());
        return $settings['iframe_id'] ?? '';
    }

    /**
     * Update API credentials
     * @param string $access_key
     * @param string $secret_key
     * @param string $environment
     */
    public function update_credentials($access_key, $secret_key, $environment) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->environment = $environment;
        $this->set_base_url();
    }

    /**
     * Get current environment
     * @return string
     */
    public function get_environment() {
        return $this->environment;
    }

    /**
     * Get base URL
     * @return string
     */
    public function get_base_url() {
        return $this->base_url;
    }

    /**
     * Check if API credentials are configured
     * @return bool
     */
    public function has_credentials() {
        return !empty($this->access_key) && !empty($this->secret_key);
    }
}
