<?php
/**
 * BNA Payment Gateway
 * WooCommerce payment gateway integration for BNA Smart Payment with iframe
 *
 * @package BNA_Payment_Bridge
 * @subpackage WooCommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Payment Gateway class
 * Extends WooCommerce payment gateway functionality with real iframe integration
 */
class BNA_Bridge_Gateway extends WC_Payment_Gateway {

    /**
     * API integration instance
     *
     * @var BNA_Bridge_API_Integration
     */
    private $api_integration;

    /**
     * Scripts handler instance
     *
     * @var BNA_Bridge_Scripts_Handler
     */
    private $scripts_handler;

    /**
     * Gateway constructor
     * Initialize gateway settings and properties
     */
    public function __construct() {
        $this->id = 'bna_bridge';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('BNA Smart Payment', BNA_BRIDGE_TEXT_DOMAIN);
        $this->method_description = __('Accept payments via BNA Smart Payment iframe integration.', BNA_BRIDGE_TEXT_DOMAIN);

        // Support features
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');

        // API credentials
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');

        // Initialize components
        $this->init_api_integration();
        $this->init_scripts_handler();

        // Admin hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'update_api_settings'));
        
        // AJAX hooks for iframe loading
        add_action('wp_ajax_bna_bridge_load_iframe', array($this, 'ajax_load_iframe'));
        add_action('wp_ajax_nopriv_bna_bridge_load_iframe', array($this, 'ajax_load_iframe'));
    }
    
    /**
     * Initialize API integration
     * Create API integration instance with current settings
     */
    private function init_api_integration() {
        $settings = array(
            'access_key' => $this->access_key,
            'secret_key' => $this->secret_key,
            'iframe_id' => $this->iframe_id,
            'testmode' => $this->testmode ? 'yes' : 'no'
        );
        
        // Get API integration from main plugin
        $main_plugin = bna_payment_bridge();
        $this->api_integration = $main_plugin->get_api_integration($settings);
        
        BNA_Bridge_Helper::log("Gateway API integration initialized", 'debug');
    }

    /**
     * Initialize scripts handler
     * Create scripts handler and pass gateway settings
     */
    private function init_scripts_handler() {
        if (!class_exists('BNA_Bridge_Scripts_Handler')) {
            require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/core/class-scripts-handler.php';
        }
        
        $this->scripts_handler = new BNA_Bridge_Scripts_Handler();
        
        // Pass gateway settings to scripts handler
        $this->scripts_handler->set_gateway_settings(array(
            'testmode' => $this->testmode ? 'yes' : 'no',
            'gateway_id' => $this->id
        ));
        
        BNA_Bridge_Helper::log("Scripts handler initialized", 'debug');
    }

    /**
     * Initialize gateway form fields
     * Define admin settings fields for the gateway
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable BNA Smart Payment', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('Payment method title that customers will see on checkout.', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => __('Credit/Debit Card', BNA_BRIDGE_TEXT_DOMAIN),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'textarea',
                'description' => __('Payment method description that customers will see on checkout.', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => __('Pay securely with your credit or debit card.', BNA_BRIDGE_TEXT_DOMAIN),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test Mode', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode using staging API endpoints.', BNA_BRIDGE_TEXT_DOMAIN),
            ),
            'access_key' => array(
                'title' => __('Access Key', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('Your BNA Smart Payment Access Key.', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => __('Secret Key', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'password',
                'description' => __('Your BNA Smart Payment Secret Key.', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true,
            ),
            'iframe_id' => array(
                'title' => __('iFrame ID', BNA_BRIDGE_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('Your BNA Smart Payment iFrame ID from merchant portal.', BNA_BRIDGE_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Display payment fields
     * Show iframe container that will be populated by JavaScript
     */
    public function payment_fields() {
        // Show description if set
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        // Show test mode notice
        if ($this->testmode) {
            echo '<p style="color: #ff6600; font-weight: bold;">' . 
                 __('TEST MODE ENABLED - No real payments will be processed.', BNA_BRIDGE_TEXT_DOMAIN) . 
                 '</p>';
        }

        // Check if properly configured
        if (!$this->is_properly_configured()) {
            echo '<p style="color: red;">' . 
                 __('Payment gateway is not properly configured. Please contact site administrator.', BNA_BRIDGE_TEXT_DOMAIN) . 
                 '</p>';
            return;
        }

        // iframe container
        echo '<div id="bna-bridge-iframe-container" style="min-height: 500px; position: relative;">';
        echo '<div id="bna-bridge-iframe-loading" style="padding: 40px; text-align: center; color: #666;">';
        echo '<p>' . __('Loading secure payment form...', BNA_BRIDGE_TEXT_DOMAIN) . '</p>';
        echo '<div class="bna-loading-spinner"></div>';
        echo '</div>';
        echo '</div>';
        
        // Hidden field to track payment status
        echo '<input type="hidden" id="bna-bridge-payment-result" name="bna_bridge_payment_result" value="" />';
        echo '<input type="hidden" id="bna-bridge-transaction-id" name="bna_bridge_transaction_id" value="" />';
    }
    
