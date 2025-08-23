<?php
/**
 * Plugin Name: BNA WordPress Plugin
 * Plugin URI: https://bnasmartpayment.com
 * Description: Simple and flexible WooCommerce payment gateway for BNA Smart Payment
 * Version: 1.0.0
 * Author: BNA Team
 * Text Domain: bna-payment-gateway
 */

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BNA_PLUGIN_VERSION', '1.0.0');
define('BNA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main plugin class
 */
class BNA_Payment_Plugin {

    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load includes
        $this->load_includes();

        // Add gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));

        // Show success message
        add_action('admin_notices', array($this, 'plugin_loaded_notice'));
    }

    /**
     * Load required files
     */
    private function load_includes() {
        require_once BNA_PLUGIN_PATH . 'includes/class-gateway.php';
        require_once BNA_PLUGIN_PATH . 'includes/class-iframe-handler.php';
        require_once BNA_PLUGIN_PATH . 'includes/simple-ajax-test.php'; // Тестовий файл
    }

    /**
     * Add gateway to WooCommerce
     */
    public function add_gateway($methods) {
        $methods[] = 'BNA_Payment_Gateway';
        return $methods;
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>BNA Payment Gateway requires WooCommerce to be installed and active.</p></div>';
    }

    public function plugin_loaded_notice() {
        echo '<div class="notice notice-success is-dismissible"><p>✅ BNA Payment Plugin with iframe support loaded!</p></div>';
    }
}

// Initialize plugin
new BNA_Payment_Plugin();