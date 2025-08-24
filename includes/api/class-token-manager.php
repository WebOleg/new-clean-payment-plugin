<?php
/**
 * BNA Token Manager
 * Manages checkout tokens for iframe integration
 * 
 * @package BNA_Payment_Bridge
 * @subpackage API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Token Manager class
 * Handles token creation, caching, and validation
 */
class BNA_Bridge_Token_Manager {
    
    /**
     * Token cache duration in seconds (25 minutes - tokens expire in 30)
     * 
     * @var int
     */
    private $cache_duration = 1500;
    
    /**
     * Cache prefix for tokens
     * 
     * @var string
     */
    private $cache_prefix = 'bna_bridge_token_';
    
    /**
     * API client instance
     * 
     * @var BNA_Bridge_API_Client
     */
    private $api_client;
    
    /**
     * Constructor
     * Initialize token manager with API client
     * 
     * @param BNA_Bridge_API_Client $api_client API client instance
     */
    public function __construct($api_client = null) {
        $this->api_client = $api_client;
    }
    
    /**
     * Set API client
     * Allows injecting API client after construction
     * 
     * @param BNA_Bridge_API_Client $api_client API client instance
     * @return void
     */
    public function set_api_client($api_client) {
        $this->api_client = $api_client;
    }
    
    /**
     * Generate cache key
     * Create unique cache key for checkout data
     * 
     * @param array $checkout_data Checkout data
     * @return string Cache key
     */
    private function generate_cache_key($checkout_data) {
        // Create hash from important checkout data
        $key_data = array(
            'iframe_id' => $checkout_data['iframeId'] ?? '',
            'customer_email' => $checkout_data['customerInfo']['email'] ?? '',
            'subtotal' => $checkout_data['subtotal'] ?? 0,
            'items_count' => count($checkout_data['items'] ?? array())
        );
        
        $hash = md5(wp_json_encode($key_data));
        return $this->cache_prefix . $hash;
    }
    
    /**
     * Get cached token
     * Retrieve token from WordPress transient cache
     * 
     * @param string $cache_key Cache key
     * @return array|false Cached token data or false if not found
     */
    private function get_cached_token($cache_key) {
        $cached_data = get_transient($cache_key);
        
        if ($cached_data && is_array($cached_data)) {
            BNA_Bridge_Helper::log("Found cached token for key: {$cache_key}", 'debug');
            return $cached_data;
        }
        
        return false;
    }
    
    /**
     * Cache token
     * Store token in WordPress transient cache
     * 
     * @param string $cache_key Cache key
     * @param array $token_data Token data to cache
     * @return bool True on success
     */
    private function cache_token($cache_key, $token_data) {
        $cache_data = array(
            'token' => $token_data['token'],
            'created_at' => time(),
            'expires_at' => time() + $this->cache_duration
        );
        
        $result = set_transient($cache_key, $cache_data, $this->cache_duration);
        
        if ($result) {
            BNA_Bridge_Helper::log("Token cached successfully with key: {$cache_key}", 'debug');
        } else {
            BNA_Bridge_Helper::log("Failed to cache token with key: {$cache_key}", 'warning');
        }
        
        return $result;
    }
    
    /**
     * Validate checkout data
     * Ensure required fields are present
     * 
     * @param array $checkout_data Checkout data to validate
     * @return bool|WP_Error True if valid, error otherwise
     */
    private function validate_checkout_data($checkout_data) {
        // Required fields
        $required_fields = array('iframeId', 'customerInfo', 'items', 'subtotal');
        
        foreach ($required_fields as $field) {
            if (!isset($checkout_data[$field])) {
                return new WP_Error('missing_field', "Required field '{$field}' is missing");
            }
        }
        
        // Validate customer info
        if (empty($checkout_data['customerInfo']['email'])) {
            return new WP_Error('missing_customer_email', 'Customer email is required');
        }
        
        // Validate items
        if (empty($checkout_data['items']) || !is_array($checkout_data['items'])) {
            return new WP_Error('invalid_items', 'At least one item is required');
        }
        
        // Validate subtotal
        if (!is_numeric($checkout_data['subtotal']) || $checkout_data['subtotal'] <= 0) {
            return new WP_Error('invalid_subtotal', 'Valid subtotal is required');
        }
        
        return true;
    }
    
    /**
     * Get or create token
     * Retrieve cached token or create new one
     * 
     * @param array $checkout_data Checkout data for token creation
     * @param bool $force_refresh Force new token creation
     * @return array|WP_Error Token data or error
     */
    public function get_or_create_token($checkout_data, $force_refresh = false) {
        BNA_Bridge_Helper::log("Getting or creating token...", 'info');
        
        // Validate checkout data
        $validation = $this->validate_checkout_data($checkout_data);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Check if API client is available
        if (!$this->api_client) {
            return new WP_Error('no_api_client', 'API client not initialized');
        }
        
        $cache_key = $this->generate_cache_key($checkout_data);
        
        // Try to get cached token first (unless forced refresh)
        if (!$force_refresh) {
            $cached_token = $this->get_cached_token($cache_key);
            
            if ($cached_token && $cached_token['expires_at'] > time()) {
                BNA_Bridge_Helper::log("Using cached token", 'info');
                return array(
                    'token' => $cached_token['token'],
                    'expires_at' => $cached_token['expires_at'],
                    'from_cache' => true
                );
            }
        }
        
        // Create new token via API
        BNA_Bridge_Helper::log("Creating new token via API", 'info');
        $token_response = $this->api_client->create_checkout_token($checkout_data);
        
        if (is_wp_error($token_response)) {
            return $token_response;
        }
        
        // Cache the new token
        $this->cache_token($cache_key, $token_response);
        
        return array(
            'token' => $token_response['token'],
            'expires_at' => time() + $this->cache_duration,
            'from_cache' => false
        );
    }
    
    /**
     * Clear token cache
     * Remove cached token by key or clear all tokens
     * 
     * @param string $cache_key Specific cache key to clear (optional)
     * @return void
     */
    public function clear_cache($cache_key = null) {
        if ($cache_key) {
            delete_transient($cache_key);
            BNA_Bridge_Helper::log("Cleared token cache for key: {$cache_key}", 'debug');
        } else {
            // Clear all BNA token caches
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $this->cache_prefix . '%'
            ));
            BNA_Bridge_Helper::log("Cleared all BNA token caches", 'info');
        }
    }
    
    /**
     * Get cache statistics
     * Return information about cached tokens
     * 
     * @return array Cache statistics
     */
    public function get_cache_stats() {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $this->cache_prefix . '%'
        ));
        
        return array(
            'cached_tokens' => (int) $count,
            'cache_duration' => $this->cache_duration,
            'cache_prefix' => $this->cache_prefix
        );
    }
}
