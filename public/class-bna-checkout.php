<?php
/**
 * BNA Checkout Frontend Class
 * Handles frontend checkout functionality and user interface
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Checkout
 * Manages frontend checkout experience and iFrame integration
 */
class BNA_Checkout {

    private static $instance = null;
    private $gateway_settings;

    /**
     * Get singleton instance
     * @return BNA_Checkout
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup hooks and load settings
     */
    private function __construct() {
        $this->gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    public function init() {
        $this->init_hooks();
    }

    /**
     * Setup all WordPress hooks
     */
    private function init_hooks() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
        
        // WooCommerce checkout hooks
        add_action('woocommerce_checkout_before_order_review', array($this, 'add_checkout_notices'));
        add_action('woocommerce_checkout_after_order_review', array($this, 'add_iframe_container'));
        
        // Payment processing hooks
        add_action('woocommerce_checkout_process', array($this, 'process_checkout_validation'));
        add_action('woocommerce_before_checkout_form', array($this, 'check_gateway_availability'));
        
        // Order received page
        add_action('woocommerce_thankyou_bna_gateway', array($this, 'display_payment_confirmation'));
        
        // My Account integration
        add_action('woocommerce_account_dashboard', array($this, 'add_account_payment_info'));
        
        // AJAX endpoints for frontend
        add_action('wp_ajax_bna_get_checkout_data', array($this, 'get_checkout_data'));
        add_action('wp_ajax_nopriv_bna_get_checkout_data', array($this, 'get_checkout_data'));
        
        // Custom checkout fields
        add_filter('woocommerce_checkout_fields', array($this, 'add_custom_checkout_fields'));
        
        // Form validation
        add_action('woocommerce_checkout_posted_data', array($this, 'validate_custom_fields'));
    }

    /**
     * Enqueue checkout assets (CSS and JS)
     */
    public function enqueue_checkout_assets() {
        // Only load on checkout and cart pages
        if (!is_checkout() && !is_cart()) {
            return;
        }

        // Check if BNA gateway is available
        if (!$this->is_bna_gateway_available()) {
            return;
        }

        $version = BNA_PLUGIN_VERSION;
        $plugin_url = BNA_PLUGIN_URL;

        // Enqueue CSS files
        wp_enqueue_style(
            'bna-checkout-css',
            $plugin_url . 'public/assets/css/checkout.css',
            array(),
            $version
        );

        wp_enqueue_style(
            'bna-payment-methods-css',
            $plugin_url . 'public/assets/css/payment-methods.css',
            array(),
            $version
        );

        // Enqueue JavaScript files (modular approach)
        wp_enqueue_script(
            'bna-utils',
            $plugin_url . 'public/assets/js/utils.js',
            array('jquery'),
            $version,
            true
        );

        wp_enqueue_script(
            'bna-form-validator',
            $plugin_url . 'public/assets/js/form-validator.js',
            array('jquery', 'bna-utils'),
            $version,
            true
        );

        wp_enqueue_script(
            'bna-fee-calculator',
            $plugin_url . 'public/assets/js/fee-calculator.js',
            array('jquery', 'bna-utils'),
            $version,
            true
        );

        wp_enqueue_script(
            'bna-payment-methods',
            $plugin_url . 'public/assets/js/payment-methods.js',
            array('jquery', 'bna-utils'),
            $version,
            true
        );

        wp_enqueue_script(
            'bna-iframe-handler',
            $plugin_url . 'public/assets/js/iframe-handler.js',
            array('jquery', 'bna-utils'),
            $version,
            true
        );

        wp_enqueue_script(
            'bna-checkout',
            $plugin_url . 'public/assets/js/checkout.js',
            array('jquery', 'wc-checkout', 'bna-utils', 'bna-iframe-handler', 'bna-fee-calculator'),
            $version,
            true
        );

        // Localize main checkout script with data
        wp_localize_script('bna-checkout', 'bna_checkout_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'wc_ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
            'checkout_nonce' => wp_create_nonce('bna_checkout'),
            'site_url' => site_url(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'currency_code' => get_woocommerce_currency(),
            'debug_mode' => $this->gateway_settings['debug_mode'] === 'yes',
            'iframe_timeout' => intval($this->gateway_settings['iframe_timeout'] ?? 300),
            'enable_fees' => $this->gateway_settings['enable_fees'] === 'yes',
            'payment_methods' => $this->gateway_settings['payment_methods'] ?? array('card', 'eft'),
            'customer_types' => $this->gateway_settings['customer_types'] ?? array('Personal', 'Business'),
            'messages' => array(
                'processing' => __('Processing payment...', 'bna-payment-gateway'),
                'iframe_loading' => __('Loading payment form...', 'bna-payment-gateway'),
                'iframe_timeout' => __('Payment form timed out. Please try again.', 'bna-payment-gateway'),
                'validation_error' => __('Please correct the errors below.', 'bna-payment-gateway'),
                'payment_failed' => __('Payment failed. Please try again.', 'bna-payment-gateway'),
                'connection_error' => __('Connection error. Please check your internet connection.', 'bna-payment-gateway')
            )
        ));

        // Localize iframe handler with specific params
        wp_localize_script('bna-iframe-handler', 'bna_iframe_params', array(
            'iframe_container_id' => 'bna-iframe-container',
            'iframe_loader_id' => 'bna-iframe-loader',
            'status_check_interval' => 3000, // 3 seconds
            'max_status_checks' => 100, // 5 minutes max
            'iframe_height' => '600px',
            'iframe_width' => '100%'
        ));

        // Localize fee calculator
        wp_localize_script('bna-fee-calculator', 'bna_fee_params', array(
            'fees' => array(
                'card_flat' => floatval($this->gateway_settings['card_fee_flat'] ?? 0),
                'card_percent' => floatval($this->gateway_settings['card_fee_percent'] ?? 0),
                'eft_flat' => floatval($this->gateway_settings['eft_fee_flat'] ?? 0),
                'eft_percent' => floatval($this->gateway_settings['eft_fee_percent'] ?? 0),
                'etransfer_flat' => floatval($this->gateway_settings['etransfer_fee_flat'] ?? 0)
            ),
            'tax_rate' => 0.13, // HST for Canada
            'fee_display_selector' => '.bna-fee-display',
            'total_display_selector' => '.order-total .amount'
        ));
    }

