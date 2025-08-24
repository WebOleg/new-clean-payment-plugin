<?php
/**
 * BNA API Integration
 * Main interface for BNA Smart Payment API integration
 * 
 * @package BNA_Payment_Bridge
 * @subpackage API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA API Integration class
 * Combines API client and token manager for easy integration
 */
class BNA_Bridge_API_Integration {
    
    /**
     * API client instance
     * 
     * @var BNA_Bridge_API_Client
     */
    private $api_client;
    
    /**
     * Token manager instance
     * 
     * @var BNA_Bridge_Token_Manager
     */
    private $token_manager;
    
    /**
     * Gateway settings
     * 
     * @var array
     */
    private $settings;
    
    /**
     * Constructor
     * Initialize API integration with gateway settings
     * 
     * @param array $settings Gateway settings
     */
    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->init_api_components();
    }
    
    /**
     * Initialize API components
     * Create API client and token manager instances
     * 
     * @return void
     */
    private function init_api_components() {
        // Extract credentials from settings
        $access_key = $this->settings['access_key'] ?? '';
        $secret_key = $this->settings['secret_key'] ?? '';
        $test_mode = ($this->settings['testmode'] ?? 'yes') === 'yes';
        
        // Initialize API client
        $this->api_client = new BNA_Bridge_API_Client($access_key, $secret_key, $test_mode);
        
        // Initialize token manager
        $this->token_manager = new BNA_Bridge_Token_Manager($this->api_client);
        
        BNA_Bridge_Helper::log("API integration initialized with test_mode: " . ($test_mode ? 'true' : 'false'), 'info');
    }
    
    /**
     * Update settings
     * Update API integration settings and reinitialize components
     * 
     * @param array $new_settings New gateway settings
     * @return void
     */
    public function update_settings($new_settings) {
        $this->settings = $new_settings;
        $this->init_api_components();
        BNA_Bridge_Helper::log("API integration settings updated", 'info');
    }
    
    /**
     * Test connection
     * Test API connection and credentials
     * 
     * @return array Test result with success flag and message
     */
    public function test_connection() {
        BNA_Bridge_Helper::log("Testing BNA API connection...", 'info');
        
        $result = $this->api_client->test_connection();
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Connection successful! Your BNA API credentials are working.', BNA_BRIDGE_TEXT_DOMAIN),
            'endpoint' => $this->api_client->get_base_url()
        );
    }
    
    /**
     * Prepare checkout data
     * Convert WooCommerce order data to BNA API format
     * 
     * @param WC_Order $order WooCommerce order
     * @param array $additional_data Additional data to include
     * @return array Formatted checkout data
     */
    public function prepare_checkout_data($order = null, $additional_data = array()) {
        $iframe_id = $this->settings['iframe_id'] ?? '';
        
        if (empty($iframe_id)) {
            BNA_Bridge_Helper::log("Missing iframe ID in settings", 'error');
            return array();
        }
        
        // Base checkout data
        $checkout_data = array(
            'iframeId' => $iframe_id
        );
        
        // Add customer info if order is provided
        if ($order && $order instanceof WC_Order) {
            $checkout_data = array_merge($checkout_data, $this->format_order_data($order));
        } elseif (!empty($additional_data['customer_info'])) {
            $checkout_data['customerInfo'] = $additional_data['customer_info'];
        }
        
        // Add items if order is provided
        if ($order && $order instanceof WC_Order) {
            $checkout_data['items'] = $this->format_order_items($order);
            $checkout_data['subtotal'] = (float) $order->get_subtotal();
        } elseif (!empty($additional_data['items']) && !empty($additional_data['subtotal'])) {
            $checkout_data['items'] = $additional_data['items'];
            $checkout_data['subtotal'] = (float) $additional_data['subtotal'];
        }
        
        // Merge any additional data
        if (!empty($additional_data)) {
            $checkout_data = array_merge($checkout_data, $additional_data);
        }
        
        BNA_Bridge_Helper::log("Prepared checkout data: " . wp_json_encode($checkout_data), 'debug');
        
        return $checkout_data;
    }
    
    /**
     * Format order data
     * Convert WooCommerce order to customer info format
     * 
     * @param WC_Order $order WooCommerce order
     * @return array Customer info data
     */
    private function format_order_data($order) {
        $customer_type = 'Personal'; // Default to Personal, can be enhanced later
        
        $customer_info = array(
            'customerInfo' => array(
                'type' => $customer_type,
                'email' => $order->get_billing_email(),
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name(),
                'phoneCode' => '+1', // Default, can be enhanced
                'phoneNumber' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
                'address' => array(
                    'streetName' => $order->get_billing_address_1(),
                    'streetNumber' => '', // Can be extracted from address_1 if needed
                    'apartment' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'province' => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'postalCode' => $order->get_billing_postcode()
                )
            )
        );
        
        return $customer_info;
    }
    
    /**
     * Format order items
     * Convert WooCommerce order items to BNA API format
     * 
     * @param WC_Order $order WooCommerce order
     * @return array Formatted items
     */
    private function format_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            $items[] = array(
                'description' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : $item->get_id(),
                'price' => (float) $order->get_item_subtotal($item, false),
                'quantity' => (int) $item->get_quantity(),
                'amount' => (float) $order->get_line_subtotal($item, false)
            );
        }
        
        return $items;
    }
    
    /**
     * Generate iframe token
     * Get or create token for iframe integration
     * 
     * @param array $checkout_data Checkout data
     * @param bool $force_refresh Force new token creation
     * @return array|WP_Error Token data or error
     */
    public function generate_iframe_token($checkout_data, $force_refresh = false) {
        BNA_Bridge_Helper::log("Generating iframe token...", 'info');
        
        if (empty($checkout_data)) {
            return new WP_Error('empty_checkout_data', 'Checkout data is required');
        }
        
        // Спробуємо створити токен з існуючими даними
        $result = $this->token_manager->get_or_create_token($checkout_data, $force_refresh);
        
        // Якщо отримали помилку про існуючого кастомера, спробуємо без customer data
        if (is_wp_error($result) && strpos($result->get_error_message(), 'Customer already exists') !== false) {
            BNA_Bridge_Helper::log("Customer exists error, trying without customerInfo", 'info');
            
            // Видаляємо customerInfo і спробуємо ще раз - використовуємо тільки customerId
            $checkout_data_without_customer = $checkout_data;
            if (isset($checkout_data_without_customer['customerInfo'])) {
                $email = $checkout_data_without_customer['customerInfo']['email'] ?? '';
                unset($checkout_data_without_customer['customerInfo']);
                
                // Можемо додати customerId якщо маємо, або просто пропустити
                if (!empty($email)) {
                    // Генеруємо простий ID з email для тестування
                    $checkout_data_without_customer['customerId'] = md5($email);
                }
            }
            
            $result = $this->token_manager->get_or_create_token($checkout_data_without_customer, $force_refresh);
        }
        
        if (is_wp_error($result)) {
            BNA_Bridge_Helper::log("Token generation failed: " . $result->get_error_message(), 'error');
        } else {
            BNA_Bridge_Helper::log("Token generated successfully", 'info');
        }
        
        return $result;
    }
    
    /**
     * Get iframe URL
     * Generate complete iframe URL with token
     * 
     * @param string $token Checkout token
     * @return string Complete iframe URL
     */
    public function get_iframe_url($token) {
        $base_url = $this->api_client->get_base_url();
        return $base_url . '/v1/checkout/' . $token;
    }
    
    /**
     * Clear token cache
     * Clear cached tokens
     * 
     * @return void
     */
    public function clear_cache() {
        $this->token_manager->clear_cache();
        BNA_Bridge_Helper::log("API token cache cleared", 'info');
    }
    
    /**
     * Get integration status
     * Return current integration status and information
     * 
     * @return array Integration status
     */
    public function get_status() {
        $has_credentials = !empty($this->settings['access_key']) && !empty($this->settings['secret_key']);
        $has_iframe_id = !empty($this->settings['iframe_id']);
        
        $status = array(
            'configured' => $has_credentials && $has_iframe_id,
            'has_credentials' => $has_credentials,
            'has_iframe_id' => $has_iframe_id,
            'test_mode' => ($this->settings['testmode'] ?? 'yes') === 'yes',
            'endpoint' => $this->api_client->get_base_url(),
            'cache_stats' => $this->token_manager->get_cache_stats()
        );
        
        return $status;
    }
    
    /**
     * Get API client
     * Return API client instance for advanced usage
     * 
     * @return BNA_Bridge_API_Client
     */
    public function get_api_client() {
        return $this->api_client;
    }
    
    /**
     * Get token manager
     * Return token manager instance for advanced usage
     * 
     * @return BNA_Bridge_Token_Manager
     */
    public function get_token_manager() {
        return $this->token_manager;
    }
}
