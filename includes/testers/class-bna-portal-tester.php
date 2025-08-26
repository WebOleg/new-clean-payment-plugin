<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Portal Requirements Tester - Complete Version
 */
class BNA_Portal_Tester {

    private $config;
    private $api_url;
    private $results;

    public function __construct($config) {
        $this->config = $config;
        $config_manager = new BNA_Config_Manager();
        $this->api_url = $config_manager->get_api_url($config['mode']);
        $this->results = array();
    }

    public function run_tests() {
        $this->results = array();
        
        $this->test_minimal_payload();
        $this->test_customer_fields();
        $this->test_additional_info();
        $this->test_address_fields();
        $this->test_save_customer_variations();
        
        return $this->results;
    }

    private function test_minimal_payload() {
        $payload = array(
            'iframeId' => $this->config['iframe_id'],
            'items' => array(array(
                'amount' => 1,
                'price' => 1,
                'quantity' => 1,
                'description' => 'Test Item',
                'sku' => 'TEST-001'
            )),
            'subtotal' => 1
        );

        $response = $this->make_request($payload);
        $this->results['minimal'] = array(
            'test_name' => 'Minimal Payload',
            'payload' => $payload,
            'status' => $response['status'],
            'errors' => $response['errors'],
            'success' => $response['status'] === 200
        );
    }

    private function test_customer_fields() {
        $base_customer = array(
            'email' => 'test@example.com',
            'type' => 'Personal'
        );

        $tests = array(
            'basic_customer' => array(
                'name' => 'Basic Customer (email + type only)',
                'data' => $base_customer
            ),
            'customer_with_phone' => array(
                'name' => 'Customer with Phone',
                'data' => array_merge($base_customer, array(
                    'phoneCode' => '+1',
                    'phoneNumber' => '1234567890'
                ))
            ),
            'customer_with_names' => array(
                'name' => 'Customer with Names',
                'data' => array_merge($base_customer, array(
                    'firstName' => 'Test',
                    'lastName' => 'User'
                ))
            ),
            'customer_full_info' => array(
                'name' => 'Customer with Full Info',
                'data' => array_merge($base_customer, array(
                    'firstName' => 'Test',
                    'lastName' => 'User',
                    'phoneCode' => '+1',
                    'phoneNumber' => '1234567890',
                    'birthDate' => '1990-01-01'
                ))
            )
        );

        foreach ($tests as $test_key => $test_info) {
            $payload = array(
                'iframeId' => $this->config['iframe_id'],
                'customerInfo' => $test_info['data'],
                'items' => array(array(
                    'amount' => 1,
                    'price' => 1,
                    'quantity' => 1,
                    'description' => 'Test Item',
                    'sku' => 'TEST-001'
                )),
                'subtotal' => 1,
                'saveCustomer' => true
            );

            $response = $this->make_request($payload);
            $this->results[$test_key] = array(
                'test_name' => $test_info['name'],
                'payload' => $payload,
                'status' => $response['status'],
                'errors' => $response['errors'],
                'success' => $response['status'] === 200
            );
        }
    }

