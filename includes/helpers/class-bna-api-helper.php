<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Helper Class
 *
 * Enhanced API client with comprehensive debugging support
 */
class BNA_Api_Helper {

    private $config;
    private $config_manager;
    private $api_url;

    public function __construct($config) {
        $this->config = $config;
        $this->config_manager = new BNA_Config_Manager();
        $this->api_url = $this->determine_api_url();

        $this->log_environment_info();
    }

    /**
     * Determine API URL based on configuration
     */
    private function determine_api_url() {
        $mode = $this->config['mode'] ?? 'development';

        if (!$this->config_manager->is_valid_mode($mode)) {
            BNA_Debug_Helper::log('Invalid API mode provided', array(
                'provided_mode' => $mode,
                'valid_modes' => array_keys($this->config_manager->get_api_endpoints())
            ), 'WARNING');

            $mode = 'development';
        }

        $url = $this->config_manager->get_api_url($mode);

        BNA_Debug_Helper::log('API URL determined', array(
            'mode' => $mode,
            'api_url' => $url
        ), 'INFO');

        return $url;
    }

    /**
     * Log current environment information
     */
    private function log_environment_info() {
        $mode = $this->config['mode'] ?? 'development';
        $mode_label = $this->config_manager->get_mode_label($mode);

        BNA_Debug_Helper::log('BNA API Helper initialized', array(
            'mode' => $mode,
            'mode_label' => $mode_label,
            'api_url' => $this->api_url,
            'config_keys' => array_keys($this->config),
            'iframe_id' => $this->config['iframe_id'] ?? 'NOT_SET',
            'has_credentials' => !empty($this->config['access_key']) && !empty($this->config['secret_key'])
        ), 'INFO');
    }

    /**
     * Get checkout token from BNA API
     */
    public function get_checkout_token($order) {
        BNA_Debug_Helper::log_order_processing($order, 'CHECKOUT_TOKEN_REQUEST_STARTED');

        $email = $order->get_billing_email();

        // Search for existing customer
        $customer_id = $this->find_customer_by_email($email);

        if (!$customer_id) {
            $customer_id = $this->get_stored_customer_id($email);
            if ($customer_id) {
                BNA_Debug_Helper::log('Using stored customer ID', array(
                    'customer_id' => $customer_id,
                    'email' => $email
                ), 'CUSTOMER');
            }
        }

        // Build payload based on customer data
        if ($customer_id) {
            BNA_Debug_Helper::log('Building payload with existing customer ID', array(
                'customer_id' => $customer_id
            ), 'CUSTOMER');

            $payload = $this->build_payload_with_customer_id($order, $customer_id);
        } else {
            BNA_Debug_Helper::log('Building payload with new customer info', array(
                'email' => $email,
                'reason' => 'No existing customer found'
            ), 'CUSTOMER');

            $payload = $this->build_payload_with_customer_info($order);
        }

        // Log the final payload being sent
        BNA_Debug_Helper::log('FINAL CHECKOUT PAYLOAD', array(
            'has_customer_id' => isset($payload['customerId']),
            'has_customer_info' => isset($payload['customerInfo']),
            'iframe_id' => $payload['iframeId'] ?? 'MISSING',
            'subtotal' => $payload['subtotal'] ?? 'MISSING',
            'items_count' => count($payload['items'] ?? []),
            'full_payload' => $payload
        ), 'API');

        // Make the API request
        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response) {
            BNA_Debug_Helper::log('API request failed completely', array(
                'endpoint' => '/v1/checkout'
            ), 'ERROR');
            return false;
        }