    /**
     * Add checkout notices and warnings
     */
    public function add_checkout_notices() {
        if (!$this->is_bna_gateway_selected()) {
            return;
        }

        // Check for SSL if in production
        if ($this->gateway_settings['api_environment'] === 'production' && !is_ssl()) {
            wc_add_notice(
                __('SSL is required for secure payments. Please ensure your connection is secure.', 'bna-payment-gateway'),
                'notice'
            );
        }

        // Add currency notice if not CAD/USD
        $currency = get_woocommerce_currency();
        if (!in_array($currency, array('CAD', 'USD'))) {
            wc_add_notice(
                sprintf(__('BNA Smart Payment currently supports CAD and USD. Your store currency is %s.', 'bna-payment-gateway'), $currency),
                'notice'
            );
        }
    }

    /**
     * Add iFrame container to checkout page
     */
    public function add_iframe_container() {
        if (!$this->is_bna_gateway_selected()) {
            return;
        }

        include $this->get_template_path('checkout/iframe.php');
    }

    /**
     * Process checkout validation
     */
    public function process_checkout_validation() {
        if (!$this->is_bna_gateway_selected()) {
            return;
        }

        // Validate BNA specific fields
        $this->validate_bna_checkout_fields();
    }

    /**
     * Check gateway availability before checkout
     */
    public function check_gateway_availability() {
        if (!$this->is_bna_gateway_available()) {
            return;
        }

        // Add any pre-checkout checks here
        $this->check_cart_requirements();
    }

    /**
     * Display payment confirmation on thank you page
     * @param int $order_id
     */
    public function display_payment_confirmation($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        // Get BNA transaction data
        $order_handler = BNA_Order::get_instance();
        $transaction = $order_handler->get_transaction($order_id);

        if ($transaction) {
            include $this->get_template_path('checkout/confirmation.php');
        }
    }

    /**
     * Add payment info to My Account dashboard
     */
    public function add_account_payment_info() {
        $customer_id = get_current_user_id();
        
        if (!$customer_id) {
            return;
        }

        // Get recent BNA orders for this customer
        $recent_orders = $this->get_customer_bna_orders($customer_id, 5);
        
        if (!empty($recent_orders)) {
            include $this->get_template_path('my-account/recent-payments.php');
        }
    }

    /**
     * Get checkout data via AJAX
     */
    public function get_checkout_data() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $data = array(
                'gateway_available' => $this->is_bna_gateway_available(),
                'payment_methods' => $this->gateway_settings['payment_methods'] ?? array('card', 'eft'),
                'customer_types' => $this->gateway_settings['customer_types'] ?? array('Personal', 'Business'),
                'fees_enabled' => $this->gateway_settings['enable_fees'] === 'yes',
                'currency' => get_woocommerce_currency(),
                'cart_total' => WC()->cart ? WC()->cart->get_total('') : 0,
                'minimum_amount' => 1.00
            );

