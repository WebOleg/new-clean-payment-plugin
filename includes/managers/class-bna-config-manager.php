<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Configuration Manager
 */
class BNA_Config_Manager {

    private $gateway;
    private $api_endpoints;
    private $defaults;
    private $sections;

    public function __construct($gateway = null) {
        $this->gateway = $gateway;
        $this->init_api_endpoints();
        $this->init_defaults();
        $this->init_sections();
    }

    /**
     * Initialize API endpoints for different environments
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
     */
    private function init_defaults() {
        $this->defaults = array(
            'enabled' => 'no',
            'title' => 'BNA Smart Payment',
            'description' => 'Pay securely using BNA Smart Payment system.',
            'mode' => 'development',
            'iframe_id' => '',
            'access_key' => '',
            'secret_key' => '',
            'recurring_enabled' => 'no',
            'apply_fees' => 'no',
            'currency' => 'CAD',
            'collect_phone' => 'no',
            'collect_billing_address' => 'yes',
            'collect_shipping_address' => 'no',
            'collect_birthdate' => 'no',
            'payment_methods' => array('card', 'e_transfer', 'eft'),
            'card_enabled' => 'yes',
            'e_transfer_enabled' => 'yes',
            'eft_enabled' => 'yes',
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
     */
    public function get_api_url($mode = null) {
        if (null === $mode && $this->gateway) {
            $mode = $this->gateway->get_option('mode', 'development');
        }

        if (!isset($this->api_endpoints[$mode])) {
            $mode = 'development';
        }

        return $this->api_endpoints[$mode];
    }

    /**
     * Get all API endpoints
     */
    public function get_api_endpoints() {
        return $this->api_endpoints;
    }

    /**
     * Get default value for setting
     */
    public function get_default($key) {
        return isset($this->defaults[$key]) ? $this->defaults[$key] : null;
    }

    /**
     * Get all default values
     */
    public function get_defaults() {
        return $this->defaults;
    }

    /**
     * Get configuration sections
     */
    public function get_sections() {
        return $this->sections;
    }

    /**
     * Get fields for specific section
     */
    public function get_section_fields($section) {
        return isset($this->sections[$section]['fields']) ? $this->sections[$section]['fields'] : array();
    }

    /**
     * Validate mode value
     */
    public function is_valid_mode($mode) {
        return isset($this->api_endpoints[$mode]);
    }

    /**
     * Get available modes for form options
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
     */
    public function is_production() {
        $mode = $this->gateway ? $this->gateway->get_option('mode', 'development') : 'development';
        return $mode === 'production';
    }

    /**
     * Check if current mode is development
     */
    public function is_development() {
        $mode = $this->gateway ? $this->gateway->get_option('mode', 'development') : 'development';
        return $mode === 'development';
    }

    /**
     * Get environment label for display
     */
    public function get_mode_label($mode = null) {
        if (null === $mode && $this->gateway) {
            $mode = $this->gateway->get_option('mode', 'development');
        }

        $labels = $this->get_mode_options();
        return isset($labels[$mode]) ? $labels[$mode] : 'Unknown';
    }
}
