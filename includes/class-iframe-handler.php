 м<?php
 /**
  * BNA Iframe Handler Class (proper customer ID logic like old plugin)
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

         $base_url = match ($environment) {
             'https://production-api-service.bnasmartpayment.com' => 'https://api.bnasmartpayment.com',
             'https://dev-api-service.bnasmartpayment.com' => 'https://stage-api-service.bnasmartpayment.com',
             default => 'https://stage-api-service.bnasmartpayment.com',
         };

         // Get customer data
         $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
         $first_name = isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '';
         $last_name = isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '';
         $post_code = isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '';
         $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
         $city = isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '';
         $country = isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '';

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

         // STEP 1: Check if we have stored customer ID (like payorID in old plugin)
         $stored_customer_id = null;

         if (is_user_logged_in()) {
             // For logged in users, check user meta
             $stored_customer_id = get_user_meta(get_current_user_id(), 'bna_payorID', true);
         } else {
             // For guest users, check by email in our custom table or transient
             // For now, let's use transients based on email
             $stored_customer_id = get_transient('bna_customer_' . md5($email));
         }

         echo '<div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 10px;">';
         echo '<h3>🔍 Customer Analysis</h3>';
         echo '<p><strong>Email:</strong> ' . esc_html($email) . '</p>';
         echo '<p><strong>Stored Customer ID:</strong> ' . (!empty($stored_customer_id) ? esc_html($stored_customer_id) : 'None found') . '</p>';

         // Build payload
         $payload = [
             'iframeId' => $iframe_id,
             'items' => [
                 [
                     'description' => 'Test Item',
                     'sku' => 'TEST-001',
                     'price' => 1.00,
                     'quantity' => 1,
                     'amount' => 1.00,
                 ]
             ],
             'subtotal' => 1.00,
         ];

         // STEP 2: Decide whether to use customer ID or create new customer
         if (!empty($stored_customer_id)) {
             // Use existing customer ID
             $payload['customerId'] = $stored_customer_id;
             echo '<p><strong>Strategy:</strong> 🔄 Using existing customer ID</p>';
         } else {
             // Create new customer
             $payload['customerInfo'] = [
                 'type' => 'Personal',
                 'email' => $email,
                 'firstName' => $first_name,
                 'lastName' => $last_name,
                 'phoneNumber' => $phone ?: '1234567890',
                 'phoneCode' => '+1',
                 'birthDate' => '1990-01-01',
                 'address' => [
                     'streetName' => 'Main Street',
                     'streetNumber' => '123',
                     'city' => $city ?: 'Test City',
                     'province' => 'Test Province',
                     'country' => $country ?: 'US',
                     'postalCode' => $post_code ?: '12345',
                 ],
             ];
             echo '<p><strong>Strategy:</strong> 👤 Creating new customer</p>';
         }

         echo '<p><strong>Payload Keys:</strong> ' . implode(', ', array_keys($payload)) . '</p>';
         echo '</div>';

         // STEP 3: Make API request
         $response = wp_remote_post("{$base_url}/v1/checkout", [
             'timeout' => 30,
             'headers' => [
                 'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                 'Content-Type' => 'application/json',
             ],
             'body' => json_encode($payload),
         ]);

         if (is_wp_error($response)) {
             echo '<div style="color:red;">❌ Connection Error: ' . esc_html($response->get_error_message()) . '</div>';
             wp_die();
         }

         $http_code = wp_remote_retrieve_response_code($response);
         $response_body = wp_remote_retrieve_body($response);
         $body = json_decode($response_body, true);

         echo '<div style="background: #f0f8ff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
         echo '<strong>API Response:</strong> HTTP ' . esc_html($http_code) . '<br>';

         // STEP 4: Handle different response scenarios
         if ($http_code === 409 && !empty($stored_customer_id)) {
             // Stored customer ID is invalid, clear it and try creating new customer
             echo '<span style="color: orange;">Customer ID invalid, clearing and retrying...</span>';
             echo '</div>';

             // Clear invalid customer ID
             if (is_user_logged_in()) {
                 delete_user_meta(get_current_user_id(), 'bna_payorID');
             } else {
                 delete_transient('bna_customer_' . md5($email));
             }

             // Retry with new customer creation
             $payload = [
                 'iframeId' => $iframe_id,
                 'customerInfo' => [
                     'type' => 'Personal',
                     'email' => $email,
                     'firstName' => $first_name,
                     'lastName' => $last_name,
                     'phoneNumber' => $phone ?: '1234567890',
                     'phoneCode' => '+1',
                     'birthDate' => '1990-01-01',
                     'address' => [
                         'streetName' => 'Main Street',
                         'streetNumber' => '123',
                         'city' => $city ?: 'Test City',
                         'province' => 'Test Province',
                         'country' => $country ?: 'US',
                         'postalCode' => $post_code ?: '12345',
                     ],
                 ],
                 'items' => [
                     [
                         'description' => 'Test Item',
                         'sku' => 'TEST-001',
                         'price' => 1.00,
                         'quantity' => 1,
                         'amount' => 1.00,
                     ]
                 ],
                 'subtotal' => 1.00,
             ];

             $response = wp_remote_post("{$base_url}/v1/checkout", [
                 'timeout' => 30,
                 'headers' => [
                     'Authorization' => 'Basic ' . base64_encode("{$access_key}:{$secret_key}"),
                     'Content-Type' => 'application/json',
                 ],
                 'body' => json_encode($payload),
             ]);

             $http_code = wp_remote_retrieve_response_code($response);
             $response_body = wp_remote_retrieve_body($response);
             $body = json_decode($response_body, true);

             echo '<div style="background: #f0f8ff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
             echo '<strong>Retry Response:</strong> HTTP ' . esc_html($http_code) . '<br>';
         }

         if ($http_code === 409) {
             // Customer exists but we don't have the ID - this is the core problem
             echo '<span style="color: red;">Customer exists but we don\'t have their ID</span><br>';
             echo '<strong>Solution needed:</strong> Get customer ID from BNA or use different approach';
             echo '</div>';

             echo '<div style="color: orange; background: #fff3cd; padding: 15px; border-radius: 4px;">';
             echo '⚠️ <strong>Customer Management Issue</strong><br>';
             echo 'Email ' . esc_html($email) . ' already exists in BNA system.<br>';
             echo '<strong>Options:</strong><br>';
             echo '1. Contact BNA support to get customer ID for this email<br>';
             echo '2. Use BNA customer management API to retrieve customer ID<br>';
             echo '3. Try with a completely new email address<br>';
             echo '</div>';
             wp_die();
         }

         if (!empty($body['token'])) {
             echo '<span style="color: green;">✅ Success! Token received</span>';
             echo '</div>';

             // STEP 5: Save customer ID for future use (like old plugin)
             if (!empty($body['customerId'])) {
                 if (is_user_logged_in()) {
                     update_user_meta(get_current_user_id(), 'bna_payorID', $body['customerId']);
                 } else {
                     set_transient('bna_customer_' . md5($email), $body['customerId'], WEEK_IN_SECONDS);
                 }
                 echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                 echo '💾 <strong>Saved customer ID:</strong> ' . esc_html($body['customerId']) . ' for future use';
                 echo '</div>';
             }

             echo '<div style="background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
             echo '✅ <strong>Payment form loaded successfully!</strong><br>';
             echo '<small>Token: ' . esc_html(substr($body['token'], 0, 16)) . '...</small>';
             echo '</div>';

             echo '<iframe src="' . esc_url("{$base_url}/v1/checkout/{$body['token']}") . '" width="100%" height="600" style="border:none;margin-top:20px;"></iframe>';
         } else {
             echo '<span style="color: red;">❌ No token received</span>';
             echo '</div>';

             echo '<div style="color:red; background: #ffe6e6; padding: 10px; border-radius: 4px;">';
             echo '<strong>Full Response:</strong><br>' . esc_html($response_body);
             echo '</div>';
         }

         wp_die();
     }
 }

 // Initialize iframe handler
 new BNA_Iframe_Handler();