<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Helper Class
 */
class BNA_Api_Helper {

    private $config;
    private $api_url;

    public function __construct($config) {
        $this->config = $config;
        $this->api_url = ($config['mode'] === 'production')
            ? 'https://api.bnasmartpayment.com'
            : 'https://stage-api-service.bnasmartpayment.com';
    }

    /**
     * Get checkout token from BNA API
     *
     * @param WC_Order $order
     * @return string|false
     */
    public function get_checkout_token($order) {
        $email = $order->get_billing_email();

        $customer_id = $this->find_customer_by_email($email);

        if (!$customer_id) {
            $customer_id = $this->get_stored_customer_id($email);
        }

        if ($customer_id) {
            error_log('BNA: Using existing customer ID: ' . $customer_id);
            $payload = $this->build_payload_with_customer_id($order, $customer_id);
        } else {
            error_log('BNA: Creating new customer');
            $payload = $this->build_payload_with_customer_info($order);
        }

        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response) {
            return false;
        }

        if (($response['code'] === 400 || $response['code'] === 409) && !$customer_id) {
            if ($this->is_customer_exists_error($response['body'])) {
                error_log('BNA: Customer exists error, searching for customer...');

                $found_customer_id = $this->find_customer_by_email($email);
                if ($found_customer_id) {
                    error_log('BNA: Found existing customer via API: ' . $found_customer_id);
                    $this->store_customer_id($email, $found_customer_id);

                    $payload = $this->build_payload_with_customer_id($order, $found_customer_id);
                    $response = $this->make_api_request('/v1/checkout', $payload);
                } else {
                    return $this->retry_with_minimal_data($order);
                }
            }
        }

        if ($response['code'] !== 200) {
            error_log('BNA API Error: ' . $response['code'] . ' - ' . $response['body']);
            return false;
        }

        $data = json_decode($response['body'], true);

        if (!isset($data['token'])) {
            error_log('BNA API: Token not found in response');
            return false;
        }

        if (!$customer_id && isset($data['customerId'])) {
            $this->store_customer_id($email, $data['customerId']);
        }

        error_log('BNA API Success: Token received');
        return $data['token'];
    }

    /**
     * Find customer by email using BNA API
     *
     * @param string $email
     * @return string|false
     */
    private function find_customer_by_email($email) {
        error_log('BNA: Searching for customer by email: ' . $email);

        $response = wp_remote_get($this->api_url . '/v1/customers?email=' . urlencode($email), array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            error_log('BNA API Customer Search Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('BNA API Customer Search Response: ' . $response_code . ' - ' . $response_body);

        if ($response_code !== 200) {
            return false;
        }

        $data = json_decode($response_body, true);

        if (!$data || !isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
            error_log('BNA: No customers found for email: ' . $email);
            return false;
        }

        if (isset($data['data'][0]['id'])) {
            error_log('BNA: Found customer ID: ' . $data['data'][0]['id']);
            return $data['data'][0]['id'];
        }

        return false;
    }

    /**
     * Get customer details by ID
     *
     * @param string $customer_id
     * @return array|false
     */
    private function get_customer_by_id($customer_id) {
        error_log('BNA: Getting customer details for ID: ' . $customer_id);

        $response = wp_remote_get($this->api_url . '/v1/customers/' . $customer_id, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            error_log('BNA API Get Customer Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('BNA API Get Customer Response: ' . $response_code . ' - ' . $response_body);

        if ($response_code !== 200) {
            return false;
        }

        return json_decode($response_body, true);
    }

    /**
     * Make API request to BNA
     *
     * @param string $endpoint
     * @param array $payload
     * @return array|false
     */
    private function make_api_request($endpoint, $payload) {
        error_log('BNA API Request: ' . $this->api_url . $endpoint);
        error_log('BNA API Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

        $response = wp_remote_post($this->api_url . $endpoint, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => json_encode($payload),
        ));

        if (is_wp_error($response)) {
            error_log('BNA API WP_Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('BNA API Response: ' . $response_code . ' - ' . $response_body);

        return array(
            'code' => $response_code,
            'body' => $response_body
        );
    }

    /**
     * Build payload with customer ID
     *
     * @param WC_Order $order
     * @param string $customer_id
     * @return array
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
     *
     * @param WC_Order $order
     * @return array
     */
    private function build_payload_with_customer_info($order) {
        return array(
            'iframeId' => $this->config['iframe_id'],
            'customerInfo' => $this->build_customer_info($order),
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total())
        );
    }

    /**
     * Build customer info array
     *
     * @param WC_Order $order
     * @return array
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
     *
     * @param WC_Order $order
     * @return array
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
     *
     * @param WC_Order $order
     * @return string|false
     */
    private function retry_with_minimal_data($order) {
        error_log('BNA: Retrying with minimal payload');

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
     *
     * @param string $response_body
     * @return bool
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
     *
     * @param string $email
     * @return string|false
     */
    private function get_stored_customer_id($email) {
        return get_option('bna_customer_' . md5($email), false);
    }

    /**
     * Store customer ID locally
     *
     * @param string $email
     * @param string $customer_id
     */
    private function store_customer_id($email, $customer_id) {
        update_option('bna_customer_' . md5($email), $customer_id, false);
        error_log('BNA: Stored customer ID for email: ' . $email . ' -> ' . $customer_id);
    }

    /**
     * Get API URL
     *
     * @return string
     */
    public function get_api_url() {
        return $this->api_url;
    }
}