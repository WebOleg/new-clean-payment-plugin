<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Configuration Manager
 *
 * Centralized configuration management for BNA Gateway plugin.
 * Handles all plugin settings, validation, and dynamic configuration.
 * Supports dev/staging/production environments.
 */
class BNA_Config_Manager {

    /**
     * Gateway instance
     *
     * @var BNA_Gateway
     */
    private $gateway;

    /**
     * API endpoints configuration
     *
     * @var array
     */
    private $api_endpoints;

    /**
     * Default configuration values
     *
     * @var array
     */
    private $defaults;

    /**
     * Configuration sections
     *
     * @var array
     */
    private $sections;

    /**
     * Constructor
     *
     * @param BNA_Gateway $gateway Gateway instance
     */
    public function __construct($gateway = null) {
        $this->gateway = $gateway;
        $this->init_api_endpoints();
        $this->init_defaults();
        $this->init_sections();
    }

    /**
     * Initialize API endpoints for different environments
     *
     * @return void
     */
    private function init_api_endpoints() {
        $this->api_endpoints = array(
            'development' => 'https://dev-api-service.bnasmartpayment.com',
            'staging' => 'https://stage-api-service.bnasmartpayment.com',
            'production' => 'https://api.bnasmartpayment.com'
        );
    }

    /**
     * Initialize default configuration values
     *
     * @return void
     */
    private function init_defaults() {
        $this->defaults = array(
            // Basic settings
            'enabled' => 'no',
            'title' => 'BNA Smart Payment',
            'description' => 'Pay securely using BNA Smart Payment system.',
            'mode' => 'development',

            // API Configuration
            'iframe_id' => '',
            'access_key' => '',
            'secret_key' => '',

            // Payment setup
            'recurring_enabled' => 'no',
            'apply_fees' => 'no',
            'currency' => 'CAD',

            // Customer details collection
            'collect_phone' => 'no',
            'collect_billing_address' => 'yes',
            'collect_shipping_address' => 'no',
            'collect_birthdate' => 'no',

            // Payment methods
            'payment_methods' => array('card', 'e_transfer', 'eft'),
            'card_enabled' => 'yes',
            'e_transfer_enabled' => 'yes',
            'eft_enabled' => 'yes',

            // Advanced options
            'show_cart_details' => 'no',
            'allow_new_customers' => 'yes',
            'allow_business_customers' => 'no',
            'allow_save_payment_details' => 'no',
            'terms_url' => '',
            'privacy_url' => ''
        );
    }

    /**
     * Initialize configuration sections
     *
     * @return void
     */
    private function init_sections() {
        $this->sections = array(
            'basic' => array(
                'title' => 'Basic Settings',
                'fields' => array('enabled', 'title', 'description', 'mode')
            ),
            'api' => array(
                'title' => 'API Configuration',
                'fields' => array('iframe_id', 'access_key', 'secret_key')
            ),
            'payment_setup' => array(
                'title' => 'Payment Setup',
                'fields' => array('recurring_enabled', 'apply_fees', 'currency')
            ),
            'customer_details' => array(
                'title' => 'Customer Details Collection',
                'fields' => array('collect_phone', 'collect_billing_address', 'collect_shipping_address', 'collect_birthdate')
            ),
            'payment_methods' => array(
                'title' => 'Payment Methods',
                'fields' => array('card_enabled', 'e_transfer_enabled', 'eft_enabled')
            ),
            'advanced' => array(
                'title' => 'Advanced Options',
                'fields' => array('show_cart_details', 'allow_new_customers', 'allow_business_customers', 'allow_save_payment_details', 'terms_url', 'privacy_url')
            )
        );
    }

    /**
     * Get API URL for current mode
     *
     * @param string $mode Environment mode (development/staging/production)
     * @return string API URL
     */
    public function get_api_url($mode = null) {
        if (null === $mode && $this->gateway) {
            $mode = $this->gateway->get_option('mode', 'development');
        }

        if (!isset($this->api_endpoints[$mode])) {
            error_log('BNA Config: Invalid mode "' . $mode . '", falling back to development');
            $mode = 'development';
        }

        return $this->api_endpoints[$mode];
    }

    /**
     * Get all API endpoints
     *
     * @return array API endpoints
     */
    public function get_api_endpoints() {
        return $this->api_endpoints;
    }

    /**
     * Get default value for setting
     *
     * @param string $key Setting key
     * @return mixed Default value
     */
    public function get_default($key) {
        return isset($this->defaults[$key]) ? $this->defaults[$key] : null;
    }

    /**
     * Get all default values
     *
     * @return array Default values
     */
    public function get_defaults() {
        return $this->defaults;
    }

    /**
     * Get configuration sections
     *
     * @return array Configuration sections
     */
    public function get_sections() {
        return $this->sections;
    }

    /**
     * Get fields for specific section
     *
     * @param string $section Section name
     * @return array Section fields
     */
    public function get_section_fields($section) {
        return isset($this->sections[$section]['fields']) ? $this->sections[$section]['fields'] : array();
    }

    /**
     * Validate mode value
     *
     * @param string $mode Mode to validate
     * @return bool Is valid mode
     */
    public function is_valid_mode($mode) {
        return isset($this->api_endpoints[$mode]);
    }

    /**
     * Get available modes for form options
     *
     * @return array Mode options for forms
     */
    public function get_mode_options() {
        return array(
            'development' => 'Development (Testing)',
            'staging' => 'Staging (Pre-production)',
            'production' => 'Production (Live)'
        );
    }

    /**
     * Check if current mode is production
     *
     * @return bool Is production mode
     */
    public function is_production() {
        $mode = $this->gateway ? $this->gateway->get_option('mode', 'development') : 'development';
        return $mode === 'production';
    }

    /**
     * Check if current mode is development
     *
     * @return bool Is development mode
     */
    public function is_development() {
        $mode = $this->gateway ? $this->gateway->get_option('mode', 'development') : 'development';
        return $mode === 'development';
    }

    /**
     * Get environment label for display
     *
     * @param string $mode Mode to get label for
     * @return string Environment label
     */
    public function get_mode_label($mode = null) {
        if (null === $mode && $this->gateway) {
            $mode = $this->gateway->get_option('mode', 'development');
        }

        $labels = $this->get_mode_options();
        return isset($labels[$mode]) ? $labels[$mode] : 'Unknown';
    }
}