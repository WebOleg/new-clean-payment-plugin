<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Helper Class with Payload Logging
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

    private function determine_api_url() {
        $mode = $this->config['mode'] ?? 'development';
        if (!$this->config_manager->is_valid_mode($mode)) {
            $mode = 'development';
        }
        return $this->config_manager->get_api_url($mode);
    }

    public function get_checkout_token($order) {
        $email = $order->get_billing_email();
        $customer_id = $this->find_customer_by_email($email);

        if (!$customer_id) {
            $customer_id = $this->get_stored_customer_id($email);
        }

        if ($customer_id) {
            $payload = $this->build_payload_with_customer_id($order, $customer_id);
            BNA_Simple_Logger::log("Using existing customer for order " . $order->get_id(), array(
                'customer_id' => $customer_id,
                'email' => $email
            ));
        } else {
            $payload = $this->build_payload_with_customer_info($order);
            BNA_Simple_Logger::log("Creating new customer for order " . $order->get_id(), array(
                'email' => $email
            ));
        }

        // Log the full API request with payload
        BNA_Simple_Logger::log_api_request(
            "API REQUEST - Checkout token for order " . $order->get_id(),
            $payload
        );

        $response = $this->make_api_request('/v1/checkout', $payload);

        if (!$response || $response['code'] !== 200) {
            if (($response['code'] === 400 || $response['code'] === 409) && !$customer_id) {
                if ($this->is_customer_exists_error($response['body'])) {
                    BNA_Simple_Logger::log("Customer exists error, searching for existing customer", array(
                        'order_id' => $order->get_id(),
                        'email' => $email,
                        'response_code' => $response['code']
                    ));

                    $found_customer_id = $this->find_customer_by_email($email);
                    if ($found_customer_id) {
                        $this->store_customer_id($email, $found_customer_id);
                        $payload = $this->build_payload_with_customer_id($order, $found_customer_id);
                        
                        // Log retry with customer ID
                        BNA_Simple_Logger::log_api_request(
                            "API RETRY - Using found customer ID for order " . $order->get_id(),
                            $payload
                        );
                        
                        $response = $this->make_api_request('/v1/checkout', $payload);
                    } else {
                        return $this->retry_with_minimal_data($order);
                    }
                }
            }
        }

        if (!$response || $response['code'] !== 200) {
            BNA_Simple_Logger::log("Token creation failed for order " . $order->get_id(), array(
                'response_code' => $response['code'] ?? 'no_response',
                'response_body' => $response['body'] ?? 'no_response',
                'email' => $email,
                'final_payload_used' => $payload
            ));
            return false;
        }

        $data = json_decode($response['body'], true);
        if (!isset($data['token'])) {
            BNA_Simple_Logger::log("No token in response for order " . $order->get_id(), array(
                'response_body' => $response['body'],
                'parsed_data' => $data
            ));
            return false;
        }

        if (!$customer_id && isset($data['customerId'])) {
            $this->store_customer_id($email, $data['customerId']);
            BNA_Simple_Logger::log("New customer ID saved", array(
                'customer_id' => $data['customerId'],
                'email' => $email
            ));
        }

        // Log successful token creation with response
        BNA_Simple_Logger::log_api_request(
            "TOKEN CREATED SUCCESSFULLY for order " . $order->get_id(),
            $payload,
            $data
        );

        return $data['token'];
    }

    private function find_customer_by_email($email) {
        $response = wp_remote_get($this->api_url . '/v1/customers?email=' . urlencode($email), array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['data'][0]['id']) ? $data['data'][0]['id'] : false;
    }

    private function make_api_request($endpoint, $payload) {
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
            return false;
        }

        return array(
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response)
        );
    }

    private function build_payload_with_customer_id($order, $customer_id) {
        return array(
            'iframeId' => $this->config['iframe_id'],
            'customerId' => $customer_id,
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total()),
            'saveCustomer' => false
        );
    }

    private function build_payload_with_customer_info($order) {
        return array(
            'iframeId' => $this->config['iframe_id'],
            'customerInfo' => $this->build_customer_info($order),
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total()),
            'saveCustomer' => true
        );
    }

    private function build_customer_info($order) {
        $country = $order->get_billing_country();
        if (!in_array($country, ['US', 'CA', 'GB', 'AU'])) {
            $country = 'US';
        }

        $phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
        if (empty($phone) || strlen($phone) < 10) {
            $phone = '1234567890';
        }

        return array(
            'email' => $order->get_billing_email(),
            'type' => 'Personal',
            'phoneCode' => '+1',
            'phoneNumber' => $phone,
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Name',
            'birthDate' => '1990-01-01',
            'address' => array(
                'streetName' => $order->get_billing_address_1() ?: 'Main Street',
                'streetNumber' => $order->get_billing_address_2() ?: '1',
                'apartment' => '',
                'city' => $order->get_billing_city() ?: 'City',
                'province' => $this->normalize_province($order->get_billing_state(), $country),
                'country' => $country,
                'postalCode' => $order->get_billing_postcode() ?: '12345'
            ),
            'additionalInfo' => array(
                'field1' => 'WooCommerce Store Data',
                'field2' => 'Order Number ' . $order->get_order_number(),
                'field3' => 'BNA Payment Gateway Plugin',
                'field4' => 'WordPress Integration Module',
                'field5' => 'Plugin Version 1.0.5 Active',
                'field6' => 'Customer Checkout Information'
            )
        );
    }

    private function build_order_items($order) {
        return array(array(
            'amount' => floatval($order->get_total()),
            'description' => 'Order #' . $order->get_order_number(),
            'price' => floatval($order->get_total()),
            'quantity' => 1,
            'sku' => 'ORDER-' . $order->get_id()
        ));
    }

    private function retry_with_minimal_data($order) {
        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'items' => $this->build_order_items($order),
            'subtotal' => floatval($order->get_total()),
            'saveCustomer' => false
        );

        // Log minimal payload attempt
        BNA_Simple_Logger::log_api_request(
            "API RETRY - Minimal payload for order " . $order->get_id(),
            $payload
        );

        $response = $this->make_api_request('/v1/checkout', $payload);
        
        if (!$response || $response['code'] !== 200) {
            BNA_Simple_Logger::log("Minimal payload also failed for order " . $order->get_id(), array(
                'response_code' => $response['code'] ?? 'no_response',
                'response_body' => $response['body'] ?? 'no_response'
            ));
            return false;
        }

        $data = json_decode($response['body'], true);
        if (!isset($data['token'])) {
            return false;
        }

        // Log successful minimal payload
        BNA_Simple_Logger::log_api_request(
            "TOKEN CREATED with minimal payload for order " . $order->get_id(),
            $payload,
            $data
        );

        return $data['token'];
    }

    private function normalize_province($state, $country) {
        if ($country === 'CA') {
            $provinces = array('AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT');
            return in_array($state, $provinces) ? $state : 'ON';
        } elseif ($country === 'US') {
            return !empty($state) ? $state : 'CA';
        }
        return 'State';
    }

    private function is_customer_exists_error($response_body) {
        $data = json_decode($response_body, true);
        if (!$data || !isset($data['message'])) {
            return false;
        }
        $message = strtolower($data['message']);
        return strpos($message, 'already exists') !== false || 
               strpos($message, 'duplicate') !== false;
    }

    private function get_stored_customer_id($email) {
        return get_option('bna_customer_' . md5($email), false);
    }

    private function store_customer_id($email, $customer_id) {
        update_option('bna_customer_' . md5($email), $customer_id, false);
    }

    public function get_api_url() { 
        return $this->api_url; 
    }

    public function get_mode() { 
        return $this->config['mode'] ?? 'development'; 
    }
}
