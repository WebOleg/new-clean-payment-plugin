<?php
/**
 * BNA API Client
 * Handles communication with BNA Smart Payment API
 * 
 * @package BNA_Payment_Bridge
 * @subpackage API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Client class
 * Manages API requests and responses with BNA Smart Payment
 */
class BNA_Bridge_API_Client {
    
    /**
     * API endpoints configuration
     * 
     * @var array
     */
    private $endpoints = array(
        'staging' => 'https://stage-api-service.bnasmartpayment.com',
        'production' => 'https://api-service.bnasmartpayment.com'
    );
    
    /**
     * API credentials
     * 
     * @var string
     */
    private $access_key;
    private $secret_key;
    
    /**
     * Test mode flag
     * 
     * @var bool
     */
    private $test_mode;
    
    /**
     * Constructor
     * Initialize API client with credentials
     * 
     * @param string $access_key BNA Access Key
     * @param string $secret_key BNA Secret Key  
     * @param bool $test_mode Enable test mode
     */
    public function __construct($access_key = '', $secret_key = '', $test_mode = true) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->test_mode = $test_mode;
    }
    
    /**
     * Get API base URL
     * Returns appropriate endpoint based on test mode
     * 
     * @return string API base URL
     */
    public function get_base_url() {
        return $this->test_mode ? $this->endpoints['staging'] : $this->endpoints['production'];
    }
    
    /**
     * Get authorization header
     * Generate Basic Auth header for API requests
     * 
     * @return array Authorization headers
     */
    private function get_auth_headers() {
        $credentials = base64_encode($this->access_key . ':' . $this->secret_key);
        
        return array(
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }
    
    /**
     * Make API request
     * Send HTTP request to BNA API endpoint
     * 
     * @param string $endpoint API endpoint path
     * @param array $data Request data
     * @param string $method HTTP method (GET, POST, etc.)
     * @return array|WP_Error API response or error
     */
    public function make_request($endpoint, $data = array(), $method = 'GET') {
        $url = $this->get_base_url() . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => $this->get_auth_headers(),
            'timeout' => 30,
            'sslverify' => !$this->test_mode // Allow self-signed certs in test mode
        );
        
        // Add body for POST requests
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }
        
        BNA_Bridge_Helper::log("Making {$method} request to: {$url}", 'debug');
        BNA_Bridge_Helper::log("Request data: " . wp_json_encode($data), 'debug');
        
        // Make the request
        $response = wp_remote_request($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            BNA_Bridge_Helper::log("API request error: " . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        BNA_Bridge_Helper::log("Response code: {$response_code}", 'debug');
        BNA_Bridge_Helper::log("Response body: {$response_body}", 'debug');
        
        // Decode JSON response
        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            BNA_Bridge_Helper::log("JSON decode error: " . json_last_error_msg(), 'error');
            return new WP_Error('json_decode_error', 'Invalid JSON response from API');
        }
        
        // Check response code
        if ($response_code >= 400) {
            $error_message = isset($decoded_response['message']) ? $decoded_response['message'] : 'API request failed';
            BNA_Bridge_Helper::log("API error {$response_code}: {$error_message}", 'error');
            return new WP_Error('api_error', $error_message, array('code' => $response_code));
        }
        
        return array(
            'code' => $response_code,
            'data' => $decoded_response
        );
    }
    
    /**
     * Test API connection
     * Verify API credentials and connectivity
     * 
     * @return bool|WP_Error True if successful, error otherwise
     */
    public function test_connection() {
        BNA_Bridge_Helper::log("Testing API connection...", 'info');
        
        // Check if credentials are provided
        if (empty($this->access_key) || empty($this->secret_key)) {
            return new WP_Error('missing_credentials', 'Access Key and Secret Key are required');
        }
        
        // Make a simple request to test the connection
        $response = $this->make_request('/v1/account', array(), 'GET');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if ($response['code'] === 200) {
            BNA_Bridge_Helper::log("API connection test successful", 'info');
            return true;
        }
        
        return new WP_Error('connection_failed', 'Failed to connect to BNA API');
    }
    
    /**
     * Create checkout token
     * Generate secure token for iframe integration
     * 
     * @param array $checkout_data Checkout data (customer info, items, etc.)
     * @return array|WP_Error Token response or error
     */
    public function create_checkout_token($checkout_data) {
        BNA_Bridge_Helper::log("Creating checkout token...", 'info');
        
        // Validate required data
        if (empty($checkout_data['iframeId'])) {
            return new WP_Error('missing_iframe_id', 'iFrame ID is required');
        }
        
        // Make API request to create token
        $response = $this->make_request('/v1/checkout', $checkout_data, 'POST');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if ($response['code'] === 200 && isset($response['data']['token'])) {
            BNA_Bridge_Helper::log("Checkout token created successfully", 'info');
            return $response['data'];
        }
        
        return new WP_Error('token_creation_failed', 'Failed to create checkout token');
    }
}
