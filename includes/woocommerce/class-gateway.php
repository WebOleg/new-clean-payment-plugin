<?php
/**
 * BNA Payment Gateway
 * Basic WooCommerce payment gateway integration for BNA Smart Payment
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
 * Extends WooCommerce payment gateway functionality
 */
class BNA_Bridge_Gateway extends WC_Payment_Gateway {

    /**
     * Gateway constructor
     * Initialize gateway settings and properties
     *
     * @return void
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

        // Set API endpoint based on test mode
        $this->api_endpoint = $this->testmode
            ? 'https://api-staging.bnasmartpayment.com'
            : 'https://api.bnasmartpayment.com';

        // Admin hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Initialize gateway form fields
     * Define admin settings fields for the gateway
     *
     * @return void
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
                'default' => __('BNA Payment', BNA_BRIDGE_TEXT_DOMAIN),
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
     * Load payment scripts
     * Enqueue necessary scripts for payment processing
     *
     * @return void
     */
    public function payment_scripts() {
        // Only load on checkout page
        if (!is_checkout()) {
            return;
        }

        // Only load if gateway is enabled
        if ('no' === $this->enabled) {
            return;
        }

        // Script will be added in later stages
        if (class_exists('BNA_Bridge_Helper')) {
            BNA_Bridge_Helper::log('Payment scripts loaded for checkout page');
        }
    }

    /**
     * Display payment fields
     * Show iframe container and loading message
     *
     * @return void
     */
    public function payment_fields() {
        // Show description if set
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        // Show test mode notice
        if ($this->testmode) {
            echo '<p style="color: #ff6600; font-weight: bold;">' . __('TEST MODE ENABLED - No real payments will be processed.', BNA_BRIDGE_TEXT_DOMAIN) . '</p>';
        }

        // Check if credentials are configured
        if (empty($this->access_key) || empty($this->secret_key) || empty($this->iframe_id)) {
            echo '<p style="color: red;">' . __('Payment gateway is not properly configured. Please contact site administrator.', BNA_BRIDGE_TEXT_DOMAIN) . '</p>';
            return;
        }

        // iframe container (will be populated by JavaScript in later stages)
        echo '<div id="bna-bridge-iframe-container" style="min-height: 400px; border: 1px solid #ddd; padding: 20px; text-align: center;">';
        echo '<p>' . __('Loading payment form...', BNA_BRIDGE_TEXT_DOMAIN) . '</p>';
        echo '<small style="color: #666;">' . __('iframe integration will be added in next development stage', BNA_BRIDGE_TEXT_DOMAIN) . '</small>';
        echo '</div>';
    }

    /**
     * Process payment
     * Handle payment processing (placeholder for now)
     *
     * @param int $order_id Order ID
     * @return array Payment result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // For now, just log the attempt
        if (class_exists('BNA_Bridge_Helper')) {
            BNA_Bridge_Helper::log("Payment processing attempted for order #$order_id");
        }

        // In next stages, this will handle actual payment processing
        // For now, mark as pending payment
        $order->update_status('pending', __('Awaiting BNA payment processing implementation.', BNA_BRIDGE_TEXT_DOMAIN));

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Check if gateway is available
     * Determine if gateway should be available for use
     *
     * @return bool True if available
     */
    public function is_available() {
        // Check if enabled
        if ('yes' !== $this->enabled) {
            return false;
        }

        // Check if WooCommerce is active
        if (class_exists('BNA_Bridge_Helper') && !BNA_Bridge_Helper::is_woocommerce_active()) {
            return false;
        }

        // For development, allow gateway even without credentials
        // Remove this in production
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return parent::is_available();
        }

        // Check if required settings are configured
        if (empty($this->access_key) || empty($this->secret_key) || empty($this->iframe_id)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Admin options output
     * Display admin configuration form
     *
     * @return void
     */
    public function admin_options() {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        // Add connection test button (will be functional in later stages)
        echo '<p><button type="button" class="button-secondary" disabled>' . __('Test Connection', BNA_BRIDGE_TEXT_DOMAIN) . '</button> ';
        echo '<small>' . __('Connection testing will be available in next development stage', BNA_BRIDGE_TEXT_DOMAIN) . '</small></p>';
    }
}