    private function test_additional_info() {
        $base_customer = array(
            'email' => 'test@example.com',
            'type' => 'Personal',
            'firstName' => 'Test',
            'lastName' => 'User',
            'phoneCode' => '+1',
            'phoneNumber' => '1234567890'
        );

        $tests = array(
            'no_additional_info' => array(
                'name' => 'No Additional Info',
                'data' => $base_customer
            ),
            'empty_additional_info' => array(
                'name' => 'Empty Additional Info Object',
                'data' => array_merge($base_customer, array(
                    'additionalInfo' => array()
                ))
            ),
            'partial_additional_info' => array(
                'name' => 'Partial Additional Info',
                'data' => array_merge($base_customer, array(
                    'additionalInfo' => array(
                        'field1' => 'Test Field 1',
                        'field2' => 'Test Field 2'
                    )
                ))
            ),
            'empty_additional_fields' => array(
                'name' => 'Empty Additional Fields',
                'data' => array_merge($base_customer, array(
                    'additionalInfo' => array(
                        'field1' => '',
                        'field2' => '',
                        'field3' => '',
                        'field4' => '',
                        'field5' => '',
                        'field6' => ''
                    )
                ))
            ),
            'full_additional_info' => array(
                'name' => 'Full Additional Info',
                'data' => array_merge($base_customer, array(
                    'additionalInfo' => array(
                        'field1' => 'WooCommerce Store Data',
                        'field2' => 'Order Number TEST',
                        'field3' => 'BNA Payment Gateway Plugin',
                        'field4' => 'WordPress Integration Module',
                        'field5' => 'Plugin Version 1.0.5 Active',
                        'field6' => 'Customer Checkout Information'
                    )
                ))
            )
        );

        foreach ($tests as $test_key => $test_info) {
            $payload = array(
                'iframeId' => $this->config['iframe_id'],
                'customerInfo' => $test_info['data'],
                'items' => array(array(
                    'amount' => 1,
                    'price' => 1,
                    'quantity' => 1,
                    'description' => 'Test Item',
                    'sku' => 'TEST-001'
                )),
                'subtotal' => 1,
                'saveCustomer' => true
            );

            $response = $this->make_request($payload);
            $this->results[$test_key] = array(
                'test_name' => $test_info['name'],
                'payload' => $payload,
                'status' => $response['status'],
                'errors' => $response['errors'],
                'success' => $response['status'] === 200
            );
        }
    }

    private function test_address_fields() {
        $base_customer = array(
            'email' => 'test@example.com',
            'type' => 'Personal',
            'firstName' => 'Test',
            'lastName' => 'User'
        );

        $tests = array(
            'no_address' => array(
                'name' => 'No Address',
                'data' => $base_customer
            ),
            'minimal_address' => array(
                'name' => 'Minimal Address (country only)',
                'data' => array_merge($base_customer, array(
                    'address' => array(
                        'country' => 'US'
                    )
                ))
            ),
            'full_address' => array(
                'name' => 'Full Address',
                'data' => array_merge($base_customer, array(
                    'address' => array(
                        'streetName' => 'Test Street',
                        'streetNumber' => '123',
                        'apartment' => 'Apt 1',
                        'city' => 'Test City',
                        'province' => 'CA',
                        'country' => 'US',
                        'postalCode' => '12345'
                    )
                ))
            )
        );

        foreach ($tests as $test_key => $test_info) {
            $payload = array(
                'iframeId' => $this->config['iframe_id'],
                'customerInfo' => $test_info['data'],
                'items' => array(array(
                    'amount' => 1,
                    'price' => 1,
                    'quantity' => 1,
                    'description' => 'Test Item',
                    'sku' => 'TEST-001'
                )),
                'subtotal' => 1,
                'saveCustomer' => true
            );

            $response = $this->make_request($payload);
            $this->results[$test_key] = array(
                'test_name' => $test_info['name'],
                'payload' => $payload,
                'status' => $response['status'],
                'errors' => $response['errors'],
                'success' => $response['status'] === 200
            );
        }
    }

    private function test_save_customer_variations() {
        $customer_data = array(
            'email' => 'test@example.com',
            'type' => 'Personal',
            'firstName' => 'Test',
            'lastName' => 'User'
        );

        $tests = array(
            'save_customer_true' => array(
                'name' => 'Save Customer = true',
                'save_customer' => true
            ),
            'save_customer_false' => array(
                'name' => 'Save Customer = false',
                'save_customer' => false
            ),
            'no_save_customer' => array(
                'name' => 'No Save Customer field',
                'save_customer' => null
            )
        );

        foreach ($tests as $test_key => $test_info) {
            $payload = array(
                'iframeId' => $this->config['iframe_id'],
                'customerInfo' => $customer_data,
                'items' => array(array(
                    'amount' => 1,
                    'price' => 1,
                    'quantity' => 1,
                    'description' => 'Test Item',
                    'sku' => 'TEST-001'
                )),
                'subtotal' => 1
            );

            if ($test_info['save_customer'] !== null) {
                $payload['saveCustomer'] = $test_info['save_customer'];
            }

            $response = $this->make_request($payload);
            $this->results[$test_key] = array(
                'test_name' => $test_info['name'],
                'payload' => $payload,
                'status' => $response['status'],
                'errors' => $response['errors'],
                'success' => $response['status'] === 200
            );
        }
    }

