<?php
/**
 * Plugin Name: BNA Payment Bridge
 * Plugin URI: https://your-domain.com/bna-payment-bridge
 * Description: Lightweight modular bridge plugin for BNA Smart Payment iframe integration with WooCommerce
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://your-domain.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bna-payment-bridge
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * 
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BNA_BRIDGE_VERSION', '1.0.0');
define('BNA_BRIDGE_PLUGIN_FILE', __FILE__);
define('BNA_BRIDGE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_BRIDGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_BRIDGE_TEXT_DOMAIN', 'bna-payment-bridge');

/**
 * Main plugin class
 * Handles plugin initialization and core functionality
 * 
 * @return void
 */
final class BNA_Payment_Bridge {
    
    /**
     * Plugin instance
     * 
     * @var BNA_Payment_Bridge
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     * Ensures only one instance of the plugin is loaded
     * 
     * @return BNA_Payment_Bridge
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Plugin constructor
     * Initialize plugin hooks and components
     * 
     * @return void
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     * Set up activation, deactivation and plugin loaded hooks
     * 
     * @return void
     */
    private function init_hooks() {
        register_activation_hook(BNA_BRIDGE_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(BNA_BRIDGE_PLUGIN_FILE, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'), 10);
        
        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }
    
    /**
     * Declare High-Performance Order Storage compatibility
     * Required for WooCommerce 8.0+ HPOS feature
     * 
     * @return void
     */
    public function declare_hpos_compatibility() {
        if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                BNA_BRIDGE_PLUGIN_FILE,
                true
            );
        }
    }
    
    /**
     * Load plugin dependencies
     * Include required files and autoloader
     * 
     * @return void
     */
    private function load_dependencies() {
        require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/core/class-autoloader.php';
        new BNA_Bridge_Autoloader();
    }
    
    /**
     * Plugin activation hook
     * Runs when plugin is activated
     * 
     * @return void
     */
    public function activate() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(BNA_BRIDGE_PLUGIN_FILE);
            wp_die(__('BNA Payment Bridge requires WordPress 5.0 or higher.', BNA_BRIDGE_TEXT_DOMAIN));
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(BNA_BRIDGE_PLUGIN_FILE);
            wp_die(__('BNA Payment Bridge requires PHP 7.4 or higher.', BNA_BRIDGE_TEXT_DOMAIN));
        }
        
        // Create plugin options
        add_option('bna_bridge_version', BNA_BRIDGE_VERSION);
        add_option('bna_bridge_settings', array());
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation hook
     * Runs when plugin is deactivated
     * 
     * @return void
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
    }
    
    /**
     * Initialize plugin
     * Load components after WordPress is fully loaded
     * 
     * @return void
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain(
            BNA_BRIDGE_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(BNA_BRIDGE_PLUGIN_FILE)) . '/languages'
        );
        
        // Initialize core components
        $this->init_core();
        
        // Initialize admin components
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Initialize frontend components
        if (!is_admin()) {
            $this->init_frontend();
        }
        
        // Initialize WooCommerce integration
        add_action('woocommerce_loaded', array($this, 'init_woocommerce'));
    }
    
    /**
     * Initialize core components
     * Load core functionality classes
     * 
     * @return void
     */
    private function init_core() {
        // Core components will be loaded here
    }
    
    /**
     * Initialize admin components
     * Load admin panel functionality
     * 
     * @return void
     */
    private function init_admin() {
        // Admin components will be loaded here
    }
    
    /**
     * Initialize frontend components
     * Load frontend functionality
     * 
     * @return void
     */
    private function init_frontend() {
        // Frontend components will be loaded here
    }
    
    /**
     * Initialize WooCommerce integration
     * Load WooCommerce specific functionality
     * 
     * @return void
     */
    public function init_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . __('BNA Payment Bridge requires WooCommerce to be installed and active.', BNA_BRIDGE_TEXT_DOMAIN) . '</p></div>';
            });
            return;
        }
        
        // Load WooCommerce gateway
        require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/woocommerce/class-gateway.php';
        
        // Register payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
    }
    
    /**
     * Add payment gateway to WooCommerce
     * Register BNA payment gateway with WooCommerce
     * 
     * @param array $gateways Existing payment gateways
     * @return array Updated gateways array
     */
    public function add_payment_gateway($gateways) {
        $gateways[] = 'BNA_Bridge_Gateway';
        return $gateways;
    }
}

/**
 * Initialize the plugin
 * 
 * @return BNA_Payment_Bridge
 */
function bna_payment_bridge() {
    return BNA_Payment_Bridge::get_instance();
}

// Start the plugin
bna_payment_bridge();
