<?php
/**
 * BNA Validator Class
 * Handles data validation for forms and API requests
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Validator
 * Validates user input, payment data, and API responses
 */
class BNA_Validator {

    private static $instance = null;
    private $errors = array();

    /**
     * Get singleton instance
     * @return BNA_Validator
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize
    }

    /**
     * Validate checkout form data
     * @param array $data
     * @return bool
     */
    public function validate_checkout_data($data) {
        $this->reset_errors();

        // Required fields
        $required_fields = array(
            'billing_first_name' => 'First name is required',
            'billing_last_name' => 'Last name is required',
            'billing_email' => 'Email is required',
            'billing_phone' => 'Phone number is required',
            'billing_city' => 'City is required',
            'billing_postcode' => 'Postal code is required',
            'billing_country' => 'Country is required'
        );

        foreach ($required_fields as $field => $message) {
            if (empty($data[$field])) {
                $this->add_error($field, $message);
            }
        }

        // Validate email
        if (!empty($data['billing_email']) && !$this->validate_email($data['billing_email'])) {
            $this->add_error('billing_email', 'Please enter a valid email address');
        }

        // Validate phone
        if (!empty($data['billing_phone']) && !$this->validate_phone($data['billing_phone'])) {
            $this->add_error('billing_phone', 'Please enter a valid phone number');
        }

        // Validate postal code
        if (!empty($data['billing_postcode']) && !empty($data['billing_country'])) {
            if (!$this->validate_postal_code($data['billing_postcode'], $data['billing_country'])) {
                $this->add_error('billing_postcode', 'Please enter a valid postal code');
            }
        }

        // Validate birth date if provided
        if (!empty($data['billing_birth_date']) && !$this->validate_birth_date($data['billing_birth_date'])) {
            $this->add_error('billing_birth_date', 'Please enter a valid birth date (YYYY-MM-DD)');
        }

        return empty($this->errors);
    }

    /**
     * Validate API credentials
     * @param string $access_key
     * @param string $secret_key
     * @return bool
     */
    public function validate_api_credentials($access_key, $secret_key) {
        $this->reset_errors();

        if (empty($access_key)) {
            $this->add_error('access_key', 'Access key is required');
        }

        if (empty($secret_key)) {
            $this->add_error('secret_key', 'Secret key is required');
        }

        // Basic format validation
        if (!empty($access_key) && strlen($access_key) < 10) {
            $this->add_error('access_key', 'Access key appears to be invalid');
        }

        if (!empty($secret_key) && strlen($secret_key) < 10) {
            $this->add_error('secret_key', 'Secret key appears to be invalid');
        }

        return empty($this->errors);
    }

    /**
     * Validate iframe configuration
     * @param string $iframe_id
     * @param string $environment
     * @return bool
     */
    public function validate_iframe_config($iframe_id, $environment) {
        $this->reset_errors();

        if (empty($iframe_id)) {
            $this->add_error('iframe_id', 'iFrame ID is required');
        }

        $valid_environments = array('staging', 'production');
        if (!in_array($environment, $valid_environments)) {
            $this->add_error('environment', 'Invalid environment selected');
        }

        return empty($this->errors);
    }

    /**
     * Validate payment amount
     * @param float $amount
     * @param string $currency
     * @return bool
     */
    public function validate_payment_amount($amount, $currency = 'CAD') {
        $this->reset_errors();

        if (!is_numeric($amount)) {
            $this->add_error('amount', 'Amount must be a valid number');
            return false;
        }

        $amount = (float) $amount;

        if ($amount <= 0) {
            $this->add_error('amount', 'Amount must be greater than zero');
        }

        // Maximum amount check
        $max_amount = 50000; // $50,000 CAD/USD
        if ($amount > $max_amount) {
            $this->add_error('amount', sprintf('Amount cannot exceed %s %s', number_format($max_amount, 2), $currency));
        }

        // Minimum amount check
        $min_amount = 0.01;
        if ($amount < $min_amount) {
            $this->add_error('amount', sprintf('Amount must be at least %s %s', number_format($min_amount, 2), $currency));
        }

        return empty($this->errors);
    }

    /**
     * Validate currency code
     * @param string $currency
     * @return bool
     */
    public function validate_currency($currency) {
        $supported_currencies = array('CAD', 'USD');
        return in_array(strtoupper($currency), $supported_currencies);
    }

    /**
     * Validate email address
     * @param string $email
     * @return bool
     */
    public function validate_email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Additional checks
        $domain = substr(strrchr($email, "@"), 1);
        if (!$domain || !checkdnsrr($domain, 'MX')) {
            return false;
        }

