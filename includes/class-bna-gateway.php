<?php
/**
 * BNA Gateway Class
 * Main WooCommerce payment gateway integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Gateway
 * WooCommerce payment gateway for BNA Smart Payment System
 */
class BNA_Gateway extends WC_Payment_Gateway {

    private $api;
    private $validator;
    private $order_handler;

    /**
     * Constructor - setup gateway properties and hooks
     */
    public function __construct() {
        $this->id = 'bna_gateway';

        // Set icon directly - ВИПРАВЛЕНО
        $this->icon = BNA_PLUGIN_URL . 'public/assets/images/bna-logo.png';

        $this->has_fields = true;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments via BNA Smart Payment System using secure iFrame';
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Gateway properties
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');

        // Initialize components - БЕЗПЕЧНО
        if (class_exists('BNA_Api')) {
            $this->api = BNA_Api::get_instance();
        }
        if (class_exists('BNA_Validator')) {
            $this->validator = BNA_Validator::get_instance();
        }
        if (class_exists('BNA_Order')) {
            $this->order_handler = BNA_Order::get_instance();
        }

        // Hooks - БЕЗ woocommerce_gateway_icon фільтра!
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_' . $this->id, array($this, 'handle_webhook'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Initialize gateway form fields for admin
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable BNA Smart Payment',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title that customers see during checkout',
                'default' => 'BNA Smart Payment',
                'desc_tip' => true
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description that customers see during checkout',
                'default' => 'Pay securely using credit card, debit card, or e-transfer',
                'desc_tip' => true
            ),
            'api_environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'description' => 'Select API environment',
                'default' => 'staging',
                'options' => array(
                    'staging' => 'Staging',
                    'production' => 'Production'
                ),
                'desc_tip' => true
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your BNA API access key',
                'desc_tip' => true
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your BNA API secret key',
                'desc_tip' => true
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your BNA iFrame ID for checkout integration',
                'desc_tip' => true
            ),
            'payment_methods' => array(
                'title' => 'Payment Methods',
                'type' => 'multiselect',
                'description' => 'Select available payment methods',
                'default' => array('card', 'eft'),
                'options' => array(
                    'card' => 'Credit/Debit Card',
                    'eft' => 'Electronic Funds Transfer',
                    'e-transfer' => 'E-Transfer'
                ),
                'desc_tip' => true
            ),
            'customer_types' => array(
                'title' => 'Customer Types',
                'type' => 'multiselect',
                'description' => 'Select available customer types',
                'default' => array('Personal', 'Business'),
                'options' => array(
                    'Personal' => 'Personal',
                    'Business' => 'Business'
                ),
                'desc_tip' => true
            ),
            'enable_fees' => array(
                'title' => 'Enable Fees',
                'type' => 'checkbox',
                'label' => 'Apply processing fees based on payment method',
                'default' => 'no'
            ),
            'card_fee_flat' => array(
                'title' => 'Card Fee (Flat)',
                'type' => 'decimal',
                'description' => 'Flat fee for card payments',
                'default' => '0.00',
                'desc_tip' => true
            ),
            'card_fee_percent' => array(
                'title' => 'Card Fee (Percentage)',
                'type' => 'decimal',
                'description' => 'Percentage fee for card payments',
                'default' => '2.9',
                'desc_tip' => true
            ),
            'eft_fee_flat' => array(
                'title' => 'EFT Fee (Flat)',
                'type' => 'decimal',
                'description' => 'Flat fee for EFT payments',
                'default' => '1.25',
                'desc_tip' => true
            ),
            'eft_fee_percent' => array(
                'title' => 'EFT Fee (Percentage)',
                'type' => 'decimal',
                'description' => 'Percentage fee for EFT payments',
                'default' => '0.00',
                'desc_tip' => true
            ),
            'etransfer_fee_flat' => array(
                'title' => 'E-Transfer Fee (Flat)',
                'type' => 'decimal',
                'description' => 'Flat fee for e-transfer payments',
                'default' => '1.50',
                'desc_tip' => true
            ),
            'iframe_timeout' => array(
                'title' => 'iFrame Timeout',
                'type' => 'number',
                'description' => 'iFrame timeout in seconds',
                'default' => '300',
                'desc_tip' => true
            ),
            'debug_mode' => array(
                'title' => 'Debug Mode',
                'type' => 'checkbox',
                'label' => 'Enable debug logging',
                'default' => 'no',
                'description' => 'Log gateway events for debugging'
            )
        );
    }

    /**
     * Check if gateway is available
     * @return bool
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        // Check API credentials
        if ($this->api && !$this->api->has_credentials()) {
            return false;
        }

        // Check currency support
        if (!in_array(get_woocommerce_currency(), array('CAD', 'USD'))) {
            return false;
        }

        return true;
    }

    /**
     * Process payment
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return array(
                'result' => 'failure',
                'messages' => 'Order not found'
            );
        }

        try {
            // Validate payment data
            $validation_result = $this->validate_payment_data($order);
            if (is_wp_error($validation_result)) {
                throw new Exception($validation_result->get_error_message());
            }

            // Get payment method from POST data
            $payment_method = sanitize_text_field($_POST['bna_payment_method'] ?? 'card');
            $customer_type = sanitize_text_field($_POST['bna_customer_type'] ?? 'Personal');

            // Prepare customer data
            $customer_data = $this->prepare_customer_data($order, $customer_type);

            // Prepare order data
            $order_data = $this->prepare_order_data($order);

            // Create iFrame token
            if ($this->api) {
                $token_response = $this->api->create_iframe_token($customer_data, $order_data);

                if (is_wp_error($token_response)) {
                    throw new Exception('Failed to create payment token: ' . $token_response->get_error_message());
                }
            } else {
                throw new Exception('API not available');
            }

            // Save transaction data
            $transaction_data = array(
                'transaction_token' => $token_response['token'],
                'payment_method' => $payment_method,
                'customer_type' => $customer_type,
                'iframe_url' => $token_response['iframe_url'] ?? '',
                'status' => 'pending'
            );

            if ($this->order_handler) {
                $this->order_handler->save_transaction($order_id, $transaction_data);
            }

            // Update order status
            $order->update_status('pending', 'Awaiting BNA payment confirmation');

            // Save order meta
            $order->update_meta_data('_bna_payment_method', $payment_method);
            $order->update_meta_data('_bna_customer_type', $customer_type);
            $order->update_meta_data('_bna_transaction_token', $token_response['token']);
            $order->save();

            if (class_exists('BNA_Logger')) {
                BNA_Logger::info('Payment processing initiated', array(
                    'order_id' => $order_id,
                    'payment_method' => $payment_method
                ), 'gateway');
            }

            // Return success with redirect to payment page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );

        } catch (Exception $e) {
            if (class_exists('BNA_Logger')) {
                BNA_Logger::error('Payment processing failed', array(
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ), 'gateway');
            }

            wc_add_notice($e->getMessage(), 'error');

            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * Process refund
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found');
        }

        if (!$amount) {
            $amount = $order->get_total();
        }

        if ($this->order_handler) {
            return $this->order_handler->process_refund($order, $amount, $reason);
        }

        return new WP_Error('handler_not_available', 'Order handler not available');
    }

    /**
     * Display payment fields on checkout
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        $payment_methods = $this->get_option('payment_methods', array('card', 'eft'));
        $customer_types = $this->get_option('customer_types', array('Personal', 'Business'));

        echo '<div class="bna-payment-fields">';

        // Customer type selection
        if (count($customer_types) > 1) {
            echo '<p class="form-row form-row-wide">';
            echo '<label for="bna_customer_type">Customer Type <span class="required">*</span></label>';
            echo '<select id="bna_customer_type" name="bna_customer_type" class="select">';
            foreach ($customer_types as $type) {
                echo '<option value="' . esc_attr($type) . '">' . esc_html($type) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        } else {
            echo '<input type="hidden" name="bna_customer_type" value="' . esc_attr($customer_types[0]) . '">';
        }

        // Payment method selection
        if (count($payment_methods) > 1) {
            echo '<p class="form-row form-row-wide">';
            echo '<label for="bna_payment_method">Payment Method <span class="required">*</span></label>';
            echo '<select id="bna_payment_method" name="bna_payment_method" class="select">';

            $method_labels = array(
                'card' => 'Credit/Debit Card',
                'eft' => 'Electronic Funds Transfer',
                'e-transfer' => 'E-Transfer'
            );

            foreach ($payment_methods as $method) {
                $label = $method_labels[$method] ?? ucfirst($method);
                echo '<option value="' . esc_attr($method) . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        } else {
            echo '<input type="hidden" name="bna_payment_method" value="' . esc_attr($payment_methods[0]) . '">';
        }

        // Phone code field
        echo '<p class="form-row form-row-wide">';
        echo '<label for="bna_phone_code">Phone Country Code</label>';
        echo '<select id="bna_phone_code" name="bna_phone_code" class="select">';
        echo '<option value="+1">Canada/US (+1)</option>';
        echo '<option value="+44">UK (+44)</option>';
        echo '<option value="+33">France (+33)</option>';
        echo '<option value="+49">Germany (+49)</option>';
        echo '</select>';
        echo '</p>';

        // Terms and conditions
        echo '<p class="form-row form-row-wide">';
        echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
        echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="bna_terms" id="bna_terms" required>';
        echo '<span class="woocommerce-form__label-text">I agree to the payment processing terms</span>';
        echo '</label>';
        echo '</p>';

        echo '</div>';
    }

    /**
     * Validate payment fields
     * @return bool
     */
    public function validate_fields() {
        $errors = array();

        // Validate customer type
        $customer_type = sanitize_text_field($_POST['bna_customer_type'] ?? '');
        if (empty($customer_type)) {
            $errors[] = 'Customer type is required';
        }

        // Validate payment method
        $payment_method = sanitize_text_field($_POST['bna_payment_method'] ?? '');
        if (empty($payment_method)) {
            $errors[] = 'Payment method is required';
        }

        // Validate terms acceptance
        if (!isset($_POST['bna_terms'])) {
            $errors[] = 'You must agree to the payment processing terms';
        }

        // Add errors to WooCommerce
        foreach ($errors as $error) {
            wc_add_notice($error, 'error');
        }

        return empty($errors);
    }

    /**
     * Load payment scripts
     */
    public function payment_scripts() {
        if (!is_checkout() || !$this->is_available()) {
            return;
        }

        wp_enqueue_script(
            'bna-checkout',
            BNA_PLUGIN_URL . 'public/assets/js/checkout.js',
            array('jquery', 'wc-checkout'),
            BNA_PLUGIN_VERSION,
            true
        );

        wp_localize_script('bna-checkout', 'bna_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'checkout_nonce' => wp_create_nonce('bna_checkout'),
            'enable_fees' => $this->get_option('enable_fees') === 'yes',
            'fees' => array(
                'card_flat' => (float) $this->get_option('card_fee_flat', 0),
                'card_percent' => (float) $this->get_option('card_fee_percent', 0),
                'eft_flat' => (float) $this->get_option('eft_fee_flat', 0),
                'eft_percent' => (float) $this->get_option('eft_fee_percent', 0),
                'etransfer_flat' => (float) $this->get_option('etransfer_fee_flat', 0)
            )
        ));

        wp_enqueue_style(
            'bna-checkout',
            BNA_PLUGIN_URL . 'public/assets/css/checkout.css',
            array(),
            BNA_PLUGIN_VERSION
        );
    }

    /**
     * Handle webhook notifications
     */
    public function handle_webhook() {
        $raw_body = file_get_contents('php://input');
        $data = json_decode($raw_body, true);

        if (!$data) {
            if (class_exists('BNA_Logger')) {
                BNA_Logger::error('Invalid webhook data received', array(), 'webhook');
            }
            wp_die('Invalid data', 'Webhook Error', array('response' => 400));
        }

        // Verify webhook signature if configured
        if (!$this->verify_webhook_signature($raw_body)) {
            if (class_exists('BNA_Logger')) {
                BNA_Logger::error('Webhook signature verification failed', array(), 'webhook');
            }
            wp_die('Unauthorized', 'Webhook Error', array('response' => 401));
        }

        // Process webhook
        $this->process_webhook($data);

        wp_die('OK', 'Webhook Success', array('response' => 200));
    }

    /**
     * Prepare customer data for API
     * @param WC_Order $order
     * @param string $customer_type
     * @return array
     */
    private function prepare_customer_data($order, $customer_type) {
        $phone_code = sanitize_text_field($_POST['bna_phone_code'] ?? '+1');

        return array(
            'type' => $customer_type,
            'email' => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'phone' => $order->get_billing_phone(),
            'phone_code' => $phone_code,
            'street_name' => $order->get_billing_address_1(),
            'street_number' => '', // Could be parsed from address_1
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'country' => $order->get_billing_country(),
            'postcode' => $order->get_billing_postcode()
        );
    }

    /**
     * Prepare order data for API
     * @param WC_Order $order
     * @return array
     */
    private function prepare_order_data($order) {
        $items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'price' => $item->get_subtotal() / $item->get_quantity(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total()
            );
        }

        return array(
            'items' => $items,
            'total' => $order->get_total(),
            'currency' => $order->get_currency()
        );
    }

    /**
     * Validate payment data
     * @param WC_Order $order
     * @return bool|WP_Error
     */
    private function validate_payment_data($order) {
        // Validate order amount
        if ($this->validator && !$this->validator->validate_amount($order->get_total(), $order->get_currency())) {
            return new WP_Error('invalid_amount', 'Invalid order amount');
        }

        // Validate customer email
        if ($this->validator && !$this->validator->validate_email($order->get_billing_email())) {
            return new WP_Error('invalid_email', 'Invalid customer email');
        }

        // Validate billing phone
        if ($this->validator && !$this->validator->validate_phone($order->get_billing_phone())) {
            return new WP_Error('invalid_phone', 'Invalid customer phone number');
        }

        return true;
    }

    /**
     * Verify webhook signature
     * @param string $payload
     * @return bool
     */
    private function verify_webhook_signature($payload) {
        $webhook_secret = $this->get_option('webhook_secret');

        if (empty($webhook_secret)) {
            return true; // Skip verification if no secret configured
        }

        $signature = $_SERVER['HTTP_X_BNA_SIGNATURE'] ?? '';
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

        return hash_equals($expected_signature, $signature);
    }

    /**
     * Process webhook data
     * @param array $data
     */
    private function process_webhook($data) {
        $transaction_token = $data['transaction_token'] ?? '';
        $status = $data['status'] ?? '';

        if (empty($transaction_token)) {
            if (class_exists('BNA_Logger')) {
                BNA_Logger::error('Webhook missing transaction token', $data, 'webhook');
            }
            return;
        }

        // Find order by transaction token
        if ($this->order_handler) {
            $order = $this->order_handler->get_order_by_transaction($transaction_token);

            if (!$order) {
                if (class_exists('BNA_Logger')) {
                    BNA_Logger::error('Order not found for transaction', array(
                        'transaction_token' => $transaction_token
                    ), 'webhook');
                }
                return;
            }

            // Update order status
            $this->order_handler->update_order_status($order->get_id(), $status, $data);

            if (class_exists('BNA_Logger')) {
                BNA_Logger::info('Webhook processed successfully', array(
                    'order_id' => $order->get_id(),
                    'status' => $status
                ), 'webhook');
            }
        }
    }

    /**
     * Admin options page
     */
    public function admin_options() {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<p>' . esc_html($this->get_method_description()) . '</p>';

        // Test API connection
        if ($this->api && $this->api->has_credentials()) {
            $connection_status = $this->api->test_connection();
            $status_class = $connection_status ? 'notice-success' : 'notice-error';
            $status_text = $connection_status ? 'Connected' : 'Connection Failed';

            echo '<div class="notice ' . $status_class . ' inline"><p><strong>API Status:</strong> ' . $status_text . '</p></div>';
        }

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }
}

