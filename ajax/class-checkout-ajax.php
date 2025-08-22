<?php
/**
 * BNA Checkout AJAX Handler
 * Handles AJAX requests for checkout operations and fee calculations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Checkout_Ajax
 * Manages all AJAX operations related to checkout functionality
 */
class BNA_Checkout_Ajax {

    private static $instance = null;
    private $validator;

    /**
     * Get singleton instance
     * @return BNA_Checkout_Ajax
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup dependencies
     */
    private function __construct() {
        $this->validator = BNA_Validator::get_instance();
    }

    /**
     * Initialize AJAX hooks
     */
    public function init() {
        // Logged in users
        add_action('wp_ajax_bna_calculate_fees', array($this, 'calculate_fees'));
        add_action('wp_ajax_bna_validate_checkout_data', array($this, 'validate_checkout_data'));
        add_action('wp_ajax_bna_update_payment_method', array($this, 'update_payment_method'));
        add_action('wp_ajax_bna_get_payment_methods', array($this, 'get_payment_methods'));
        
        // Non-logged in users (for guest checkout)
        add_action('wp_ajax_nopriv_bna_calculate_fees', array($this, 'calculate_fees'));
        add_action('wp_ajax_nopriv_bna_validate_checkout_data', array($this, 'validate_checkout_data'));
        add_action('wp_ajax_nopriv_bna_update_payment_method', array($this, 'update_payment_method'));
        add_action('wp_ajax_nopriv_bna_get_payment_methods', array($this, 'get_payment_methods'));
    }

