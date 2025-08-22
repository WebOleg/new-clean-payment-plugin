<?php
/**
 * Plugin Name: BNA Payment Gateway
 * Plugin URI: https://bnasmartpayment.com/
 * Description: Clean, modular WordPress plugin for BNA Smart Payment System integration via iFrame
 * Version: 1.0.0
 * Author: BNA Smart Payment
 * Text Domain: bna-payment-gateway
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Legacy constants for compatibility
if (!defined('BNA_PLUGIN_DIR_PATH')) {
    define('BNA_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
}

if (!defined('BNA_PLUGIN_DIR_URL')) {
    define('BNA_PLUGIN_DIR_URL', plugin_dir_url(__FILE__));
}

// Legacy table constants
if (!defined('BNA_TABLE_TRANSACTIONS')) {
    define('BNA_TABLE_TRANSACTIONS', 'bna_transactions');
}
if (!defined('BNA_TABLE_SETTINGS')) {
    define('BNA_TABLE_SETTINGS', 'bna_settings');
}
if (!defined('BNA_TABLE_RECURRING')) {
    define('BNA_TABLE_RECURRING', 'bna_recurring');
}

// Legacy subscription constants
if (!defined('BNA_SUBSCRIPTION_SETTING_REPEAT')) {
    define('BNA_SUBSCRIPTION_SETTING_REPEAT', 'monthly');
}
if (!defined('BNA_SUBSCRIPTION_SETTING_STARTDATE')) {
    define('BNA_SUBSCRIPTION_SETTING_STARTDATE', 0);
}
if (!defined('BNA_SUBSCRIPTION_SETTING_NUMPAYMENT')) {
    define('BNA_SUBSCRIPTION_SETTING_NUMPAYMENT', 0);
}

// Legacy currency constants
if (!defined('BNA_CARD_ALLOWED_CURRENCY')) {
    define('BNA_CARD_ALLOWED_CURRENCY', array('USD', 'CAD'));
}
if (!defined('BNA_EFT_ALLOWED_CURRENCY')) {
    define('BNA_EFT_ALLOWED_CURRENCY', array('CAD'));
}
if (!defined('BNA_E_TRANSFER_ALLOWED_CURRENCY')) {
    define('BNA_E_TRANSFER_ALLOWED_CURRENCY', array('CAD'));
}

/**
 * Main BNA Payment Gateway Class
 * Initializes the entire plugin and manages all components
 */
final class BNA_Payment_Gateway {

    const VERSION = '1.0.0';
    const MIN_WP_VERSION = '5.0';
    const MIN_WC_VERSION = '5.0';
    const MIN_PHP_VERSION = '7.4';

    private static $instance = null;
    private $plugin_file;
    private $plugin_path;
    private $plugin_url;

    /**
     * Get singleton instance
     * @return BNA_Payment_Gateway
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup plugin paths and initialize
     */
    private function __construct() {
        $this->plugin_file = __FILE__;
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url  = plugin_dir_url(__FILE__);

        $this->init_plugin();
    }

    /**
     * Initialize plugin - check requirements and load components
     */
    private function init_plugin() {
        if (!$this->check_requirements()) {
            return;
        }

        $this->setup_hooks();
        $this->load_plugin();
    }

