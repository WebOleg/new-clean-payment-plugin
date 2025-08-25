<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Payment Gateway Class
 */
class BNA_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bna_gateway';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment iframe integration';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->init_properties();
        $this->init_hooks();
    }

    private function init_properties() {
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->mode = $this->get_option('mode', 'development');
        $this->iframe_id = $this->get_option('iframe_id');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
    }

    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
    }

    /**
     * Initialize form fields for admin settings
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable BNA Payment Gateway',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title that customers see during checkout.',
                'default' => 'BNA Smart Payment',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description that customers see during checkout.',
                'default' => 'Pay securely using BNA Smart Payment system.',
            ),
            'mode' => array(
                'title' => 'Mode',
                'type' => 'select',
                'description' => 'Select development for testing, staging for pre-production, production for live payments.',
                'default' => 'development',
                'desc_tip' => true,
                'options' => array(
                    'development' => 'Development (Testing)',
                    'staging' => 'Staging (Pre-production)',
                    'production' => 'Production (Live)'
                ),
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your BNA iFrame identifier from Merchant Portal.',
                'default' => '',
                'desc_tip' => true,
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your BNA access key for API authentication.',
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your BNA secret key for API authentication.',
                'default' => '',
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Process payment and redirect to BNA payment page
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        BNA_Debug_Helper::log('PROCESS PAYMENT CALLED', array(
            'order_id' => $order_id,
            'method' => 'bna_gateway'
        ), 'PAYMENT');

        $order = wc_get_order($order_id);

        if (!$order) {
            BNA_Debug_Helper::log('ORDER NOT FOUND', array('order_id' => $order_id), 'ERROR');
            wc_add_notice('Order not found', 'error');
            return array('result' => 'failure');
        }

        if (!$this->validate_settings()) {
            BNA_Debug_Helper::log('SETTINGS VALIDATION FAILED', array(), 'ERROR');
            return array('result' => 'failure');
        }

        BNA_Debug_Helper::log('CREATING API CLIENT', array(), 'PAYMENT');
        $api_client = new BNA_Api_Helper($this->get_api_config());
        
        BNA_Debug_Helper::log('GETTING CHECKOUT TOKEN', array(), 'PAYMENT');
        $token = $api_client->get_checkout_token($order);

        if (!$token) {
            BNA_Debug_Helper::log('TOKEN GENERATION FAILED', array(), 'ERROR');
            $this->handle_token_error();
            return array('result' => 'failure');
        }

        BNA_Debug_Helper::log('TOKEN RECEIVED SUCCESSFULLY', array(
            'token_length' => strlen($token)
        ), 'PAYMENT');

        $this->store_order_data($order, $token);

        BNA_Debug_Helper::log('REDIRECTING TO PAYMENT PAGE', array(
            'redirect_url' => $order->get_checkout_payment_url(true)
        ), 'PAYMENT');

        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Validate plugin settings
     *
     * @return bool
     */
    private function validate_settings() {
        if (empty($this->iframe_id)) {
            wc_add_notice('iFrame ID is not configured', 'error');
            return false;
        }

        if (empty($this->access_key) || empty($this->secret_key)) {
            wc_add_notice('API credentials are not configured', 'error');
            return false;
        }

        return true;
    }

    /**
     * Get API configuration array
     *
     * @return array
     */
    private function get_api_config() {
        return array(
            'mode' => $this->mode,
            'iframe_id' => $this->iframe_id,
            'access_key' => $this->access_key,
            'secret_key' => $this->secret_key,
        );
    }

    private function handle_token_error() {
        if (current_user_can('manage_options')) {
            wc_add_notice('BNA API Error - Check logs for details', 'error');
        } else {
            wc_add_notice('Payment initialization failed. Please try again.', 'error');
        }
    }

    /**
     * Store order data for payment processing
     *
     * @param WC_Order $order
     * @param string $token
     */
    private function store_order_data($order, $token) {
        $order->update_meta_data('_bna_checkout_token', $token);
        $order->update_meta_data('_bna_payment_method', 'bna_gateway');
        $order->update_status('pending', 'Awaiting BNA payment');
        $order->save();
    }

    /**
     * Display payment fields on checkout
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
    }

    /**
     * Display payment page with iframe
     *
     * @param int $order_id
     */
    public function receipt_page($order_id) {
        BNA_Debug_Helper::log('RECEIPT PAGE CALLED', array(
            'order_id' => $order_id
        ), 'PAYMENT');

        $order = wc_get_order($order_id);
        if (!$order) {
            BNA_Debug_Helper::log('RECEIPT PAGE - ORDER NOT FOUND', array(
                'order_id' => $order_id
            ), 'ERROR');
            $this->render_error_template('Order not found. Please try again.');
            return;
        }

        $token = $order->get_meta('_bna_checkout_token');
        if (!$token) {
            BNA_Debug_Helper::log('RECEIPT PAGE - TOKEN NOT FOUND', array(
                'order_id' => $order_id
            ), 'ERROR');
            $this->render_expired_template($order);
            return;
        }

        BNA_Debug_Helper::log('RECEIPT PAGE - RENDERING IFRAME', array(
            'order_id' => $order_id,
            'token_length' => strlen($token)
        ), 'PAYMENT');

        $renderer = new BNA_Iframe_Renderer($this->get_api_config(), $token, $order);
        $renderer->render();
    }

    /**
     * Render error message template
     *
     * @param string $message
     */
    private function render_error_template($message) {
        include BNA_GATEWAY_PLUGIN_PATH . 'templates/error-message.php';
    }

    /**
     * Render session expired template
     *
     * @param WC_Order $order
     */
    private function render_expired_template($order) {
        $retry_url = $order->get_checkout_payment_url();
        include BNA_GATEWAY_PLUGIN_PATH . 'templates/session-expired.php';
    }
}
