<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Gateway Autoloader
 */
class BNA_Autoloader {

    private static $instance = null;
    private $namespace_prefix = 'BNA_';
    private $base_dir;

    private function __construct() {
        $this->base_dir = BNA_GATEWAY_PLUGIN_PATH . 'includes/';
    }

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register autoloader
     */
    public function register() {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Autoload class files
     */
    public function autoload($class_name) {
        if (strpos($class_name, $this->namespace_prefix) !== 0) {
            return;
        }

        $file_path = $this->get_file_path($class_name);

        if ($file_path && file_exists($file_path)) {
            require_once $file_path;
        }
    }

    /**
     * Get file path for class
     */
    private function get_file_path($class_name) {
        $class_map = $this->get_direct_class_map();

        if (isset($class_map[$class_name])) {
            return $this->base_dir . $class_map[$class_name];
        }

        return $this->get_file_path_by_pattern($class_name);
    }

    /**
     * Get direct class mapping
     */
    private function get_direct_class_map() {
        return array(
            'BNA_Gateway' => 'gateways/class-bna-gateway.php',
            'BNA_Ajax_Handler' => 'handlers/class-bna-ajax-handler.php',
            'BNA_Iframe_Renderer' => 'renderers/class-bna-iframe-renderer.php',
            'BNA_Api_Helper' => 'helpers/class-bna-api-helper.php',
            'BNA_Webhook_Listener' => 'listeners/class-bna-webhook-listener.php',
            'BNA_Config_Manager' => 'managers/class-bna-config-manager.php',
        );
    }

    /**
     * Get file path by pattern matching
     */
    private function get_file_path_by_pattern($class_name) {
        $class_without_prefix = substr($class_name, strlen($this->namespace_prefix));
        $filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';

        $directory_mappings = array(
            'Gateway' => 'gateways/',
            'Handler' => 'handlers/',
            'Renderer' => 'renderers/',
            'Listener' => 'listeners/',
            'Helper' => 'helpers/',
            'Manager' => 'managers/',
        );

        foreach ($directory_mappings as $pattern => $directory) {
            if (strpos($class_without_prefix, $pattern) !== false) {
                $file_path = $this->base_dir . $directory . $filename;
                if (file_exists($file_path)) {
                    return $file_path;
                }
            }
        }

        $root_file_path = $this->base_dir . $filename;
        if (file_exists($root_file_path)) {
            return $root_file_path;
        }

        return false;
    }
}