    /**
     * Check WordPress, PHP, WooCommerce requirements
     * @return bool Requirements met
     */
    private function check_requirements() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), self::MIN_WP_VERSION, '<')) {
            add_action('admin_notices', array($this, 'wp_version_notice'));
            return false;
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            return false;
        }

        // Check WooCommerce active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }

        // Check WooCommerce version
        if (class_exists('WooCommerce') && version_compare(WC()->version, self::MIN_WC_VERSION, '<')) {
            add_action('admin_notices', array($this, 'wc_version_notice'));
            return false;
        }

        return true;
    }

    /**
     * Check if WooCommerce is active
     * @return bool WooCommerce active
     */
    private function is_woocommerce_active() {
        if (class_exists('WooCommerce')) {
            return true;
        }

        $active_plugins = (array) get_option('active_plugins', array());

        if (is_multisite()) {
            $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
        }

        return in_array('woocommerce/woocommerce.php', $active_plugins) ||
            array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    }

    /**
     * Setup WordPress hooks for activation, deactivation, init
     */
    private function setup_hooks() {
        register_activation_hook($this->plugin_file, array($this, 'activate'));
        register_deactivation_hook($this->plugin_file, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'plugins_loaded'));
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), array($this, 'plugin_action_links'));
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Load plugin files and initialize components
     */
    private function load_plugin() {
        $this->define_constants();
        $this->load_includes();
        $this->init_components();
    }

    /**
     * Define plugin constants for paths and version
     */
    private function define_constants() {
        if (!defined('BNA_PLUGIN_VERSION')) {
            define('BNA_PLUGIN_VERSION', self::VERSION);
        }
        if (!defined('BNA_PLUGIN_FILE')) {
            define('BNA_PLUGIN_FILE', $this->plugin_file);
        }
        if (!defined('BNA_PLUGIN_PATH')) {
            define('BNA_PLUGIN_PATH', $this->plugin_path);
        }
        if (!defined('BNA_PLUGIN_URL')) {
            define('BNA_PLUGIN_URL', $this->plugin_url);
        }
        if (!defined('BNA_PLUGIN_BASENAME')) {
            define('BNA_PLUGIN_BASENAME', plugin_basename($this->plugin_file));
        }
    }

    /**
     * Include necessary class files - ONLY existing files
     */
    private function load_includes() {
        // Core classes - only load if file exists
        $includes = array(
            // New includes structure
            'includes/class-bna-gateway.php',

            // Legacy inc structure (if exists)
            'inc/bna_class_exchanger.php',
            'inc/bna_class_cctools.php',
            'inc/bna_class_wcgate.php',
            'inc/bna_class_manageaccount.php',
            'inc/bna_class_subscriptions.php',
            'inc/bna_class_jsonmessage.php',
            'inc/bna_wc_hooks_filters.php',
            'inc/bna_functions.php',

            // Public classes
            'public/class-bna-checkout.php'
        );

        foreach ($includes as $file) {
            $file_path = $this->plugin_path . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // Load migration file if exists (legacy support)
        $migration_file = $this->plugin_path . 'inc/bna-migration.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
        }

        // Load webhook handler if exists
        $webhook_file = $this->plugin_path . 'inc/bna_webhook_handler_full.php';
        if (file_exists($webhook_file)) {
            require_once $webhook_file;
        }

        // Load payload generator if exists
        $payload_file = $this->plugin_path . 'inc/bna_generate_payloads.php';
        if (file_exists($payload_file)) {
            require_once $payload_file;
        }
    }

    /**
     * Initialize plugin components - only if classes exist
     */
    private function init_components() {
        // Initialize legacy system if it exists
        if (class_exists('BNAPluginManager')) {
            new BNAPluginManager();
        }

        // Initialize account manager if it exists
        if (class_exists('BNAAccountManager')) {
            new BNAAccountManager();
        }

        // Initialize checkout if it exists
        if (class_exists('BNA_Checkout')) {
            BNA_Checkout::get_instance()->init();
        }
    }

    /**
     * Plugin activation - setup database and options
     */
    public function activate() {
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename($this->plugin_file));
            wp_die(
                esc_html__('BNA Payment Gateway could not be activated due to unmet requirements.', 'bna-payment-gateway'),
                esc_html__('Plugin Activation Error', 'bna-payment-gateway'),
                array('back_link' => true)
            );
        }

        // Legacy activation if exists
        if (class_exists('BNAPluginManager')) {
            BNAPluginManager::activate();
        }

        update_option('bna_plugin_activated', true);
    }

    /**
     * Plugin deactivation - cleanup temporary data
     */
    public function deactivate() {
        delete_option('bna_plugin_activated');
    }

    /**
     * Register payment gateway with WooCommerce
     */
    public function plugins_loaded() {
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
    }

    /**
     * Add gateway class to WooCommerce gateways
     * @param array $gateways
     * @return array
     */
    public function add_gateway_class($gateways) {
        // Add new gateway if class exists
        if (class_exists('BNA_Gateway')) {
            $gateways[] = 'BNA_Gateway';
        }

        // Add legacy gateway if class exists
        if (class_exists('WC_BNA_Gateway')) {
            $gateways[] = 'WC_BNA_Gateway';
        }

        return $gateways;
    }

    /**
     * Add Settings link to plugin actions
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=bna_gateway') . '">' .
            esc_html__('Settings', 'bna-payment-gateway') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bna-payment-gateway',
            false,
            dirname(plugin_basename($this->plugin_file)) . '/languages/'
        );
    }

    // Admin notices for requirements
    public function wp_version_notice() {
        echo '<div class="notice notice-error"><p>';
        printf(esc_html__('BNA Payment Gateway requires WordPress version %s or higher.', 'bna-payment-gateway'), esc_html(self::MIN_WP_VERSION));
        echo '</p></div>';
    }

    public function php_version_notice() {
        echo '<div class="notice notice-error"><p>';
        printf(esc_html__('BNA Payment Gateway requires PHP version %s or higher.', 'bna-payment-gateway'), esc_html(self::MIN_PHP_VERSION));
        echo '</p></div>';
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('BNA Payment Gateway requires WooCommerce to be installed and activated.', 'bna-payment-gateway');
        echo '</p></div>';
    }

    public function wc_version_notice() {
        echo '<div class="notice notice-error"><p>';
        printf(esc_html__('BNA Payment Gateway requires WooCommerce version %s or higher.', 'bna-payment-gateway'), esc_html(self::MIN_WC_VERSION));
        echo '</p></div>';
    }

    // Getters
    public function get_plugin_file() { return $this->plugin_file; }
    public function get_plugin_path() { return $this->plugin_path; }
    public function get_plugin_url() { return $this->plugin_url; }
}

/**
 * Get main plugin instance
 * @return BNA_Payment_Gateway
 */
function bna_payment_gateway() {
    return BNA_Payment_Gateway::get_instance();
}

// Initialize plugin when WordPress loads
add_action('plugins_loaded', function() {
    // Make sure WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        return;
    }

    bna_payment_gateway();
}, 10);