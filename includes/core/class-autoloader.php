<?php
/**
 * BNA Bridge Autoloader
 * Handles automatic loading of plugin classes
 *
 * @package BNA_Payment_Bridge
 * @subpackage Core
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader class
 * Automatically loads classes when needed
 */
class BNA_Bridge_Autoloader {

    /**
     * Class name prefix
     *
     * @var string
     */
    private $prefix = 'BNA_Bridge_';

    /**
     * Constructor
     * Register the autoloader
     *
     * @return void
     */
    public function __construct() {
        spl_autoload_register(array($this, 'load_class'));
    }

    /**
     * Load class file
     * Convert class name to file path and include it
     *
     * @param string $class_name The class name to load
     * @return bool True if class was loaded, false otherwise
     */
    public function load_class($class_name) {
        // Check if this is our class
        if (strpos($class_name, $this->prefix) !== 0) {
            return false;
        }

        // Remove prefix and convert to lowercase with hyphens
        $class_name = substr($class_name, strlen($this->prefix));
        $class_name = strtolower(str_replace('_', '-', $class_name));

        // Determine the directory based on class name
        $directory = $this->get_class_directory($class_name);

        // Build file path
        $file_name = 'class-' . $class_name . '.php';
        $file_path = BNA_BRIDGE_PLUGIN_PATH . 'includes/' . $directory . '/' . $file_name;

        // Load the file if it exists
        if (file_exists($file_path)) {
            require_once $file_path;
            return true;
        }

        return false;
    }

    /**
     * Get directory for class based on name
     * Determine which directory contains the class file
     *
     * @param string $class_name The class name (without prefix, lowercase with hyphens)
     * @return string Directory path
     */
    private function get_class_directory($class_name) {
        // Admin classes
        if (strpos($class_name, 'admin-') === 0) {
            return 'admin';
        }

        // API classes
        if (strpos($class_name, 'api-') === 0) {
            return 'api';
        }

        // Frontend classes
        if (strpos($class_name, 'frontend-') === 0) {
            return 'frontend';
        }

        // WooCommerce classes
        if (strpos($class_name, 'gateway') !== false) {
            return 'woocommerce';
        }

        // Default to core
        return 'core';
    }

}
