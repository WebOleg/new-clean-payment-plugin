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
 */
final class BNA_Payment_Bridge {

    /**
     * Plugin instance
     *
     * @var BNA_Payment_Bridge
     */
    private static $instance = null;
    
    /**
     * API integration instance
     *
     * @var BNA_Bridge_API_Integration
     */
    private $api_integration = null;

    /**
     * Scripts handler instance
     *
     * @var BNA_Bridge_Scripts_Handler
     */
    private $scripts_handler = null;

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
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }

    /**
     * Initialize WordPress hooks
     * Set up activation, deactivation and plugin loaded hooks
     */
    private function init_hooks() {
        register_activation_hook(BNA_BRIDGE_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(BNA_BRIDGE_PLUGIN_FILE, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'), 10);

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // AJAX hooks for API testing and token generation
        add_action('wp_ajax_bna_bridge_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bna_bridge_generate_token', array($this, 'ajax_generate_token'));
    }

    /**
     * Declare High-Performance Order Storage compatibility
     * Required for WooCommerce 8.0+ HPOS feature
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
     */
    private function load_dependencies() {
        require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/core/class-autoloader.php';
        new BNA_Bridge_Autoloader();
    }

    /**
     * Plugin activation hook
     * Runs when plugin is activated
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
     */
    public function deactivate() {
        // Clear API token cache on deactivation
        if ($this->api_integration) {
            $this->api_integration->clear_cache();
        }
        
        // Clean up if needed
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin
     * Load components after WordPress is fully loaded
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
        
        // Also initialize immediately if WooCommerce is already loaded
        if (class_exists('WooCommerce')) {
            $this->init_woocommerce();
        }
    }

    /**
     * Initialize core components
     * Load core functionality classes
     */
    private function init_core() {
        // Load helper class manually if autoloader doesn't catch it
        if (!class_exists('BNA_Bridge_Helper')) {
            require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/core/class-helper.php';
        }
        
        // Load API classes
        $this->load_api_classes();
        
        // Initialize scripts handler
        $this->init_scripts_handler();
        
        BNA_Bridge_Helper::log("Core components initialized", 'info');
    }
    
    /**
     * Load API classes
     * Initialize API integration components
     */
    private function load_api_classes() {
        // Load API classes manually to ensure they're available
        $api_files = array(
            'includes/api/class-api-client.php',
            'includes/api/class-token-manager.php', 
            'includes/api/class-api-integration.php'
        );
        
        foreach ($api_files as $file) {
            $file_path = BNA_BRIDGE_PLUGIN_PATH . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
                BNA_Bridge_Helper::log("Loaded API file: {$file}", 'debug');
            } else {
                BNA_Bridge_Helper::log("API file not found: {$file}", 'warning');
            }
        }
    }

    /**
     * Initialize scripts handler
     * Load and initialize centralized scripts management
     */
    private function init_scripts_handler() {
        // Load scripts handler class
        $scripts_file = BNA_BRIDGE_PLUGIN_PATH . 'includes/core/class-scripts-handler.php';
        if (file_exists($scripts_file)) {
            require_once $scripts_file;
            
            // Initialize scripts handler
            if (class_exists('BNA_Bridge_Scripts_Handler')) {
                $this->scripts_handler = new BNA_Bridge_Scripts_Handler();
                BNA_Bridge_Helper::log("Scripts handler initialized", 'debug');
            } else {
                BNA_Bridge_Helper::log("BNA_Bridge_Scripts_Handler class not found", 'warning');
            }
        } else {
            BNA_Bridge_Helper::log("Scripts handler file not found: {$scripts_file}", 'warning');
        }
    }

    /**
     * Initialize admin components
     * Load admin panel functionality
     */
    private function init_admin() {
        // Admin components will be loaded here
        BNA_Bridge_Helper::log("Admin components initialized", 'debug');
    }

    /**
     * Initialize frontend components
     * Load frontend functionality
     */
    private function init_frontend() {
        // Frontend components will be loaded here
        BNA_Bridge_Helper::log("Frontend components initialized", 'debug');
    }

    /**
     * Initialize WooCommerce integration
     * Load WooCommerce specific functionality
     */
    public function init_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . __('BNA Payment Bridge requires WooCommerce to be installed and active.', BNA_BRIDGE_TEXT_DOMAIN) . '</p></div>';
            });
            return;
        }

        // Load WooCommerce gateway manually to ensure it's loaded
        if (!class_exists('BNA_Bridge_Gateway')) {
            require_once BNA_BRIDGE_PLUGIN_PATH . 'includes/woocommerce/class-gateway.php';
        }

        // Register payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));

        // Update scripts handler settings (delayed to avoid gateway method issues)
        add_action('init', array($this, 'update_scripts_handler_settings'), 20);

        BNA_Bridge_Helper::log("WooCommerce integration initialized", 'info');
    }

    /**
     * Update scripts handler with gateway settings
     * Pass current gateway settings to scripts handler
     */
    public function update_scripts_handler_settings() {
        if (!$this->scripts_handler) {
            return;
        }

        // Get gateway settings directly from options table to avoid method issues
        $gateway_settings = get_option('woocommerce_bna_bridge_settings', array());
        
        if (!empty($gateway_settings)) {
            $settings = array(
                'testmode' => isset($gateway_settings['testmode']) ? $gateway_settings['testmode'] : 'yes',
                'gateway_id' => 'bna_bridge',
                'enabled' => isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no'
            );
            
            $this->scripts_handler->set_gateway_settings($settings);
            BNA_Bridge_Helper::log("Scripts handler settings updated from options", 'debug');
        }
    }

    /**
     * Add payment gateway to WooCommerce
     * Register BNA payment gateway with WooCommerce
     *
     * @param array $gateways Existing payment gateways
     * @return array Updated gateways array
     */
    public function add_payment_gateway($gateways) {
        if (class_exists('BNA_Bridge_Gateway')) {
            $gateways[] = 'BNA_Bridge_Gateway';
            BNA_Bridge_Helper::log("Payment gateway added to WooCommerce", 'debug');
        } else {
            BNA_Bridge_Helper::log("BNA_Bridge_Gateway class not found", 'error');
        }

        return $gateways;
    }
    
    /**
     * Get API integration instance
     * Create or return existing API integration instance
     * 
     * @param array $settings Gateway settings (optional)
     * @return BNA_Bridge_API_Integration|null
     */
    public function get_api_integration($settings = array()) {
        // Get settings from options if not provided
        if (empty($settings)) {
            $gateway_settings = get_option('woocommerce_bna_bridge_settings', array());
            
            if (!empty($gateway_settings)) {
                $settings = array(
                    'access_key' => isset($gateway_settings['access_key']) ? $gateway_settings['access_key'] : '',
                    'secret_key' => isset($gateway_settings['secret_key']) ? $gateway_settings['secret_key'] : '',
                    'iframe_id' => isset($gateway_settings['iframe_id']) ? $gateway_settings['iframe_id'] : '',
                    'testmode' => isset($gateway_settings['testmode']) ? $gateway_settings['testmode'] : 'yes'
                );
            }
        }
        
        // Create API integration if needed or settings changed
        if (!$this->api_integration || !empty($settings)) {
            if (class_exists('BNA_Bridge_API_Integration')) {
                $this->api_integration = new BNA_Bridge_API_Integration($settings);
                BNA_Bridge_Helper::log("API integration instance created", 'debug');
            } else {
                BNA_Bridge_Helper::log("BNA_Bridge_API_Integration class not found", 'error');
                return null;
            }
        }
        
        return $this->api_integration;
    }

    /**
     * Get scripts handler instance
     * Return scripts handler for external use
     * 
     * @return BNA_Bridge_Scripts_Handler|null
     */
    public function get_scripts_handler() {
        return $this->scripts_handler;
    }
    
    /**
     * AJAX handler for connection testing
     * Test BNA API connection via AJAX
     */
    public function ajax_test_connection() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bna_bridge_admin')) {
            wp_die(__('Security check failed', BNA_BRIDGE_TEXT_DOMAIN));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permission denied', BNA_BRIDGE_TEXT_DOMAIN));
        }
        
        $settings = array(
            'access_key' => sanitize_text_field($_POST['access_key'] ?? ''),
            'secret_key' => sanitize_text_field($_POST['secret_key'] ?? ''),
            'testmode' => sanitize_text_field($_POST['testmode'] ?? 'yes')
        );
        
        $api_integration = $this->get_api_integration($settings);
        
        if (!$api_integration) {
            wp_send_json_error(array(
                'message' => __('Failed to initialize API integration', BNA_BRIDGE_TEXT_DOMAIN)
            ));
        }
        
        $result = $api_integration->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for token generation
     * Generate iframe token via AJAX
     */
    public function ajax_generate_token() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bna_bridge_checkout')) {
            wp_die(__('Security check failed', BNA_BRIDGE_TEXT_DOMAIN));
        }
        
        $api_integration = $this->get_api_integration();
        
        if (!$api_integration) {
            wp_send_json_error(array(
                'message' => __('API not configured', BNA_BRIDGE_TEXT_DOMAIN)
            ));
        }
        
        // Get checkout data from request
        $checkout_data = json_decode(stripslashes($_POST['checkout_data'] ?? '{}'), true);
        
        $token_result = $api_integration->generate_iframe_token($checkout_data);
        
        if (is_wp_error($token_result)) {
            wp_send_json_error(array(
                'message' => $token_result->get_error_message()
            ));
        }
        
        wp_send_json_success(array(
            'token' => $token_result['token'],
            'iframe_url' => $api_integration->get_iframe_url($token_result['token']),
            'expires_at' => $token_result['expires_at'],
            'from_cache' => $token_result['from_cache']
        ));
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
