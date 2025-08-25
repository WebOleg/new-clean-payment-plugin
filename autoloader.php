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
        // Check if class starts with our namespace
        if (strpos($class_name, $this->namespace_prefix) !== 0) {
            return;
        }

        // Get file path for the class
        $file_path = $this->get_file_path($class_name);
        
        if ($file_path && file_exists($file_path)) {
            require_once $file_path;
        }
    }

    private function get_file_path($class_name) {
        // Direct mapping of classes to file paths
        $class_map = array(
            'BNA_Gateway' => 'gateways/class-bna-gateway.php',
            'BNA_Ajax_Handler' => 'handlers/class-bna-ajax-handler.php',
            'BNA_Iframe_Renderer' => 'renderers/class-bna-iframe-renderer.php',
            'BNA_Api_Helper' => 'helpers/class-bna-api-helper.php',
            'BNA_Webhook_Listener' => 'listeners/class-bna-webhook-listener.php',
        );

        if (isset($class_map[$class_name])) {
            return $this->base_dir . $class_map[$class_name];
        }

        // Fallback to pattern matching
        return $this->get_file_path_by_pattern($class_name);
    }

    private function get_file_path_by_pattern($class_name) {
        // Remove BNA_ prefix
        $class_without_prefix = substr($class_name, strlen($this->namespace_prefix));
        
        // Convert to filename format: BNA_Ajax_Handler -> bna-ajax-handler
        $filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        
        // Directory mappings based on class patterns
        $directory_mappings = array(
            'Gateway' => 'gateways/',
            'Handler' => 'handlers/',
            'Renderer' => 'renderers/',
            'Listener' => 'listeners/',
            'Helper' => 'helpers/',
            'Api' => 'helpers/',
        );

        foreach ($directory_mappings as $pattern => $directory) {
            if (strpos($class_without_prefix, $pattern) !== false) {
                return $this->base_dir . $directory . $filename;
            }
        }

        return false;
    }
}
