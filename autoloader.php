<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Gateway Autoloader - Simplified Version
 */
class BNA_Autoloader {

    private static $instance = null;
    private $namespace_prefix = 'BNA_';
    private $base_dir;

    private function __construct() {
        $this->base_dir = BNA_GATEWAY_PLUGIN_PATH . 'includes/';
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register() {
        spl_autoload_register(array($this, 'autoload'));
    }

    public function autoload($class_name) {
        if (strpos($class_name, $this->namespace_prefix) !== 0) {
            return;
        }

        $file_path = $this->get_file_path($class_name);

        if ($file_path && file_exists($file_path)) {
            require_once $file_path;
        }
    }

    private function get_file_path($class_name) {
        $class_map = $this->get_direct_class_map();

        if (isset($class_map[$class_name])) {
            return $this->base_dir . $class_map[$class_name];
        }

        return false;
    }

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

            // Testers
            'BNA_Portal_Tester' => 'testers/class-bna-portal-tester.php',

            // Admin Interface
            'BNA_Admin_Debug' => 'admin/class-bna-admin-debug.php',
            'BNA_Portal_Test_Page' => 'admin/class-bna-portal-test-page.php',

            // Simple Logging
            'BNA_Simple_Logger' => 'logging/class-bna-simple-logger.php',
            'BNA_Simple_Admin' => 'admin/class-bna-simple-admin.php',
        );
    }
}
