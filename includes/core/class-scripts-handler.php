<?php
/**
 * BNA Scripts Handler
 * Centralized management of all plugin scripts and styles
 * 
 * @package BNA_Payment_Bridge
 * @subpackage Core
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Scripts Handler class
 * Manages all plugin scripts and styles enqueuing
 */
class BNA_Bridge_Scripts_Handler {
    
    /**
     * Gateway settings for script localization
     * 
     * @var array
     */
    private $gateway_settings = array();
    
    /**
     * Constructor
     * Initialize script hooks
     */
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Set gateway settings for localization
     * Used by payment gateway to pass settings for scripts
     * 
     * @param array $settings Gateway settings
     * @return void
     */
    public function set_gateway_settings($settings) {
        $this->gateway_settings = $settings;
    }
    
    /**
     * Enqueue frontend scripts
     * Load scripts for frontend pages (checkout, etc.)
     * 
     * @return void
     */
    public function enqueue_frontend_scripts() {
        // Only load on checkout page
        if (!is_checkout()) {
            return;
        }
        
        // Check if BNA gateway is available and enabled
        if (!$this->is_bna_gateway_enabled()) {
            return;
        }
        
        $this->enqueue_checkout_scripts();
        $this->enqueue_checkout_styles();
        
        BNA_Bridge_Helper::log('Frontend scripts enqueued for checkout', 'debug');
    }
    
    /**
     * Enqueue admin scripts
     * Load scripts for admin pages
     * 
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on WooCommerce settings pages
        if (!$this->is_woocommerce_settings_page($hook)) {
            return;
        }
        
        $this->enqueue_admin_gateway_scripts();
        $this->enqueue_admin_styles();
        
        BNA_Bridge_Helper::log('Admin scripts enqueued', 'debug');
    }
    
    /**
     * Enqueue checkout scripts
     * Load JavaScript files needed for checkout
     * 
     * @return void
     */
    private function enqueue_checkout_scripts() {
        // Main checkout script
        wp_enqueue_script(
            'bna-bridge-checkout',
            BNA_BRIDGE_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery', 'wc-checkout'),
            BNA_BRIDGE_VERSION,
            true
        );
        
        // iframe handler script
        wp_enqueue_script(
            'bna-bridge-iframe',
            BNA_BRIDGE_PLUGIN_URL . 'assets/js/iframe-handler.js',
            array('bna-bridge-checkout'),
            BNA_BRIDGE_VERSION,
            true
        );
        
        // Localize checkout script
        wp_localize_script('bna-bridge-checkout', 'bna_bridge_checkout', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bna_bridge_checkout'),
            'gateway_id' => 'bna_bridge',
            'settings' => array(
                'testmode' => $this->gateway_settings['testmode'] ?? 'yes'
            ),
            'messages' => array(
                'loading' => __('Loading secure payment form...', BNA_BRIDGE_TEXT_DOMAIN),
                'error_loading' => __('Unable to load payment form. Please refresh and try again.', BNA_BRIDGE_TEXT_DOMAIN),
                'error_incomplete' => __('Please complete all required billing details before proceeding.', BNA_BRIDGE_TEXT_DOMAIN),
                'processing' => __('Processing your payment...', BNA_BRIDGE_TEXT_DOMAIN),
                'payment_success' => __('Payment completed successfully!', BNA_BRIDGE_TEXT_DOMAIN),
                'payment_failed' => __('Payment failed. Please try again or use a different payment method.', BNA_BRIDGE_TEXT_DOMAIN),
                'connection_error' => __('Connection error. Please check your internet connection.', BNA_BRIDGE_TEXT_DOMAIN),
            ),
            'iframe' => array(
                'min_height' => '500px',
                'loading_timeout' => 30000, // 30 seconds
                'allowed_origins' => $this->get_allowed_iframe_origins()
            )
        ));
    }
    
    /**
     * Enqueue checkout styles
     * Load CSS files for checkout
     * 
     * @return void
     */
    private function enqueue_checkout_styles() {
        wp_enqueue_style(
            'bna-bridge-checkout',
            BNA_BRIDGE_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            BNA_BRIDGE_VERSION
        );
        
        // Add inline styles for iframe container
        $custom_css = "
            #bna-bridge-iframe-container {
                position: relative;
                min-height: 500px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: #fff;
            }
            #bna-bridge-iframe-loading {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                text-align: center;
                color: #666;
            }
            .bna-bridge-iframe {
                width: 100%;
                min-height: 500px;
                border: none;
                border-radius: 4px;
            }
            .bna-bridge-error {
                padding: 15px;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                border-radius: 4px;
                margin: 10px 0;
            }
            .bna-bridge-success {
                padding: 15px;
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                border-radius: 4px;
                margin: 10px 0;
            }
        ";
        wp_add_inline_style('bna-bridge-checkout', $custom_css);
    }
    
    /**
     * Enqueue admin gateway scripts
     * Load JavaScript for admin gateway settings
     * 
     * @return void
     */
    private function enqueue_admin_gateway_scripts() {
        wp_enqueue_script(
            'bna-bridge-admin',
            BNA_BRIDGE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            BNA_BRIDGE_VERSION,
            true
        );
        
        // Localize admin script
        wp_localize_script('bna-bridge-admin', 'bna_bridge_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bna_bridge_admin'),
            'messages' => array(
                'testing_connection' => __('Testing connection...', BNA_BRIDGE_TEXT_DOMAIN),
                'connection_success' => __('Connection successful!', BNA_BRIDGE_TEXT_DOMAIN),
                'connection_failed' => __('Connection failed:', BNA_BRIDGE_TEXT_DOMAIN),
                'missing_credentials' => __('Please enter Access Key and Secret Key', BNA_BRIDGE_TEXT_DOMAIN),
            )
        ));
    }
    
    /**
     * Enqueue admin styles
     * Load CSS for admin pages
     * 
     * @return void
     */
    private function enqueue_admin_styles() {
        wp_enqueue_style(
            'bna-bridge-admin',
            BNA_BRIDGE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BNA_BRIDGE_VERSION
        );
    }
    
    /**
     * Check if BNA gateway is enabled
     * Verify if the payment gateway is active and available
     * 
     * @return bool True if gateway is enabled
     */
    private function is_bna_gateway_enabled() {
        // Get gateway settings directly from options instead of using gateway instance
        $gateway_settings = get_option('woocommerce_bna_bridge_settings', array());
        
        // Check if gateway is enabled
        $enabled = isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no';
        
        if ($enabled !== 'yes') {
            return false;
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if current page is WooCommerce settings
     * Determine if we're on a WooCommerce settings page
     * 
     * @param string $hook Current page hook
     * @return bool True if on WooCommerce settings page
     */
    private function is_woocommerce_settings_page($hook) {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return false;
        }
        
        $tab = $_GET['tab'] ?? '';
        $section = $_GET['section'] ?? '';
        
        // Only load on payments tab with BNA gateway section
        return $tab === 'checkout' && $section === 'bna_bridge';
    }
    
    /**
     * Get allowed iframe origins
     * Return list of allowed origins for iframe security
     * 
     * @return array List of allowed origins
     */
    private function get_allowed_iframe_origins() {
        $testmode = ($this->gateway_settings['testmode'] ?? 'yes') === 'yes';
        
        if ($testmode) {
            return array(
                'https://stage-api-service.bnasmartpayment.com',
            );
        }
        
        return array(
            'https://api-service.bnasmartpayment.com'
        );
    }
}