        // Handle customer exists error
        if (($response['code'] === 400 || $response['code'] === 409) && !$customer_id) {
            if ($this->is_customer_exists_error($response['body'])) {
                BNA_Debug_Helper::log('Customer exists error detected, searching for existing customer', array(
                    'response_code' => $response['code'],
                    'email' => $email
                ), 'WARNING');

                $found_customer_id = $this->find_customer_by_email($email);
                if ($found_customer_id) {
                    BNA_Debug_Helper::log('Found existing customer, retrying with customer ID', array(
                        'found_customer_id' => $found_customer_id
                    ), 'CUSTOMER');

                    $this->store_customer_id($email, $found_customer_id);
                    $payload = $this->build_payload_with_customer_id($order, $found_customer_id);

                    BNA_Debug_Helper::log('RETRY PAYLOAD', array(
                        'payload' => $payload
                    ), 'API');

                    $response = $this->make_api_request('/v1/checkout', $payload);
                } else {
                    BNA_Debug_Helper::log('Could not find existing customer, trying minimal payload', array(), 'WARNING');
                    return $this->retry_with_minimal_data($order);
                }
            }
        }

        // Handle response
        if ($response['code'] !== 200) {
            BNA_Debug_Helper::log('API request failed with error response', array(
                'status_code' => $response['code'],
                'response_body' => $response['body']
            ), 'ERROR');
            return false;
        }

        $data = json_decode($response['body'], true);

        if (!isset($data['token'])) {
            BNA_Debug_Helper::log('Token not found in successful response', array(
                'response_data' => $data
            ), 'ERROR');
            return false;
        }

        // Store customer ID if created
        if (!$customer_id && isset($data['customerId'])) {
            $this->store_customer_id($email, $data['customerId']);
            BNA_Debug_Helper::log('New customer ID stored', array(
                'customer_id' => $data['customerId'],
                'email' => $email
            ), 'CUSTOMER');
        }

        BNA_Debug_Helper::log_iframe_token($data['token'], array(
            'iframe_id' => $this->config['iframe_id'],
            'mode' => $this->config['mode'],
            'api_url' => $this->api_url
        ));

