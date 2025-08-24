<?php
/**
 * BNA Bridge Helper
 * Common utility functions and helpers
 * 
 * @package BNA_Payment_Bridge
 * @subpackage Core
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper class
 * Provides common utility functions
 */
class BNA_Bridge_Helper {
    
    /**
     * Get plugin settings
     * Retrieve plugin configuration options
     * 
     * @param string $key Optional specific setting key
     * @return mixed Settings array or specific value
     */
    public static function get_settings($key = null) {
        $settings = get_option('bna_bridge_settings', array());
        
        if ($key !== null) {
            return isset($settings[$key]) ? $settings[$key] : null;
        }
        
        return $settings;
    }
    
    /**
     * Update plugin settings
     * Save plugin configuration options
     * 
     * @param array $new_settings New settings to save
     * @return bool True on success, false on failure
     */
    public static function update_settings($new_settings) {
        $current_settings = self::get_settings();
        $updated_settings = wp_parse_args($new_settings, $current_settings);
        
        return update_option('bna_bridge_settings', $updated_settings);
    }
    
    /**
     * Log message
     * Write message to debug log if WP_DEBUG is enabled
     * 
     * @param mixed $message Message to log
     * @param string $level Log level (error, warning, info, debug)
     * @return void
     */
    public static function log($message, $level = 'info') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $log_message = '[BNA Bridge] (' . strtoupper($level) . ') ';
        
        if (is_array($message) || is_object($message)) {
            $log_message .= print_r($message, true);
        } else {
            $log_message .= $message;
        }
        
        error_log($log_message);
    }
    
    /**
     * Check if WooCommerce is active
     * Verify WooCommerce plugin availability
     * 
     * @return bool True if WooCommerce is active
     */
    public static function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Get current page URL
     * Get the current page URL for iframe domain validation
     * 
     * @return string Current page URL
     */
    public static function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }
}