    /**
     * Check if gateway is properly configured
     * Verify all required settings are present
     * 
     * @return bool True if properly configured
     */
    private function is_properly_configured() {
        return !empty($this->access_key) && 
               !empty($this->secret_key) && 
               !empty($this->iframe_id) &&
               $this->api_integration;
    }

    /**
     * Process payment
     * Handle payment processing after iframe completion
     * 
     * @param int $order_id Order ID
     * @return array Payment result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            BNA_Bridge_Helper::log("Order not found: {$order_id}", 'error');
            return array(
                'result' => 'failure',
                'messages' => __('Order not found.', BNA_BRIDGE_TEXT_DOMAIN)
            );
        }

        // Get payment result from hidden field
        $payment_result = sanitize_text_field($_POST['bna_bridge_payment_result'] ?? '');
        $transaction_id = sanitize_text_field($_POST['bna_bridge_transaction_id'] ?? '');

        BNA_Bridge_Helper::log("Processing payment for order #{$order_id}, result: {$payment_result}", 'info');

        if (empty($payment_result)) {
            $order->add_order_note(__('BNA payment failed: No payment result received.', BNA_BRIDGE_TEXT_DOMAIN));
            return array(
                'result' => 'failure',
                'messages' => __('Payment processing failed. Please try again.', BNA_BRIDGE_TEXT_DOMAIN)
            );
        }

        // Handle payment result
        switch ($payment_result) {
            case 'success':
                return $this->handle_successful_payment($order, $transaction_id);
                
            case 'failed':
                return $this->handle_failed_payment($order);
                
            default:
                return $this->handle_unknown_payment_result($order, $payment_result);
        }
    }

    /**
     * Handle successful payment
     * Process successful payment from iframe
     * 
     * @param WC_Order $order WooCommerce order
     * @param string $transaction_id BNA transaction ID
     * @return array Payment result
     */
    private function handle_successful_payment($order, $transaction_id) {
        // Mark order as processing/completed
        $order->payment_complete($transaction_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(__('BNA payment completed successfully. Transaction ID: %s', BNA_BRIDGE_TEXT_DOMAIN), $transaction_id)
        );

        // Update order meta
        $order->update_meta_data('_bna_transaction_id', $transaction_id);
        $order->update_meta_data('_bna_payment_method', 'iframe');
        $order->save();

        // Clear cart
        WC()->cart->empty_cart();

        BNA_Bridge_Helper::log("Payment successful for order #{$order->get_id()}, transaction: {$transaction_id}", 'info');

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Handle failed payment
     * Process failed payment from iframe
     * 
     * @param WC_Order $order WooCommerce order
     * @return array Payment result
     */
    private function handle_failed_payment($order) {
        $order->update_status('failed', __('BNA payment failed via iframe.', BNA_BRIDGE_TEXT_DOMAIN));
        
        BNA_Bridge_Helper::log("Payment failed for order #{$order->get_id()}", 'warning');

        return array(
            'result' => 'failure',
            'messages' => __('Payment was declined. Please try again or use a different payment method.', BNA_BRIDGE_TEXT_DOMAIN)
        );
    }

    /**
     * Handle unknown payment result
     * Process unknown payment result from iframe
     * 
     * @param WC_Order $order WooCommerce order
     * @param string $result Payment result
     * @return array Payment result
     */
    private function handle_unknown_payment_result($order, $result) {
        $order->add_order_note(
            sprintf(__('BNA payment unknown result: %s', BNA_BRIDGE_TEXT_DOMAIN), $result)
        );
        
        BNA_Bridge_Helper::log("Unknown payment result for order #{$order->get_id()}: {$result}", 'warning');

        return array(
            'result' => 'failure',
            'messages' => __('Payment processing encountered an error. Please try again.', BNA_BRIDGE_TEXT_DOMAIN)
        );
    }

    /**
     * AJAX handler for iframe loading
     * Generate token and return iframe URL
     */
    public function ajax_load_iframe() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bna_bridge_checkout')) {
            wp_die(__('Security check failed', BNA_BRIDGE_TEXT_DOMAIN));
        }

        if (!$this->api_integration) {
            wp_send_json_error(array(
                'message' => __('Payment gateway not properly configured.', BNA_BRIDGE_TEXT_DOMAIN)
            ));
        }

        // Get customer data from checkout form
        $customer_data = $this->extract_customer_data($_POST);
        
        if (is_wp_error($customer_data)) {
            wp_send_json_error(array(
                'message' => $customer_data->get_error_message()
            ));
        }

        // Prepare checkout data
        $checkout_data = $this->api_integration->prepare_checkout_data(null, $customer_data);
        
        if (empty($checkout_data)) {
            wp_send_json_error(array(
                'message' => __('Unable to prepare checkout data.', BNA_BRIDGE_TEXT_DOMAIN)
            ));
        }

        // Generate token
        $token_result = $this->api_integration->generate_iframe_token($checkout_data);
        
