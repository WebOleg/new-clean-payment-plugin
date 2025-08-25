<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Gateway Autoloader
 *
 * Enhanced autoloader with support for new directory structure
 * and additional class categories including debug system.
 */
class BNA_Autoloader {

    /**
     * Singleton instance
     *
     * @var BNA_Autoloader
     */
    private static $instance = null;

    /**
     * Class namespace prefix
     *
     * @var string
     */
    private $namespace_prefix = 'BNA_';

    /**
     * Base directory for includes
     *
     * @var string
     */
    private $base_dir;

    /**
     * Constructor
     */
    private function __construct() {
        $this->base_dir = BNA_GATEWAY_PLUGIN_PATH . 'includes/';
    }

    /**
     * Get singleton instance
     *
     * @return BNA_Autoloader
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register autoloader
     *
     * @return void
     */
    public function register() {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Autoload class files
     *
     * @param string $class_name Class name to autoload
     * @return void
     */
    public function autoload($class_name) {
        if (strpos($class_name, $this->namespace_prefix) !== 0) {
            return;
        }

        $file_path = $this->get_file_path($class_name);

        if ($file_path && file_exists($file_path)) {
            require_once $file_path;
            $this->log_class_loaded($class_name, $file_path);
        } else {
            $this->log_class_not_found($class_name);
        }
    }

    /**
     * Get file path for class
     *
     * @param string $class_name Class name
     * @return string|false File path or false if not found
     */
    private function get_file_path($class_name) {
        // Direct class mapping for specific classes
        $class_map = $this->get_direct_class_map();

        if (isset($class_map[$class_name])) {
            return $this->base_dir . $class_map[$class_name];
        }

        // Pattern-based mapping for auto-discovery
        return $this->get_file_path_by_pattern($class_name);
    }

    /**
     * Get direct class mapping
     *
     * @return array Direct class to file mapping
     */
    private function get_direct_class_map() {
        return array(
            // Core Gateway
            'BNA_Gateway' => 'gateways/class-bna-gateway.php',

            // Handlers
            'BNA_Ajax_Handler' => 'handlers/class-bna-ajax-handler.php',
            'BNA_Debug_Ajax_Handler' => 'handlers/class-bna-debug-ajax-handler.php',

            // Renderers
            'BNA_Iframe_Renderer' => 'renderers/class-bna-iframe-renderer.php',

            // Helpers
            'BNA_Api_Helper' => 'helpers/class-bna-api-helper.php',
            'BNA_Debug_Helper' => 'helpers/class-bna-debug-helper.php',

            // Listeners
            'BNA_Webhook_Listener' => 'listeners/class-bna-webhook-listener.php',

            // Managers
            'BNA_Config_Manager' => 'managers/class-bna-config-manager.php',
            'BNA_Settings_Validator' => 'managers/class-bna-settings-validator.php',
            'BNA_Customer_Manager' => 'managers/class-bna-customer-manager.php',

            // Builders
            'BNA_Payload_Builder' => 'builders/class-bna-payload-builder.php',

            // Validators
            'BNA_Portal_Validator' => 'validators/class-bna-portal-validator.php',

            // Admin Interface
            'BNA_Admin_Interface' => 'admin/class-bna-admin-interface.php',
            'BNA_Admin_Debug' => 'admin/class-bna-admin-debug.php',
        );
    }

    /**
     * Get file path by pattern matching
     *
     * @param string $class_name Class name
     * @return string|false File path or false if not found
     */
    private function get_file_path_by_pattern($class_name) {
        $class_without_prefix = substr($class_name, strlen($this->namespace_prefix));
        $filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';

        // Directory mappings based on class suffix patterns
        $directory_mappings = array(
            'Gateway' => 'gateways/',
            'Handler' => 'handlers/',
            'Renderer' => 'renderers/',
            'Listener' => 'listeners/',
            'Helper' => 'helpers/',
            'Api' => 'helpers/',
            'Manager' => 'managers/',
            'Builder' => 'builders/',
            'Validator' => 'validators/',
            'Interface' => 'admin/',
            'Admin' => 'admin/',
            'Debug' => 'helpers/', // Fallback for debug classes
        );

        foreach ($directory_mappings as $pattern => $directory) {
            if (strpos($class_without_prefix, $pattern) !== false) {
                $file_path = $this->base_dir . $directory . $filename;
                if (file_exists($file_path)) {
                    return $file_path;
                }
            }
        }

        // Fallback: try root includes directory
        $root_file_path = $this->base_dir . $filename;
        if (file_exists($root_file_path)) {
            return $root_file_path;
        }

        return false;
    }

    /**
     * Log successful class loading
     *
     * @param string $class_name Class name
     * @param string $file_path File path
     * @return void
     */
    private function log_class_loaded($class_name, $file_path) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('BNA_DEBUG_AUTOLOADER') && BNA_DEBUG_AUTOLOADER) {
            $relative_path = str_replace(BNA_GATEWAY_PLUGIN_PATH, '', $file_path);
            error_log('BNA Autoloader: Loaded ' . $class_name . ' from ' . $relative_path);
        }
    }

    /**
     * Log class not found error
     *
     * @param string $class_name Class name
     * @return void
     */
    private function log_class_not_found($class_name) {
        error_log('BNA Autoloader: Could not find file for class ' . $class_name);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->suggest_file_location($class_name);
        }
    }

    /**
     * Suggest file location for missing class
     *
     * @param string $class_name Class name
     * @return void
     */
    private function suggest_file_location($class_name) {
        $class_without_prefix = substr($class_name, strlen($this->namespace_prefix));
        $suggested_filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';

        $directory_mappings = array(
            'Gateway' => 'gateways/',
            'Handler' => 'handlers/',
            'Renderer' => 'renderers/',
            'Listener' => 'listeners/',
            'Helper' => 'helpers/',
            'Manager' => 'managers/',
            'Builder' => 'builders/',
            'Validator' => 'validators/',
            'Admin' => 'admin/',
            'Debug' => 'helpers/',
        );

        foreach ($directory_mappings as $pattern => $directory) {
            if (strpos($class_without_prefix, $pattern) !== false) {
                $suggested_path = 'includes/' . $directory . $suggested_filename;
                error_log('BNA Autoloader: Suggested location: ' . $suggested_path);
                break;
            }
        }
    }

    /**
     * Get all registered class mappings
     *
     * @return array All class mappings
     */
    public function get_class_mappings() {
        return $this->get_direct_class_map();
    }

    /**
     * Check if class is registered
     *
     * @param string $class_name Class name to check
     * @return bool Is class registered
     */
    public function is_class_registered($class_name) {
        $mappings = $this->get_direct_class_map();
        return isset($mappings[$class_name]);
    }

    /**
     * Get classes by directory
     *
     * @param string $directory Directory to filter by
     * @return array Classes in directory
     */
    public function get_classes_by_directory($directory) {
        $classes = array();
        $mappings = $this->get_direct_class_map();

        foreach ($mappings as $class_name => $file_path) {
            if (strpos($file_path, $directory . '/') === 0) {
                $classes[] = $class_name;
            }
        }

        return $classes;
    }

    /**
     * Validate all registered classes exist
     *
     * @return array Missing classes
     */
    public function validate_classes() {
        $missing_classes = array();
        $mappings = $this->get_direct_class_map();

        foreach ($mappings as $class_name => $file_path) {
            $full_path = $this->base_dir . $file_path;
            if (!file_exists($full_path)) {
                $missing_classes[$class_name] = $file_path;
            }
        }

        return $missing_classes;
    }

    /**
     * Get autoloader statistics for debugging
     *
     * @return array Autoloader statistics
     */
    public function get_statistics() {
        $direct_mappings = $this->get_direct_class_map();
        $missing_classes = $this->validate_classes();

        // Count classes by directory
        $directory_counts = array();
        foreach ($direct_mappings as $class_name => $file_path) {
            $directory = dirname($file_path);
            $directory_counts[$directory] = ($directory_counts[$directory] ?? 0) + 1;
        }

        return array(
            'total_direct_mappings' => count($direct_mappings),
            'missing_classes' => count($missing_classes),
            'base_directory' => $this->base_dir,
            'namespace_prefix' => $this->namespace_prefix,
            'directory_counts' => $directory_counts,
            'registered_classes' => array_keys($direct_mappings),
            'missing_files' => $missing_classes
        );
    }

    /**
     * Generate autoloader report
     *
     * @return string Autoloader report
     */
    public function generate_report() {
        $stats = $this->get_statistics();
        $report = array();

        $report[] = "BNA Autoloader Report";
        $report[] = "=====================";
        $report[] = "Total Classes: " . $stats['total_direct_mappings'];
        $report[] = "Missing Files: " . $stats['missing_classes'];
        $report[] = "Base Directory: " . $stats['base_directory'];
        $report[] = "";

        $report[] = "Classes by Directory:";
        foreach ($stats['directory_counts'] as $dir => $count) {
            $report[] = "  " . $dir . ": " . $count . " classes";
        }
        $report[] = "";

        if (!empty($stats['missing_files'])) {
            $report[] = "Missing Files:";
            foreach ($stats['missing_files'] as $class => $file) {
                $report[] = "  " . $class . " -> " . $file;
            }
        }

        return implode("\n", $report);
    }
}