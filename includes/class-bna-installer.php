<?php
/**
 * BNA Installer Class
 * Handles plugin installation, activation, and database setup
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Installer
 * Creates database tables and sets default options on plugin activation
 */
class BNA_Installer {

    const DB_VERSION = '1.0.0';

    /**
     * Install plugin - create tables and set defaults
     */
    public static function install() {
        if (self::needs_db_update()) {
            self::create_tables();
            self::create_options();
            self::update_db_version();
        }

        self::set_default_settings();
        self::create_directories();

        if (class_exists('BNA_Logger')) {
            BNA_Logger::log('Plugin installed successfully', 'info');
        }
    }

    /**
     * Check if database update is needed
     * @return bool
     */
    private static function needs_db_update() {
        $current_version = get_option('bna_db_version', '');
        return version_compare($current_version, self::DB_VERSION, '<');
    }

    /**
     * Create database tables for transactions and logs
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Transactions table
        $transactions_table = $wpdb->prefix . 'bna_transactions';
        $transactions_sql = "CREATE TABLE {$transactions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            transaction_id varchar(255) NOT NULL,
            iframe_token varchar(255) DEFAULT NULL,
            payment_method varchar(50) DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'CAD',
            status varchar(20) NOT NULL DEFAULT 'pending',
            bna_response longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY transaction_id (transaction_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Logs table
        $logs_table = $wpdb->prefix . 'bna_logs';
        $logs_sql = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            source varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY source (source),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Customer tokens table
        $tokens_table = $wpdb->prefix . 'bna_customer_tokens';
        $tokens_sql = "CREATE TABLE {$tokens_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token varchar(255) NOT NULL,
            payment_method varchar(50) NOT NULL,
            last_four varchar(4) DEFAULT NULL,
            expiry_month varchar(2) DEFAULT NULL,
            expiry_year varchar(4) DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY token (token),
            KEY payment_method (payment_method)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($transactions_sql);
        dbDelta($logs_sql);
        dbDelta($tokens_sql);
    }

    /**
     * Create default options
     */
    private static function create_options() {
        $default_options = array(
            'bna_api_environment' => 'staging',
            'bna_logging_enabled' => true,
            'bna_log_level' => 'info',
            'bna_webhook_secret' => wp_generate_password(32, false),
            'bna_iframe_timeout' => 300,
        );

        foreach ($default_options as $option_name => $option_value) {
            if (!get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    /**
     * Set default WooCommerce gateway settings
     */
    private static function set_default_settings() {
        $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());

        $defaults = array(
            'enabled' => 'no',
            'title' => 'BNA Payment',
            'description' => 'Pay securely with BNA Smart Payment System',
            'api_environment' => 'staging',
            'access_key' => '',
            'secret_key' => '',
            'iframe_id' => '',
            'webhook_secret' => get_option('bna_webhook_secret', ''),
            'debug_mode' => 'yes',
        );

        $gateway_settings = wp_parse_args($gateway_settings, $defaults);
        update_option('woocommerce_bna_gateway_settings', $gateway_settings);
    }

    /**
     * Create necessary directories for logs and uploads
     */
    private static function create_directories() {
        $upload_dir = wp_upload_dir();
        $bna_dir = $upload_dir['basedir'] . '/bna-payment-gateway';

        if (!file_exists($bna_dir)) {
            wp_mkdir_p($bna_dir);
        }

        $logs_dir = $bna_dir . '/logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }

        $htaccess_file = $logs_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Order Deny,Allow\nDeny from all";
            file_put_contents($htaccess_file, $htaccess_content);
        }

        $index_files = array($bna_dir . '/index.php', $logs_dir . '/index.php');
        foreach ($index_files as $index_file) {
            if (!file_exists($index_file)) {
                file_put_contents($index_file, '<?php // Silence is golden');
            }
        }
    }

    /**
     * Update database version
     */
    private static function update_db_version() {
        update_option('bna_db_version', self::DB_VERSION);
    }

    /**
     * Check if tables exist
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'bna_transactions',
            $wpdb->prefix . 'bna_logs',
            $wpdb->prefix . 'bna_customer_tokens'
        );

        foreach ($tables as $table) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get database version
     * @return string
     */
    public static function get_db_version() {
        return get_option('bna_db_version', '');
    }

    /**
     * Drop all plugin tables - used in uninstall
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'bna_transactions',
            $wpdb->prefix . 'bna_logs',
            $wpdb->prefix . 'bna_customer_tokens'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option('bna_db_version');
    }

    /**
     * Clean up plugin options - used in uninstall
     */
    public static function cleanup_options() {
        $options_to_remove = array(
            'bna_db_version',
            'bna_api_environment',
            'bna_logging_enabled',
            'bna_log_level',
            'bna_webhook_secret',
            'bna_iframe_timeout',
            'bna_plugin_activated',
            'woocommerce_bna_gateway_settings'
        );

        foreach ($options_to_remove as $option) {
            delete_option($option);
        }
    }
}
