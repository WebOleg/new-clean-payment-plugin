<?php
/**
 * BNA Iframe Handler Class (з виправленою логікою пошуку customer)
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Iframe_Handler {

    public function __construct() {
        add_action('wp_ajax_load_bna_iframe', array($this, 'load_iframe_callback'));
        add_action('wp_ajax_nopriv_load_bna_iframe', array($this, 'load_iframe_callback'));
    }

    public function load_iframe_callback() {

        check_ajax_referer('bna_iframe_nonce', 'nonce');

        $settings = get_option('woocommerce_bna_payment_gateway_settings');
        $access_key = $settings['access_key'] ?? '';
        $secret_key = $settings['secret_key'] ?? '';
        $iframe_id = $settings['iframe_id'] ?? '';
        $environment = $settings['environment'] ?? 'https://dev-api-service.bnasmartpayment.com';

        // PHP 7.4 compatible base URL mapping
        switch ($environment) {
            case 'https://production-api-service.bnasmartpayment.com':
                $base_url = 'https://api.bnasmartpayment.com';
                break;
            case 'https://dev-api-service.bnasmartpayment.com':
                $base_url = 'https://stage-api-service.bnasmartpayment.com';
                break;
            default:
                $base_url = 'https://stage-api-service.bnasmartpayment.com';
                break;
        }

        // Get customer data
        $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
        $first_name = isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '';
        $last_name = isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '';
        $post_code = isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '';
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
        $city = isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '';
        $country = isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '';
        $address_1 = isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '';
        $birth_date = isset($_POST['billing_birth_date']) ? sanitize_text_field($_POST['billing_birth_date']) : '';

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BNA Iframe: Processing checkout for email ' . $email);
        }

        // Validation
        $errors = [];
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address required';
        }
        if (empty($first_name) || empty($last_name)) {
            $errors[] = 'First name and last name required';
        }

        if (!empty($errors)) {
            echo '<div style="color:red;"><strong>❌ Validation Errors:</strong><br>' . implode('<br>', $errors) . '</div>';
            wp_die();
        }

        // Check if we have stored customer ID
        $stored_customer_id = null;
        if (is_user_logged_in()) {
            $stored_customer_id = get_user_meta(get_current_user_id(), 'bna_payorID', true);
        } else {
            $stored_customer_id = get_transient('bna_customer_' . md5($email));
        }

        // Build payload згідно BNA API документації
        $payload = [
            'iframeId' => $iframe_id,
            'items' => [
                [
                    'amount' => 1.00,
                    'description' => 'Order Payment',
                    'price' => 1.00,
                    'quantity' => 1,
                    'sku' => 'ORDER-001',
                ]
            ],
            'subtotal' => 1.00,
        ];

        // Add customer info based on BNA API documentation
        if (!empty($stored_customer_id)) {
            $payload['customerId'] = $stored_customer_id;
        } else {
            $payload['customerInfo'] = [
                'type' => 'Personal',
                'email' => $email,
                'firstName' => $first_name,
                'lastName' => $last_name,
                'phoneCode' => '+1',
                'phoneNumber' => preg_replace('/[^0-9]/', '', $phone) ?: '1234567890',
                'birthDate' => !empty($birth_date) ? $birth_date : '1990-01-01',
                'address' => [
                    'streetName' => $address_1 ?: 'Main Street',
                    'streetNumber' => '123',
                    'apartment' => '',
                    'city' => $city ?: 'Toronto',
                    'province' => $this->get_province_name($country, $_POST['billing_state'] ?? ''),
                    'country' => $this->get_country_name($country),
                    'postalCode' => $post_code ?: 'M1M 1M1',
                ]
            ];
        }

        echo '<div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 10px;">';
        echo '<h4>🔄 Creating BNA Checkout Session</h4>';
        echo '<p><strong>Email:</strong> ' . esc_html($email) . '</p>';
        echo '<p><strong>Customer ID:</strong> ' . (!empty($stored_customer_id) ? esc_html($stored_customer_id) : 'Creating new') . '</p>';
        echo '</div>';

        // Make API request to /v1/checkout
        $response = wp_remote_post("{$base_url}/v1/checkout", [
            'timeout' => 45, // Збільшений timeout
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            echo '<div style="color:red; background: #ffe6e6; padding: 15px; border-radius: 4px;">';
            echo '<strong>❌ Connection Error</strong><br>';
            echo esc_html($response->get_error_message());
            echo '</div>';
            wp_die();
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $body = json_decode($response_body, true);

        echo '<div style="background: #f0f8ff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
        echo '<strong>API Response:</strong> HTTP ' . esc_html($http_code) . '<br>';

        // Handle 409 error with FIXED customer search logic
        if ($http_code === 409) {
            echo '<span style="color: orange;">Customer already exists, searching for customer ID...</span><br>';
            echo '</div>';

            // Try different search methods
            echo '<div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 10px;">';
            echo '🔍 <strong>Searching for existing customer...</strong><br>';

            // Method 1: Search by email
            $search_url = "{$base_url}/v1/customers?email=" . urlencode($email);
            echo '<strong>Search URL:</strong> ' . esc_html($search_url) . '<br>';

            $search_response = wp_remote_get($search_url, [
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                    'Content-Type' => 'application/json',
                ],
            ]);

            if (is_wp_error($search_response)) {
                echo '<span style="color: red;">❌ Customer search failed: ' . esc_html($search_response->get_error_message()) . '</span><br>';
                echo '</div>';
                $this->show_user_friendly_409_message($email);
                wp_die();
            }

            $search_http_code = wp_remote_retrieve_response_code($search_response);
            $search_response_body = wp_remote_retrieve_body($search_response);
            $search_body = json_decode($search_response_body, true);

            echo '<strong>Search Response:</strong> HTTP ' . esc_html($search_http_code) . '<br>';

            // DEBUG: Show full response for analysis
            echo '<details style="margin: 10px 0;"><summary style="cursor: pointer; color: #0066cc;"><strong>🔍 DEBUG: Full API Response</strong></summary>';
            echo '<div style="background: #f8f8f8; padding: 10px; border-radius: 4px; margin-top: 5px;">';
            echo '<pre style="font-size: 11px; overflow-x: auto; white-space: pre-wrap;">';
            echo esc_html(json_encode($search_body, JSON_PRETTY_PRINT));
            echo '</pre>';
            echo '</div>';
            echo '</details>';

            if ($search_http_code === 200 && !empty($search_body)) {
                $found_customer_id = null;

                echo '<strong>🔍 Analysis of search results:</strong><br>';

                // FIXED LOGIC: Check for 'data' array first (this is the main fix!)
                if (isset($search_body['data']) && is_array($search_body['data'])) {
                    echo '<span style="color: blue;">• Found "data" array with ' . count($search_body['data']) . ' customers</span><br>';

                    foreach ($search_body['data'] as $index => $customer) {
                        if (isset($customer['email'])) {
                            $email_match = ($this->normalize_email($customer['email']) === $this->normalize_email($email));
                            echo '<span style="color: #666;">  - Customer ' . $index . ': "' . esc_html($customer['email']) . '" (match: ' . ($email_match ? 'YES' : 'NO') . ')</span><br>';

                            if ($email_match) {
                                $found_customer_id = $customer['id'] ?? $customer['customerId'] ?? null;
                                if ($found_customer_id) {
                                    echo '<span style="color: green;">    → ✅ Found customer ID: ' . esc_html($found_customer_id) . '</span><br>';
                                    break; // Знайшли клієнта, виходимо з циклу
                                }
                            }
                        }
                    }
                } else if (is_array($search_body)) {
                    // Handle case where response is direct array of customers (legacy support)
                    echo '<span style="color: blue;">• Response is direct array with ' . count($search_body) . ' customers</span><br>';

                    foreach ($search_body as $index => $customer) {
                        if (is_array($customer) && isset($customer['email'])) {
                            $email_match = ($this->normalize_email($customer['email']) === $this->normalize_email($email));
                            echo '<span style="color: #666;">  - Customer ' . $index . ': "' . esc_html($customer['email']) . '" (match: ' . ($email_match ? 'YES' : 'NO') . ')</span><br>';

                            if ($email_match) {
                                $found_customer_id = $customer['id'] ?? $customer['customerId'] ?? null;
                                if ($found_customer_id) {
                                    echo '<span style="color: green;">    → ✅ Found customer ID: ' . esc_html($found_customer_id) . '</span><br>';
                                    break;
                                }
                            }
                        }
                    }
                } else if (isset($search_body['email'])) {
                    // Handle single customer response
                    echo '<span style="color: blue;">• Direct customer object with email: "' . esc_html($search_body['email']) . '"</span><br>';
                    if ($this->normalize_email($search_body['email']) === $this->normalize_email($email)) {
                        $found_customer_id = $search_body['id'] ?? $search_body['customerId'] ?? null;
                        if ($found_customer_id) {
                            echo '<span style="color: green;">  → ✅ Found customer ID: ' . esc_html($found_customer_id) . '</span><br>';
                        }
                    }
                } else {
                    echo '<span style="color: orange;">• Unexpected response structure</span><br>';
                }

                // Try alternative search methods if not found
                if (!$found_customer_id) {
                    echo '<span style="color: orange;">🔍 Customer not found with direct search. Trying all customers...</span><br>';

                    // Method 2: Get all customers and search locally
                    $all_customers_response = wp_remote_get("{$base_url}/v1/customers", [
                        'timeout' => 45,
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                            'Content-Type' => 'application/json',
                        ],
                    ]);

                    if (!is_wp_error($all_customers_response)) {
                        $all_http_code = wp_remote_retrieve_response_code($all_customers_response);
                        $all_response_body = wp_remote_retrieve_body($all_customers_response);
                        $all_customers = json_decode($all_response_body, true);

                        echo '<strong>🔍 Alternative search - Get all customers:</strong> HTTP ' . esc_html($all_http_code) . '<br>';

                        if ($all_http_code === 200 && !empty($all_customers)) {
                            // Search in all customers
                            $customers_to_search = [];

                            if (isset($all_customers['data']) && is_array($all_customers['data'])) {
                                $customers_to_search = $all_customers['data'];
                            } elseif (is_array($all_customers)) {
                                $customers_to_search = $all_customers;
                            }

                            echo '<span style="color: blue;">• Searching in ' . count($customers_to_search) . ' total customers</span><br>';

                            foreach ($customers_to_search as $customer) {
                                if (isset($customer['email'])) {
                                    if ($this->normalize_email($customer['email']) === $this->normalize_email($email)) {
                                        $found_customer_id = $customer['id'] ?? $customer['customerId'] ?? null;
                                        echo '<span style="color: green;">✅ Found customer by email in all customers list!</span><br>';
                                        echo '<span style="color: green;">  → Customer ID: ' . esc_html($found_customer_id) . '</span><br>';
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($found_customer_id) {
                    echo '<span style="color: green;">✅ Successfully found customer ID: ' . esc_html($found_customer_id) . '</span><br>';
                    echo '</div>';

                    // Debug logging
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('BNA Iframe: Found existing customer ID ' . $found_customer_id);
                    }

                    // Save the customer ID for future use
                    if (is_user_logged_in()) {
                        update_user_meta(get_current_user_id(), 'bna_payorID', $found_customer_id);
                    } else {
                        set_transient('bna_customer_' . md5($email), $found_customer_id, WEEK_IN_SECONDS);
                    }

                    echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                    echo '💾 <strong>Customer ID saved:</strong> ' . esc_html($found_customer_id) . ' for future use';
                    echo '</div>';

                    // Now retry checkout with the found customer ID
                    $retry_payload = [
                        'iframeId' => $iframe_id,
                        'customerId' => $found_customer_id,
                        'items' => [
                            [
                                'amount' => 1.00,
                                'description' => 'Order Payment',
                                'price' => 1.00,
                                'quantity' => 1,
                                'sku' => 'ORDER-001',
                            ]
                        ],
                        'subtotal' => 1.00,
                    ];

                    echo '<div style="background: #f0f8ff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                    echo '<strong>🔄 Retrying checkout with found customer ID...</strong><br>';

                    $retry_response = wp_remote_post("{$base_url}/v1/checkout", [
                        'timeout' => 45,
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                            'Content-Type' => 'application/json',
                        ],
                        'body' => json_encode($retry_payload),
                    ]);

                    if (!is_wp_error($retry_response)) {
                        $retry_http_code = wp_remote_retrieve_response_code($retry_response);
                        $retry_response_body = wp_remote_retrieve_body($retry_response);
                        $retry_body = json_decode($retry_response_body, true);

                        echo '<strong>Retry Response:</strong> HTTP ' . esc_html($retry_http_code) . '<br>';

                        if (($retry_http_code === 200 || $retry_http_code === 201) && !empty($retry_body['token'])) {
                            echo '<span style="color: green;">✅ Success with existing customer!</span>';
                            echo '</div>';

                            echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                            echo '✅ <strong>Payment form loaded successfully!</strong><br>';
                            echo '<small>Token: ' . esc_html(substr($retry_body['token'], 0, 16)) . '...</small>';
                            echo '</div>';

                            echo '<iframe src="' . esc_url("{$base_url}/v1/checkout/{$retry_body['token']}") . '" width="100%" height="600" style="border:none;margin-top:20px;" onload="console.log(\'BNA iframe loaded successfully\')"></iframe>';
                            wp_die();
                        } else {
                            echo '<span style="color: red;">❌ Retry failed with HTTP ' . esc_html($retry_http_code) . '</span>';
                            if (!empty($retry_body['message'])) {
                                echo '<br><span style="color: red;">Error: ' . esc_html($retry_body['message']) . '</span>';
                            }
                            echo '</div>';
                        }
                    } else {
                        echo '<span style="color: red;">❌ Retry request failed: ' . esc_html($retry_response->get_error_message()) . '</span>';
                        echo '</div>';
                    }
                } else {
                    echo '<span style="color: orange;">⚠️ Customer not found in search results despite 409 error</span><br>';
                    echo '</div>';
                }
            } else {
                if ($search_http_code !== 200) {
                    echo '<span style="color: red;">❌ Search API error: HTTP ' . esc_html($search_http_code) . '</span><br>';
                } else {
                    echo '<span style="color: orange;">⚠️ Customer search returned empty results</span><br>';
                }
                echo '</div>';
            }

            // If we get here, automatic resolution failed, show user-friendly message
            $this->show_user_friendly_409_message($email);
            wp_die();
        }

        // Handle successful responses
        if ($http_code === 200 || $http_code === 201) {
            if (!empty($body['token'])) {
                echo '<span style="color: green;">✅ Success! Token received</span>';
                echo '</div>';

                // Save customer ID for future use if provided
                if (!empty($body['customerId'])) {
                    if (is_user_logged_in()) {
                        update_user_meta(get_current_user_id(), 'bna_payorID', $body['customerId']);
                    } else {
                        set_transient('bna_customer_' . md5($email), $body['customerId'], WEEK_IN_SECONDS);
                    }

                    echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                    echo '💾 <strong>Customer ID saved:</strong> ' . esc_html($body['customerId']);
                    echo '</div>';
                }

                echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                echo '✅ <strong>Payment form loaded successfully!</strong><br>';
                echo '<small>Token: ' . esc_html(substr($body['token'], 0, 16)) . '...</small>';
                echo '</div>';

                echo '<iframe src="' . esc_url("{$base_url}/v1/checkout/{$body['token']}") . '" width="100%" height="600" style="border:none;margin-top:20px;" onload="console.log(\'BNA iframe loaded successfully\')"></iframe>';

            } else {
                echo '<span style="color: red;">❌ No token received</span>';
                echo '</div>';

                echo '<div style="color:red; background: #ffe6e6; padding: 15px; border-radius: 4px;">';
                echo '<strong>❌ API Response Issue</strong><br>';
                echo 'API returned HTTP ' . esc_html($http_code) . ' but no token was provided.<br>';
                echo '<button onclick="location.reload()" style="margin-top: 10px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">🔄 Try Again</button>';
                echo '</div>';
            }
        } else {
            echo '<span style="color: red;">❌ API Error</span>';
            echo '</div>';

            echo '<div style="color:red; background: #ffe6e6; padding: 15px; border-radius: 4px;">';
            echo '<strong>❌ API Error Response</strong><br>';
            echo 'HTTP Code: ' . esc_html($http_code) . '<br>';
            if (!empty($body['message'])) {
                echo 'Error: ' . esc_html($body['message']) . '<br>';
            }
            echo '<details style="margin-top: 10px;"><summary>Full Response</summary>';
            echo '<pre style="background: #f8f8f8; padding: 10px; margin-top: 5px; border-radius: 4px; font-size: 12px; overflow-x: auto;">';
            echo esc_html($response_body);
            echo '</pre></details>';
            echo '<button onclick="location.reload()" style="margin-top: 10px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">🔄 Try Again</button>';
            echo '</div>';
        }

        wp_die();
    }

    /**
     * Normalize email for comparison
     */
    private function normalize_email($email) {
        return strtolower(trim($email));
    }

    /**
     * Show user-friendly 409 message
     */
    private function show_user_friendly_409_message($email) {
        echo '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #dee2e6;">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
        echo '<span style="font-size: 24px; margin-right: 10px;">👋</span>';
        echo '<h3 style="margin: 0; color: #495057;">Вітаємо назад!</h3>';
        echo '</div>';

        echo '<p style="color: #6c757d; margin-bottom: 15px;">Ваш акаунт з email <strong>' . esc_html($email) . '</strong> вже існує в нашій системі, але автоматично знайти його ID не вдалося.</p>';

        echo '<div style="background: #e7f3ff; padding: 15px; border-radius: 6px; border-left: 4px solid #0066cc; margin-bottom: 20px;">';
        echo '<p style="margin: 0; color: #0066cc;"><strong>💡 Що це означає?</strong><br>';
        echo 'Ви вже користувалися нашою системою раніше, але система не змогла автоматично знайти ваш профіль.</p>';
        echo '</div>';

        echo '<div style="margin-bottom: 20px;">';
        echo '<h4 style="color: #495057; margin-bottom: 10px;">🔄 Варіанти дій:</h4>';

        echo '<div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 10px;">';
        echo '<strong>1. Використати інший email</strong><br>';
        echo '<small style="color: #6c757d;">Скористайтеся іншою адресою email</small><br>';
        echo '<button onclick="document.getElementById(\'billing_email\').focus(); document.getElementById(\'billing_email\').select();" style="margin-top: 8px; padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">✏️ Змінити email</button>';
        echo '</div>';

        echo '<div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef;">';
        echo '<strong>2. Зв\'язатися з підтримкою</strong><br>';
        echo '<small style="color: #6c757d;">Ми швидко допоможемо знайти ваш акаунт</small><br>';
        echo '<div style="margin-top: 8px;">';
        echo '<span style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 12px;">Email: ' . esc_html($email) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Get country name from country code
     */
    private function get_country_name($country_code) {
        $countries = [
            'CA' => 'Canada',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'UA' => 'Ukraine',
        ];

        return $countries[$country_code] ?? 'Canada';
    }

    /**
     * Get province/state name
     */
    private function get_province_name($country_code, $state_code) {
        if ($country_code === 'CA') {
            $provinces = [
                'AB' => 'Alberta',
                'BC' => 'British Columbia',
                'MB' => 'Manitoba',
                'NB' => 'New Brunswick',
                'NL' => 'Newfoundland and Labrador',
                'NS' => 'Nova Scotia',
                'ON' => 'Ontario',
                'PE' => 'Prince Edward Island',
                'QC' => 'Quebec',
                'SK' => 'Saskatchewan',
                'NT' => 'Northwest Territories',
                'NU' => 'Nunavut',
                'YT' => 'Yukon',
            ];
            return $provinces[$state_code] ?? 'Ontario';
        }

        if ($country_code === 'US') {
            return $state_code ?: 'California';
        }

        return $state_code ?: 'Ontario';
    }
}

// Initialize iframe handler
new BNA_Iframe_Handler();