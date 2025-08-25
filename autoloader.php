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
     */
    public function register() {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Autoload class files
     *
     * @param string $class_name
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
     *
     * @param string $class_name
     * @return string|false
     */
    private function get_file_path($class_name) {
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

        return $this->get_file_path_by_pattern($class_name);
    }

    /**
     * Get file path by pattern matching
     *
     * @param string $class_name
     * @return string|false
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