        return true;
    }

    /**
     * Validate phone number
     * @param string $phone
     * @return bool
     */
    public function validate_phone($phone) {
        // Remove all non-digit characters
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Check length (7-15 digits)
        if (strlen($phone_clean) < 7 || strlen($phone_clean) > 15) {
            return false;
        }

        return true;
    }

    /**
     * Validate postal code by country
     * @param string $postal_code
     * @param string $country
     * @return bool
     */
    public function validate_postal_code($postal_code, $country) {
        $postal_code = strtoupper(trim($postal_code));
        $country = strtoupper($country);

        switch ($country) {
            case 'CA':
                // Canadian postal code: A1A 1A1
                return preg_match('/^[A-Z][0-9][A-Z]\s?[0-9][A-Z][0-9]$/', $postal_code);
            
            case 'US':
                // US ZIP code: 12345 or 12345-6789
                return preg_match('/^[0-9]{5}(-[0-9]{4})?$/', $postal_code);
            
            default:
                // Basic validation for other countries
                return strlen($postal_code) >= 3 && strlen($postal_code) <= 10;
        }
    }

    /**
     * Validate birth date
     * @param string $birth_date
     * @return bool
     */
    public function validate_birth_date($birth_date) {
        // Check format YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            return false;
        }

        $date_parts = explode('-', $birth_date);
        $year = (int) $date_parts[0];
        $month = (int) $date_parts[1];
        $day = (int) $date_parts[2];

        // Check if valid date
        if (!checkdate($month, $day, $year)) {
            return false;
        }

        // Check age limits (18-120 years)
        $birth_timestamp = strtotime($birth_date);
        $current_timestamp = time();
        $age = floor(($current_timestamp - $birth_timestamp) / (365.25 * 24 * 3600));

        return $age >= 18 && $age <= 120;
    }

    /**
     * Validate webhook data
     * @param array $data
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    public function validate_webhook($data, $signature, $secret) {
        $this->reset_errors();

        if (empty($data)) {
            $this->add_error('webhook', 'Webhook data is empty');
            return false;
        }

        if (empty($signature)) {
            $this->add_error('webhook', 'Webhook signature is missing');
            return false;
        }

        // Verify signature
        $expected_signature = hash_hmac('sha256', wp_json_encode($data), $secret);
        if (!hash_equals($expected_signature, $signature)) {
            $this->add_error('webhook', 'Invalid webhook signature');
            return false;
        }

        // Validate required webhook fields
        $required_fields = array('transaction_id', 'status');
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->add_error('webhook', "Missing required field: {$field}");
            }
        }

        return empty($this->errors);
    }

    /**
     * Validate transaction ID
     * @param string $transaction_id
     * @return bool
     */
    public function validate_transaction_id($transaction_id) {
        if (empty($transaction_id)) {
            return false;
        }

        // Basic format check
        if (strlen($transaction_id) < 10 || strlen($transaction_id) > 100) {
            return false;
        }

        // Allow alphanumeric, hyphens, underscores
        return preg_match('/^[a-zA-Z0-9_-]+$/', $transaction_id);
    }

    /**
     * Sanitize text input
     * @param string $input
     * @return string
     */
    public function sanitize_text($input) {
        return sanitize_text_field(trim($input));
    }

    /**
     * Sanitize email input
     * @param string $email
     * @return string
     */
    public function sanitize_email($email) {
        return sanitize_email(trim($email));
    }

    /**
     * Sanitize phone input
     * @param string $phone
     * @return string
     */
    public function sanitize_phone($phone) {
        // Keep only digits, spaces, hyphens, parentheses, plus
        return preg_replace('/[^0-9\s\-\(\)\+]/', '', trim($phone));
    }

    /**
     * Add validation error
     * @param string $field
     * @param string $message
     */
    private function add_error($field, $message) {
        $this->errors[$field] = $message;
    }

    /**
     * Reset validation errors
     */
    private function reset_errors() {
        $this->errors = array();
    }

    /**
     * Get validation errors
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Check if has errors
     * @return bool
     */
    public function has_errors() {
        return !empty($this->errors);
    }

    /**
     * Get first error message
     * @return string
     */
    public function get_first_error() {
        if (empty($this->errors)) {
            return '';
        }

        return reset($this->errors);
    }

    /**
     * Get errors as formatted string
     * @return string
     */
    public function get_errors_string() {
        if (empty($this->errors)) {
            return '';
        }

        return implode('; ', $this->errors);
    }
}