    /**
     * Calculate fees based on payment method and order total
     */
    public function calculate_fees() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
            $order_total = floatval($_POST['order_total'] ?? 0);
            $currency = sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());

            if (empty($payment_method)) {
                throw new Exception('Payment method is required');
            }

            if ($order_total <= 0) {
                throw new Exception('Invalid order total');
            }

            // Get gateway settings
            $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
            
            if ($gateway_settings['enable_fees'] !== 'yes') {
                wp_send_json_success(array(
                    'fees_enabled' => false,
                    'flat_fee' => 0,
                    'percentage_fee' => 0,
                    'total_fee' => 0,
                    'tax_amount' => 0,
                    'fee_with_tax' => 0,
                    'new_total' => $order_total
                ));
                return;
            }

            // Calculate fees based on payment method
            $fee_data = $this->calculate_payment_fees($payment_method, $order_total, $gateway_settings);

            BNA_Logger::info('Fees calculated', array(
                'payment_method' => $payment_method,
                'order_total' => $order_total,
                'total_fee' => $fee_data['total_fee']
            ), 'checkout_ajax');

            wp_send_json_success($fee_data);

        } catch (Exception $e) {
            BNA_Logger::error('Fee calculation failed', array(
                'error' => $e->getMessage(),
                'payment_method' => $payment_method ?? 'unknown',
                'order_total' => $order_total ?? 0
            ), 'checkout_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'fee_calculation_failed'
            ));
        }
    }

    /**
     * Validate checkout data before processing
     */
    public function validate_checkout_data() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            // Get and sanitize checkout data
            $checkout_data = $this->get_sanitized_checkout_data();

            // Validate all required fields
            $validation_result = $this->validate_all_checkout_fields($checkout_data);
            
            if (is_wp_error($validation_result)) {
                wp_send_json_error(array(
                    'message' => $validation_result->get_error_message(),
                    'code' => 'validation_failed',
                    'field_errors' => $validation_result->get_error_data()
                ));
                return;
            }

            // Additional business logic validation
            $business_validation = $this->validate_business_rules($checkout_data);
            
            if (is_wp_error($business_validation)) {
                wp_send_json_error(array(
                    'message' => $business_validation->get_error_message(),
                    'code' => 'business_validation_failed'
                ));
                return;
            }

            BNA_Logger::info('Checkout data validation successful', array(
                'customer_type' => $checkout_data['customer_type'],
                'payment_method' => $checkout_data['payment_method']
            ), 'checkout_ajax');

            wp_send_json_success(array(
                'message' => 'Validation successful',
                'validated_data' => $checkout_data
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Checkout validation failed', array(
                'error' => $e->getMessage()
            ), 'checkout_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'validation_error'
            ));
        }
    }

    /**
     * Update payment method and recalculate totals
     */
    public function update_payment_method() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
            $order_total = floatval($_POST['order_total'] ?? 0);

            if (empty($payment_method)) {
                throw new Exception('Payment method is required');
            }

            // Get available payment methods from settings
            $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
            $available_methods = $gateway_settings['payment_methods'] ?? array('card', 'eft');

            if (!in_array($payment_method, $available_methods)) {
                throw new Exception('Payment method not available');
            }

            // Calculate fees for new payment method
            $fee_data = array();
            if ($gateway_settings['enable_fees'] === 'yes') {
                $fee_data = $this->calculate_payment_fees($payment_method, $order_total, $gateway_settings);
            }

            // Get payment method specific information
            $method_info = $this->get_payment_method_info($payment_method);

            BNA_Logger::info('Payment method updated', array(
                'payment_method' => $payment_method,
                'order_total' => $order_total
            ), 'checkout_ajax');

            wp_send_json_success(array(
                'payment_method' => $payment_method,
                'method_info' => $method_info,
                'fees' => $fee_data,
                'message' => 'Payment method updated successfully'
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Payment method update failed', array(
                'error' => $e->getMessage(),
                'payment_method' => $payment_method ?? 'unknown'
            ), 'checkout_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'payment_method_update_failed'
            ));
        }
    }

    /**
     * Get available payment methods and their details
     */
    public function get_payment_methods() {
        try {
            // Verify nonce
            if (!$this->verify_nonce('bna_checkout')) {
                throw new Exception('Security verification failed');
            }

            $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
            $available_methods = $gateway_settings['payment_methods'] ?? array('card', 'eft');

            $methods_data = array();

            foreach ($available_methods as $method) {
                $methods_data[$method] = $this->get_payment_method_info($method);
            }

            wp_send_json_success(array(
                'payment_methods' => $methods_data,
                'default_method' => $available_methods[0] ?? 'card'
            ));

        } catch (Exception $e) {
            BNA_Logger::error('Failed to get payment methods', array(
                'error' => $e->getMessage()
            ), 'checkout_ajax');

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'payment_methods_failed'
            ));
        }
    }

    /**
     * Calculate payment fees based on method and total
     * @param string $payment_method
     * @param float $order_total
     * @param array $settings
     * @return array
     */
    private function calculate_payment_fees($payment_method, $order_total, $settings) {
        $flat_fee = 0;
        $percentage_fee = 0;

        // Get fees based on payment method
        switch ($payment_method) {
            case 'card':
                $flat_fee = floatval($settings['card_fee_flat'] ?? 0);
                $percentage_fee = floatval($settings['card_fee_percent'] ?? 0);
                break;
            case 'eft':
                $flat_fee = floatval($settings['eft_fee_flat'] ?? 0);
                $percentage_fee = floatval($settings['eft_fee_percent'] ?? 0);
                break;
            case 'e-transfer':
                $flat_fee = floatval($settings['etransfer_fee_flat'] ?? 0);
                $percentage_fee = 0; // E-transfer typically has no percentage fee
                break;
        }

        // Calculate total fee
        $percentage_amount = ($order_total * $percentage_fee) / 100;
        $total_fee = $flat_fee + $percentage_amount;

        // Calculate tax on fee (assume 13% HST for Canada)
        $tax_rate = 0.13;
        $tax_amount = $total_fee * $tax_rate;
        $fee_with_tax = $total_fee + $tax_amount;

        // Calculate new order total
        $new_total = $order_total + $fee_with_tax;

        return array(
            'fees_enabled' => true,
            'payment_method' => $payment_method,
            'flat_fee' => round($flat_fee, 2),
            'percentage_fee' => $percentage_fee,
            'percentage_amount' => round($percentage_amount, 2),
            'total_fee' => round($total_fee, 2),
            'tax_rate' => $tax_rate,
            'tax_amount' => round($tax_amount, 2),
            'fee_with_tax' => round($fee_with_tax, 2),
            'original_total' => round($order_total, 2),
            'new_total' => round($new_total, 2)
        );
    }

    /**
     * Get sanitized checkout data from POST
     * @return array
     */
    private function get_sanitized_checkout_data() {
        return array(
            'customer_type' => sanitize_text_field($_POST['customer_type'] ?? 'Personal'),
            'payment_method' => sanitize_text_field($_POST['payment_method'] ?? 'card'),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'company' => sanitize_text_field($_POST['company'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'phone_code' => sanitize_text_field($_POST['phone_code'] ?? '+1'),
            'address_1' => sanitize_text_field($_POST['address_1'] ?? ''),
            'address_2' => sanitize_text_field($_POST['address_2'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'state' => sanitize_text_field($_POST['state'] ?? ''),
            'postcode' => sanitize_text_field($_POST['postcode'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? 'CA'),
            'order_total' => floatval($_POST['order_total'] ?? 0),
            'currency' => sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency()),
            'terms_accepted' => !empty($_POST['terms_accepted'])
        );
    }

    /**
     * Validate all checkout fields
     * @param array $data
     * @return bool|WP_Error
     */
    private function validate_all_checkout_fields($data) {
        $errors = array();

        // Required field validation
        $required_fields = array(
            'email' => 'Email address',
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'phone' => 'Phone number',
            'address_1' => 'Street address',
            'city' => 'City',
            'state' => 'State/Province',
            'postcode' => 'Postal code',
            'country' => 'Country'
        );

        foreach ($required_fields as $field => $label) {
            if (empty($data[$field])) {
                $errors[$field] = $label . ' is required';
            }
        }

        // Email validation
        if (!empty($data['email']) && !$this->validator->validate_email($data['email'])) {
            $errors['email'] = 'Please enter a valid email address';
        }

        // Phone validation
        if (!empty($data['phone']) && !$this->validator->validate_phone($data['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number';
        }

        // Postal code validation
        if (!empty($data['postcode']) && !empty($data['country'])) {
            if (!$this->validator->validate_postal_code($data['postcode'], $data['country'])) {
                $errors['postcode'] = 'Please enter a valid postal code';
            }
        }

        // Order total validation
        if ($data['order_total'] <= 0) {
            $errors['order_total'] = 'Order total must be greater than zero';
        } elseif (!$this->validator->validate_amount($data['order_total'], $data['currency'])) {
            $errors['order_total'] = 'Invalid order amount';
        }

        // Terms acceptance validation
        if (!$data['terms_accepted']) {
            $errors['terms_accepted'] = 'You must accept the terms and conditions';
        }

        // Company field validation for business customers
        if ($data['customer_type'] === 'Business' && empty($data['company'])) {
            $errors['company'] = 'Company name is required for business customers';
        }

        if (!empty($errors)) {
            return new WP_Error('field_validation_failed', 'Please correct the following errors:', $errors);
        }

        return true;
    }

    /**
     * Validate business rules
     * @param array $data
     * @return bool|WP_Error
     */
    private function validate_business_rules($data) {
        // Check if payment method is available
        $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
        $available_methods = $gateway_settings['payment_methods'] ?? array('card', 'eft');
        
        if (!in_array($data['payment_method'], $available_methods)) {
            return new WP_Error('payment_method_unavailable', 'Selected payment method is not available');
        }

        // Check if customer type is allowed
        $available_types = $gateway_settings['customer_types'] ?? array('Personal', 'Business');
        
        if (!in_array($data['customer_type'], $available_types)) {
            return new WP_Error('customer_type_unavailable', 'Selected customer type is not available');
        }

        // Check currency support
        $supported_currencies = array('CAD', 'USD');
        if (!in_array($data['currency'], $supported_currencies)) {
            return new WP_Error('currency_not_supported', 'Currency not supported');
        }

        // Minimum order amount validation
        $min_amount = 1.00;
        if ($data['order_total'] < $min_amount) {
            return new WP_Error('amount_too_low', 'Order amount is below minimum required');
        }

        return true;
    }

    /**
     * Get payment method information
     * @param string $method
     * @return array
     */
    private function get_payment_method_info($method) {
        $methods_info = array(
            'card' => array(
                'label' => 'Credit/Debit Card',
                'description' => 'Pay securely with your credit or debit card',
                'icon' => 'card',
                'processing_time' => 'Instant',
                'supports_refunds' => true
            ),
            'eft' => array(
                'label' => 'Electronic Funds Transfer',
                'description' => 'Direct bank account transfer',
                'icon' => 'bank',
                'processing_time' => '1-2 business days',
                'supports_refunds' => true
            ),
            'e-transfer' => array(
                'label' => 'E-Transfer',
                'description' => 'Email money transfer',
                'icon' => 'email',
                'processing_time' => '30 minutes - 2 hours',
                'supports_refunds' => false
            )
        );

        return $methods_info[$method] ?? array(
            'label' => ucfirst($method),
            'description' => 'Payment method',
            'icon' => 'payment',
            'processing_time' => 'Unknown',
            'supports_refunds' => false
        );
    }

    /**
     * Verify nonce for security
     * @param string $action
     * @return bool
     */
    private function verify_nonce($action) {
        $nonce = $_POST['_wpnonce'] ?? $_POST['nonce'] ?? '';
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Get checkout session data
     * @return array
     */
    public function get_checkout_session() {
        $session_data = WC()->session->get('bna_checkout_data', array());
        
        return array(
            'customer_type' => $session_data['customer_type'] ?? 'Personal',
            'payment_method' => $session_data['payment_method'] ?? 'card',
            'phone_code' => $session_data['phone_code'] ?? '+1'
        );
    }

    /**
     * Save checkout session data
     * @param array $data
     */
    public function save_checkout_session($data) {
        $session_data = array(
            'customer_type' => $data['customer_type'] ?? 'Personal',
            'payment_method' => $data['payment_method'] ?? 'card',
            'phone_code' => $data['phone_code'] ?? '+1',
            'updated_at' => time()
        );

        WC()->session->set('bna_checkout_data', $session_data);
    }
}