            wp_send_json_success($data);

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'checkout_data_failed'
            ));
        }
    }

    /**
     * Add custom checkout fields
     * @param array $fields
     * @return array
     */
    public function add_custom_checkout_fields($fields) {
        if (!$this->is_bna_gateway_selected()) {
            return $fields;
        }

        // Add BNA specific fields to billing section
        $fields['billing']['billing_phone_code'] = array(
            'type' => 'select',
            'label' => __('Phone Country Code', 'bna-payment-gateway'),
            'required' => true,
            'class' => array('form-row-first', 'bna-phone-code'),
            'options' => array(
                '+1' => 'Canada/US (+1)',
                '+44' => 'UK (+44)',
                '+33' => 'France (+33)',
                '+49' => 'Germany (+49)'
            ),
            'default' => '+1',
            'priority' => 25
        );

        // Add customer type field
        $customer_types = $this->gateway_settings['customer_types'] ?? array('Personal', 'Business');
        
        if (count($customer_types) > 1) {
            $fields['billing']['bna_customer_type'] = array(
                'type' => 'select',
                'label' => __('Customer Type', 'bna-payment-gateway'),
                'required' => true,
                'class' => array('form-row-wide', 'bna-customer-type'),
                'options' => array_combine($customer_types, $customer_types),
                'default' => $customer_types[0],
                'priority' => 30
            );
        }

        return $fields;
    }

    /**
     * Validate custom fields
     * @param array $data
     */
    public function validate_custom_fields($data) {
        if (!$this->is_bna_gateway_selected()) {
            return;
        }

        // Validate phone code
        if (empty($data['billing_phone_code'])) {
            wc_add_notice(__('Phone country code is required.', 'bna-payment-gateway'), 'error');
        }

        // Validate customer type
        if (isset($data['bna_customer_type'])) {
            $allowed_types = $this->gateway_settings['customer_types'] ?? array('Personal', 'Business');
            if (!in_array($data['bna_customer_type'], $allowed_types)) {
                wc_add_notice(__('Invalid customer type selected.', 'bna-payment-gateway'), 'error');
            }
        }
    }

    /**
     * Validate BNA specific checkout fields
     */
    private function validate_bna_checkout_fields() {
        $validator = BNA_Validator::get_instance();

        // Validate email
        if (!empty($_POST['billing_email'])) {
            if (!$validator->validate_email($_POST['billing_email'])) {
                wc_add_notice(__('Please enter a valid email address.', 'bna-payment-gateway'), 'error');
            }
        }

        // Validate phone
        if (!empty($_POST['billing_phone'])) {
            if (!$validator->validate_phone($_POST['billing_phone'])) {
                wc_add_notice(__('Please enter a valid phone number.', 'bna-payment-gateway'), 'error');
            }
        }

        // Validate postal code
        if (!empty($_POST['billing_postcode']) && !empty($_POST['billing_country'])) {
            if (!$validator->validate_postal_code($_POST['billing_postcode'], $_POST['billing_country'])) {
                wc_add_notice(__('Please enter a valid postal code.', 'bna-payment-gateway'), 'error');
            }
        }
    }

    /**
     * Check cart requirements for BNA payment
     */
    private function check_cart_requirements() {
        if (!WC()->cart) {
            return;
        }

        $cart_total = WC()->cart->get_total('');
        $min_amount = 1.00;

        if ($cart_total < $min_amount) {
            wc_add_notice(
                sprintf(__('Minimum order amount for BNA Smart Payment is %s.', 'bna-payment-gateway'), 
                wc_price($min_amount)),
                'error'
            );
        }
    }

    /**
     * Check if BNA gateway is available
     * @return bool
     */
    private function is_bna_gateway_available() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        return isset($gateways['bna_gateway']) && $gateways['bna_gateway']->is_available();
    }

    /**
     * Check if BNA gateway is currently selected
     * @return bool
     */
    private function is_bna_gateway_selected() {
        return WC()->session && WC()->session->get('chosen_payment_method') === 'bna_gateway';
    }

    /**
     * Get customer's recent BNA orders
     * @param int $customer_id
     * @param int $limit
     * @return array
     */
    private function get_customer_bna_orders($customer_id, $limit = 10) {
        $args = array(
            'customer_id' => $customer_id,
            'payment_method' => 'bna_gateway',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        return wc_get_orders($args);
    }

    /**
     * Get template file path
     * @param string $template
     * @return string
     */
    private function get_template_path($template) {
        $plugin_template = BNA_PLUGIN_PATH . 'public/templates/' . $template;
        
        // Allow theme to override template
        $theme_template = locate_template('bna-payment-gateway/' . $template);
        
        if ($theme_template) {
            return $theme_template;
        }
        
        return $plugin_template;
    }

    /**
     * Load template with variables
     * @param string $template
     * @param array $variables
     */
    public function load_template($template, $variables = array()) {
        extract($variables);
        include $this->get_template_path($template);
    }

    /**
     * Get gateway settings
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get_setting($key, $default = null) {
        return $this->gateway_settings[$key] ?? $default;
    }

    /**
     * Check if debug mode is enabled
     * @return bool
     */
    public function is_debug_mode() {
        return $this->gateway_settings['debug_mode'] === 'yes';
    }
}