        if (is_wp_error($token_result)) {
            BNA_Bridge_Helper::log("Token generation failed: " . $token_result->get_error_message(), 'error');
            wp_send_json_error(array(
                'message' => __('Unable to initialize payment form. Please try again.', BNA_BRIDGE_TEXT_DOMAIN)
            ));
        }

        // Return iframe URL
        wp_send_json_success(array(
            'iframe_url' => $this->api_integration->get_iframe_url($token_result['token']),
            'token' => $token_result['token'],
            'expires_at' => $token_result['expires_at'],
            'from_cache' => $token_result['from_cache']
        ));
    }

    /**
     * Extract customer data from POST request
     * Parse customer information from checkout form
     * 
     * @param array $post_data POST data from checkout form
     * @return array|WP_Error Customer data or error
     */
    private function extract_customer_data($post_data) {
        // Extract required fields
        $required_fields = array('billing_email', 'billing_first_name', 'billing_last_name');
        
        foreach ($required_fields as $field) {
            if (empty($post_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Required field %s is missing.', BNA_BRIDGE_TEXT_DOMAIN), $field));
            }
        }

        // Build customer info
        $customer_info = array(
            'type' => 'Personal',
            'email' => sanitize_email($post_data['billing_email']),
            'firstName' => sanitize_text_field($post_data['billing_first_name']),
            'lastName' => sanitize_text_field($post_data['billing_last_name']),
            'phoneCode' => '+1', // Default, can be enhanced
            'phoneNumber' => preg_replace('/[^0-9]/', '', $post_data['billing_phone'] ?? ''),
            'address' => array(
                'streetName' => sanitize_text_field($post_data['billing_address_1'] ?? ''),
                'streetNumber' => '',
                'apartment' => sanitize_text_field($post_data['billing_address_2'] ?? ''),
                'city' => sanitize_text_field($post_data['billing_city'] ?? ''),
                'province' => sanitize_text_field($post_data['billing_state'] ?? ''),
                'country' => sanitize_text_field($post_data['billing_country'] ?? ''),
                'postalCode' => sanitize_text_field($post_data['billing_postcode'] ?? '')
            )
        );

        // Get cart total and items
        $cart_total = WC()->cart->get_subtotal();
        $cart_items = $this->format_cart_items();

        return array(
            'customer_info' => $customer_info,
            'items' => $cart_items,
            'subtotal' => $cart_total
        );
    }

    /**
     * Format cart items for API
     * Convert WooCommerce cart to BNA API format
     * 
     * @return array Formatted cart items
     */
    private function format_cart_items() {
        $items = array();
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            $items[] = array(
                'description' => $product->get_name(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => (float) $product->get_price(),
                'quantity' => (int) $cart_item['quantity'],
                'amount' => (float) ($product->get_price() * $cart_item['quantity'])
            );
        }
        
        return $items;
    }

    /**
     * Update API settings
     * Reinitialize API integration when settings are updated
     */
    public function update_api_settings() {
        $this->init_api_integration();
        BNA_Bridge_Helper::log("API settings updated for gateway", 'info');
    }

    /**
     * Check if gateway is available
     * Determine if gateway should be available for use
     * 
     * @return bool True if available
     */
    public function is_available() {
        // Check if enabled
        if ('no' === $this->enabled) {
            return false;
        }

        // Check if WooCommerce is active
        if (!BNA_Bridge_Helper::is_woocommerce_active()) {
            return false;
        }

        // For development, allow gateway even without full configuration
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return parent::is_available();
        }

        // Check if properly configured
        if (!$this->is_properly_configured()) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Admin options output
     * Display admin configuration form
     */
    public function admin_options() {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<p>' . __('Configure your BNA Smart Payment integration settings below.', BNA_BRIDGE_TEXT_DOMAIN) . '</p>';
        
        // Display connection status if configured
        if ($this->is_properly_configured()) {
            $this->display_connection_status();
        }
        
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        // Add connection test button
        $this->display_connection_test_button();
    }

    /**
     * Display connection status
     * Show current API connection status in admin
     */
    private function display_connection_status() {
        echo '<div id="bna-bridge-connection-status" style="margin: 15px 0;">';
        echo '<p><strong>' . __('Connection Status:', BNA_BRIDGE_TEXT_DOMAIN) . '</strong> ';
        echo '<span id="bna-connection-indicator">' . __('Click "Test Connection" to verify', BNA_BRIDGE_TEXT_DOMAIN) . '</span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Display connection test button
     * Add test connection functionality in admin
     */
    private function display_connection_test_button() {
        echo '<p>';
        echo '<button type="button" id="bna-bridge-test-connection" class="button-secondary">' . 
             __('Test Connection', BNA_BRIDGE_TEXT_DOMAIN) . '</button> ';
        echo '<span id="bna-test-result"></span>';
        echo '</p>';
        
        echo '<div id="bna-bridge-debug-info" style="margin-top: 20px; display: none;">';
        echo '<h4>' . __('Debug Information', BNA_BRIDGE_TEXT_DOMAIN) . '</h4>';
        echo '<textarea id="bna-debug-output" rows="8" cols="80" readonly></textarea>';
        echo '</div>';
    }
}