        return $data['token'];
    }

    /**
     * Find customer by email using BNA API
     */
    private function find_customer_by_email($email) {
        BNA_Debug_Helper::log('Searching for customer by email', array(
            'email' => $email,
            'endpoint' => '/v1/customers?email=' . urlencode($email)
        ), 'CUSTOMER');

        $response = wp_remote_get($this->api_url . '/v1/customers?email=' . urlencode($email), array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            BNA_Debug_Helper::log('Customer search failed with WP Error', array(
                'error_message' => $response->get_error_message(),
                'email' => $email
            ), 'ERROR');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        BNA_Debug_Helper::log_api_response($response_code, $response_body);

        if ($response_code !== 200) {
            BNA_Debug_Helper::log('Customer search failed', array(
                'status_code' => $response_code,
                'email' => $email
            ), 'WARNING');
            return false;
        }

        $data = json_decode($response_body, true);

        if (!$data || !isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
            BNA_Debug_Helper::log('No customers found in search response', array(
                'email' => $email,
                'response_structure' => array(
                    'has_data_key' => isset($data['data']),
                    'data_type' => isset($data['data']) ? gettype($data['data']) : 'missing',
                    'data_count' => isset($data['data']) && is_array($data['data']) ? count($data['data']) : 'N/A'
                )
            ), 'INFO');
            return false;
        }

        if (isset($data['data'][0]['id'])) {
            BNA_Debug_Helper::log('Customer found in search', array(
                'customer_id' => $data['data'][0]['id'],
                'customer_data' => $data['data'][0],
                'email' => $email
            ), 'CUSTOMER');

            return $data['data'][0]['id'];
        }

        return false;
    }

    /**
     * Make API request to BNA
     */
    private function make_api_request($endpoint, $payload) {
        $full_url = $this->api_url . $endpoint;

        // Log the request
        BNA_Debug_Helper::log_api_request($full_url, $payload, 'POST');

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
            BNA_Debug_Helper::log('API request failed with WP Error', array(
                'endpoint' => $endpoint,
                'error_message' => $response->get_error_message(),
                'error_code' => $response->get_error_code()
            ), 'ERROR');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Log the response
        BNA_Debug_Helper::log_api_response($response_code, $response_body, $response_headers->getAll());

        return array(
            'code' => $response_code,
            'body' => $response_body
        );
    }

    /**
     * Build payload with customer ID
     */
    private function build_payload_with_customer_id($order, $customer_id) {
        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'customerId' => $customer_id,
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );

        BNA_Debug_Helper::log('Built payload with customer ID', array(
            'customer_id' => $customer_id,
            'iframe_id' => $this->config['iframe_id'],
            'has_customer_info_section' => false,
            'payload' => $payload
        ), 'DEBUG');

        return $payload;
    }

    /**
     * Build payload with customer info
     */
    private function build_payload_with_customer_info($order) {
        $customer_info = $this->build_customer_info($order);

        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'customerInfo' => $customer_info,
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );

        BNA_Debug_Helper::log('Built payload with customer info', array(
            'iframe_id' => $this->config['iframe_id'],
            'has_customer_id_field' => false,
            'customer_info' => $customer_info,
            'payload' => $payload
        ), 'DEBUG');

        return $payload;
    }

    // ... rest of methods stay the same but add debug logging where needed

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
        $customer_info = array(
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

        BNA_Debug_Helper::log_customer_processing($customer_info, 'BUILD_CUSTOMER_INFO');

        return $customer_info;
    }

    /**
     * Build order items array
     */
    private function build_order_items($order) {
        $items = array(
            array(
                'amount' => floatval($order->get_total()),
                'description' => 'Order #' . $order->get_order_number(),
                'price' => floatval($order->get_total()),
                'quantity' => 1,
                'sku' => 'ORDER-' . $order->get_id()
            )
        );

        BNA_Debug_Helper::log('Built order items', array(
            'items' => $items,
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total()
        ), 'DEBUG');

        return $items;
    }

    /**
     * Retry with minimal data
     */
    private function retry_with_minimal_data($order) {
        BNA_Debug_Helper::log('Retrying with minimal payload (no customer data)', array(
            'order_id' => $order->get_id()
        ), 'WARNING');

        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );

        BNA_Debug_Helper::log('MINIMAL PAYLOAD', array(
            'payload' => $payload,
            'has_customer_id' => false,
            'has_customer_info' => false
        ), 'API');

        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response || $response['code'] !== 200) {
            BNA_Debug_Helper::log('Minimal payload request also failed', array(
                'response_code' => $response['code'] ?? 'NO_RESPONSE'
            ), 'ERROR');
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
        $is_exists_error = strpos($message, 'already exists') !== false ||
            strpos($message, 'duplicate') !== false ||
            strpos($message, 'user already exists') !== false ||
            strpos($message, 'customer already exists') !== false;

        BNA_Debug_Helper::log('Checking for customer exists error', array(
            'response_message' => $data['message'],
            'is_exists_error' => $is_exists_error
        ), 'DEBUG');

        return $is_exists_error;
    }

    /**
     * Get stored customer ID
     */
    private function get_stored_customer_id($email) {
        $customer_id = get_option('bna_customer_' . md5($email), false);

        if ($customer_id) {
            BNA_Debug_Helper::log('Retrieved stored customer ID', array(
                'email' => $email,
                'customer_id' => $customer_id
            ), 'CUSTOMER');
        }

        return $customer_id;
    }

    /**
     * Store customer ID locally
     */
    private function store_customer_id($email, $customer_id) {
        update_option('bna_customer_' . md5($email), $customer_id, false);

        BNA_Debug_Helper::log('Stored customer ID locally', array(
            'email' => $email,
            'customer_id' => $customer_id,
            'option_key' => 'bna_customer_' . md5($email)
        ), 'CUSTOMER');
    }

    // Existing methods for API URL, mode checking, etc.
    public function get_api_url() { return $this->api_url; }
    public function get_mode() { return $this->config['mode'] ?? 'development'; }
    public function is_production() { return $this->get_mode() === 'production'; }
    public function is_development() { return $this->get_mode() === 'development'; }

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