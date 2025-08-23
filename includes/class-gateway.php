<?php
/**
 * BNA Payment Gateway Class (with Login and Secret Key fields)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main gateway class
 */
class BNA_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'bna_payment_gateway';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'BNA Payment Gateway';
        $this->method_description = 'Accept payments through BNA Smart Payment system';

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->iframe_id = $this->get_option('iframe_id');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->login = $this->get_option('login');           // Додаємо login
        $this->secretKey = $this->get_option('secretKey');   // Додаємо secretKey
        $this->webhook_secret = $this->get_option('webhook_secret');
        $this->environment = $this->get_option('environment');
        $this->apply_fee = $this->get_option('apply_fee');

        // Save settings hook
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable BNA Payment Gateway',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title that customers will see',
                'default' => 'BNA Payment',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description',
                'default' => 'Online payments by card visa, amex, mastercard, etc., through the payment service',
                'desc_tip' => true,
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Enter the iFrame ID from BNA Smart Payment',
                'desc_tip' => true,
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'API access key (login)',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'API secret key (password)',
                'desc_tip' => true,
            ),
            'login' => array(
                'title' => 'Login',
                'type' => 'text',
                'description' => 'BNA API Login (required for API authentication)',
                'desc_tip' => true,
            ),
            'secretKey' => array(
                'title' => 'Secret Key (API)',
                'type' => 'password',
                'description' => 'BNA API Secret Key (required for API authentication)',
                'desc_tip' => true,
            ),
            'webhook_secret' => array(
                'title' => 'Webhook Secret',
                'type' => 'text',
                'description' => 'Secret key used to validate incoming BNA webhook requests',
                'desc_tip' => true,
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'description' => 'Select environment',
                'default' => 'https://dev-api-service.bnasmartpayment.com',
                'options' => array(
                    'https://dev-api-service.bnasmartpayment.com' => 'Stage',
                    'https://production-api-service.bnasmartpayment.com' => 'Production',
                ),
                'desc_tip' => true,
            ),
            'apply_fee' => array(
                'title' => 'Apply Payment Fee',
                'type' => 'checkbox',
                'label' => 'Apply BNA Payment Fee',
                'default' => 'no'
            ),
        );
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        // Check required fields - використовуємо login і secretKey
        if (empty($this->login) || empty($this->secretKey) || empty($this->iframe_id)) {
            return false;
        }

        return true;
    }

    /**
     * Display payment fields with real iframe loading
     */
    public function payment_fields() {
        // Show description
        if ($this->description) {
            echo '<div class="bna-payment-description">' . wp_kses_post($this->description) . '</div>';
        }

        // Container for iframe
        echo '<div id="bna-iframe-wrapper">';
        echo '<div id="bna-loading" style="text-align: center; padding: 20px; color: #666;">';
        echo '🔄 Loading payment form...';
        echo '</div>';
        echo '</div>';

        // Add JavaScript for real iframe loading
        $this->add_payment_script();
    }

    /**
     * Add JavaScript for iframe functionality
     */
    private function add_payment_script() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {

                var loadTimer;

                // Function to load BNA iframe
                function loadBNAIframe() {
                    console.log('Loading BNA iframe with real AJAX...');

                    // Show loading
                    $('#bna-iframe-wrapper').html('<div style="text-align: center; padding: 20px; color: #666;">🔄 Loading payment form...</div>');

                    // Get billing data
                    var billingData = {
                        action: 'load_bna_iframe',
                        nonce: '<?php echo wp_create_nonce("bna_iframe_nonce"); ?>',
                        billing_email: $('#billing_email').val() || '',
                        billing_first_name: $('#billing_first_name').val() || '',
                        billing_last_name: $('#billing_last_name').val() || '',
                        billing_phone: $('#billing_phone').val() || '',
                        billing_city: $('#billing_city').val() || '',
                        billing_country: $('#billing_country').val() || '',
                        billing_postcode: $('#billing_postcode').val() || '',
                        billing_address_1: $('#billing_address_1').val() || ''
                    };

                    console.log('Sending billing data:', billingData);

                    // AJAX request to load iframe
                    $.post('<?php echo admin_url("admin-ajax.php"); ?>', billingData, function(response) {
                        $('#bna-iframe-wrapper').html(response);
                        console.log('Iframe loaded successfully');
                    }).fail(function(xhr, status, error) {
                        $('#bna-iframe-wrapper').html('<div style="color:red; padding: 15px;">❌ Failed to load payment form: ' + error + '</div>');
                        console.log('Iframe loading failed:', xhr, status, error);
                    });
                }

                // Debounced reload function
                function debounceReload() {
                    clearTimeout(loadTimer);
                    loadTimer = setTimeout(loadBNAIframe, 800);
                }

                // Watch for field changes
                var fieldsToWatch = [
                    '#billing_email',
                    '#billing_first_name',
                    '#billing_last_name'
                ];

                $(fieldsToWatch.join(', ')).on('input change', debounceReload);

                // Load iframe when this payment method is selected
                $('body').on('change', 'input[name="payment_method"]', function() {
                    if ($(this).val() === 'bna_payment_gateway') {
                        debounceReload();
                    }
                });

                // Load immediately if already selected
                if ($('input[name="payment_method"]:checked').val() === 'bna_payment_gateway') {
                    debounceReload();
                }
            });
        </script>
        <?php
    }

    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Mark as pending
        $order->update_status('pending', 'Awaiting BNA payment');

        // Reduce stock
        wc_reduce_stock_levels($order_id);

        // Remove cart
        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
}