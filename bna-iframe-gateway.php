<?php
/**
 * Plugin Name: BNA iFrame Payment Gateway
 * Description: WooCommerce payment gateway for BNA Smart Payment with iframe integration
 * Version: 1.0.5
 * Author: Your Name
 * Text Domain: bna-iframe-gateway
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check WooCommerce
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Define constants
define('BNA_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_GATEWAY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_GATEWAY_VERSION', '1.0.0');

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Include autoloader
require_once BNA_GATEWAY_PLUGIN_PATH . 'autoloader.php';

/**
 * Main Plugin Class
 */
class BNA_Gateway_Plugin {
    
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_autoloader();
        $this->init_hooks();
    }

    private function init_autoloader() {
        $autoloader = BNA_Autoloader::get_instance();
        $autoloader->register();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init_gateway'), 11);
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('init', array($this, 'init_listeners'));
    }

    public function init_gateway() {
        new BNA_Ajax_Handler();
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=bna_gateway') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function enqueue_assets() {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            wp_enqueue_style(
                'bna-iframe-css',
                BNA_GATEWAY_PLUGIN_URL . 'assets/css/bna-iframe.css',
                array(),
                BNA_GATEWAY_VERSION
            );

            wp_enqueue_script(
                'bna-iframe-js',
                BNA_GATEWAY_PLUGIN_URL . 'assets/js/bna-iframe.js',
                array('jquery'),
                BNA_GATEWAY_VERSION,
                true
            );

            wp_localize_script('bna-iframe-js', 'bna_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'checkout_url' => wc_get_checkout_url(),
            ));
        }
    }

    public function init_listeners() {
        new BNA_Webhook_Listener();
    }
}

// Initialize plugin
BNA_Gateway_Plugin::get_instance();
