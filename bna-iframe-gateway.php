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

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

define('BNA_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_GATEWAY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_GATEWAY_VERSION', '1.0.5');

add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

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
        add_action('init', array($this, 'init_components'));
        add_action('admin_init', array($this, 'init_debug_system'));
    }

    public function init_debug_system() {
        if (class_exists('BNA_Debug_Helper') && !did_action('bna_debug_initialized')) {
            BNA_Debug_Helper::init();
            BNA_Debug_Helper::log('BNA Plugin debug system initialized', array(
                'plugin_version' => BNA_GATEWAY_VERSION,
                'wp_debug' => defined('WP_DEBUG') && WP_DEBUG
            ));
            do_action('bna_debug_initialized');
        }
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
                'nonce' => wp_create_nonce('bna_ajax_nonce'),
            ));
        }
    }

    public function init_components() {
        // Core listeners
        new BNA_Webhook_Listener();

        // Simple logging
        if (class_exists('BNA_Simple_Logger')) {
            BNA_Simple_Logger::init();
        }

        // Admin interfaces (only in admin)
        if (is_admin()) {
            if (class_exists('BNA_Admin_Debug')) {
                new BNA_Admin_Debug();
            }
            
            if (class_exists('BNA_Debug_Ajax_Handler')) {
                new BNA_Debug_Ajax_Handler();
            }
            
            if (class_exists('BNA_Portal_Test_Page')) {
                new BNA_Portal_Test_Page();
            }
            
            if (class_exists('BNA_Simple_Admin')) {
                new BNA_Simple_Admin();
            }
        }
    }
}

BNA_Gateway_Plugin::get_instance();