    private function make_request($payload) {
        $response = wp_remote_post($this->api_url . '/v1/checkout', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['access_key'] . ':' . $this->config['secret_key']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload)
        ));

        if (is_wp_error($response)) {
            return array(
                'status' => 0,
                'errors' => array('connection' => $response->get_error_message())
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $errors = array();
        if ($status === 400 && isset($data['details'])) {
            $errors = $data['details'];
        } elseif ($status !== 200) {
            $errors = array('api' => $data['message'] ?? 'Unknown error');
        }

        return array(
            'status' => $status,
            'errors' => $errors,
            'body' => $body
        );
    }

    public function generate_report() {
        if (empty($this->results)) {
            return array('No test results available');
        }

        $report = array(
            "BNA PORTAL REQUIREMENTS TEST REPORT",
            "====================================",
            "",
            "Timestamp: " . current_time('Y-m-d H:i:s T'),
            "API URL: " . $this->api_url,
            "iFrame ID: " . $this->config['iframe_id'],
            "Mode: " . $this->config['mode'],
            "",
            "TEST RESULTS:",
            "-------------"
        );

        foreach ($this->results as $test_key => $result) {
            $status_icon = $result['success'] ? '✅' : '❌';
            $report[] = "";
            $report[] = "$status_icon {$result['test_name']}";
            $report[] = "   Status: HTTP {$result['status']}";
            
            if (!empty($result['errors'])) {
                $report[] = "   Errors:";
                foreach ($result['errors'] as $field => $error) {
                    $report[] = "     - $field: $error";
                }
            }
        }

        $report[] = "";
        $report[] = "ANALYSIS:";
        $report[] = "---------";
        $report[] = $this->analyze_results();
        
        $report[] = "";
        $report[] = "RECOMMENDATIONS:";
        $report[] = "---------------";
        $report[] = $this->get_recommendations();
        
        $report[] = "";
        $report[] = "====================================";

        return $report;
    }

    private function analyze_results() {
        $successful = array_filter($this->results, function($r) { return $r['success']; });
        $failed = array_filter($this->results, function($r) { return !$r['success']; });

        $analysis = array();
        
        $analysis[] = "• Total tests: " . count($this->results);
        $analysis[] = "• Successful: " . count($successful);
        $analysis[] = "• Failed: " . count($failed);

        if (!empty($successful)) {
            $successful_names = array_map(function($r) { return $r['test_name']; }, $successful);
            $analysis[] = "• Working configurations: " . implode(', ', $successful_names);
        }

        if (!empty($failed)) {
            $all_errors = array();
            foreach ($failed as $test) {
                $all_errors = array_merge($all_errors, array_keys($test['errors']));
            }
            $error_counts = array_count_values($all_errors);
            arsort($error_counts);
            
            $analysis[] = "• Most common errors: " . implode(', ', array_keys($error_counts));
        }

        return implode("\n", $analysis);
    }

    private function get_recommendations() {
        $working = array_filter($this->results, function($r) { return $r['success']; });
        
        if (empty($working)) {
            return "❌ No working configurations found. Check:\n" .
                   "  1. iFrame ID is active in BNA Portal\n" .
                   "  2. Domain is whitelisted\n" .
                   "  3. API credentials are correct";
        }

        $simplest_working = reset($working);
        $recommendations = array();
        
        $recommendations[] = "✅ Use configuration: " . $simplest_working['test_name'];
        
        // Analyze what fields are actually required
        if (isset($this->results['minimal']) && $this->results['minimal']['success']) {
            $recommendations[] = "• Minimal payload works - customerInfo not required";
        }
        
        if (isset($this->results['save_customer_true']) && $this->results['save_customer_true']['success']) {
            $recommendations[] = "• saveCustomer field is required";
        }
        
        return implode("\n", $recommendations);
    }

    public function get_recommendation() {
        if (empty($this->results)) {
            return "Run tests first";
        }

        $working = array_filter($this->results, function($r) { return $r['success']; });
        
        if (empty($working)) {
            return "No working configuration found - check portal settings";
        }

        $first_working = reset($working);
        return "Use: " . $first_working['test_name'];
    }
}
