<?php
/**
 * BNA Payment Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bna_payment_gateway';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('BNA Payment Gateway', 'wc-bna-gateway');
        $this->method_description = __('Accept payments using BNA Smart Payment system', 'wc-bna-gateway');

        // Support for subscriptions and pre-orders
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
            'pre-orders'
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');
        $this->environment = $this->get_option('environment');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_bna_payment_gateway', array($this, 'webhook_handler'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-bna-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable BNA Payment Gateway', 'wc-bna-gateway'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'wc-bna-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-bna-gateway'),
                'default' => __('BNA Payment', 'wc-bna-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-bna-gateway'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-bna-gateway'),
                'default' => __('Pay securely using BNA Smart Payment system.', 'wc-bna-gateway'),
            ),
            'access_key' => array(
                'title' => __('Access Key', 'wc-bna-gateway'),
                'type' => 'text',
                'description' => __('Enter your BNA Access Key', 'wc-bna-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => __('Secret Key', 'wc-bna-gateway'),
                'type' => 'password',
                'description' => __('Enter your BNA Secret Key', 'wc-bna-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'iframe_id' => array(
                'title' => __('Iframe ID', 'wc-bna-gateway'),
                'type' => 'text',
                'description' => __('Enter your BNA Iframe ID', 'wc-bna-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'environment' => array(
                'title' => __('Environment', 'wc-bna-gateway'),
                'type' => 'select',
                'description' => __('Select the BNA environment', 'wc-bna-gateway'),
                'default' => 'https://dev-api-service.bnasmartpayment.com',
                'desc_tip' => true,
                'options' => array(
                    'https://dev-api-service.bnasmartpayment.com' => __('Development', 'wc-bna-gateway'),
                    'https://stage-api-service.bnasmartpayment.com' => __('Staging', 'wc-bna-gateway'),
                    'https://production-api-service.bnasmartpayment.com' => __('Production', 'wc-bna-gateway'),
                ),
            ),
        );
    }

    /**
     * Payment form fields for BNA Gateway
     */
    public function payment_fields() {
        // Show description
        if ($this->description) {
            echo '<div class="bna-payment-description" style="margin-bottom: 15px;">';
            echo wp_kses_post($this->description) . '</div>';
        }

        // Container for iframe
        echo '<div id="bna-iframe-wrapper">';
        echo '<div id="bna-loading" style="text-align: center; padding: 30px; color: #888; background: #f8f9fa; border-radius: 8px;">';
        echo '<div style="display: flex; align-items: center; justify-content: center; flex-direction: column;">';
        echo '<div class="bna-loading" style="margin-bottom: 15px;"></div>';
        echo '<h4 style="margin-bottom: 10px;">Підготовка платіжної форми...</h4>';
        echo '<p style="margin: 0;">Заповніть дані вище для завантаження платіжних методів</p>';
        echo '</div>';
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

                // Debug: Check if we're on the right page
                console.log('🔍 BNA Gateway script initializing...');
                console.log('🔍 Current URL:', window.location.href);
                console.log('🔍 Is checkout page:', $('body').hasClass('woocommerce-checkout'));

                // CRITICAL: Make sure we have the global data for AJAX
                window.bna_iframe_data = {
                    ajax_url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    nonce: '<?php echo wp_create_nonce("bna_iframe_nonce"); ?>'
                };
                console.log('🔍 Set bna_iframe_data:', window.bna_iframe_data);

                // Ensure iframe wrapper exists
                if ($('#bna-iframe-wrapper').length === 0) {
                    console.warn('🔍 BNA iframe wrapper not found! Adding fallback...');
                    $('.payment_method_bna_payment_gateway').append('<div id="bna-iframe-wrapper"></div>');
                } else {
                    console.log('🔍 BNA iframe wrapper found');
                }

                // Add CSS styles
                if (!$('#bna-gateway-styles').length) {
                    $('<style id="bna-gateway-styles">').text(`
                        #bna-iframe-wrapper {
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            background: #fff;
                            overflow: hidden;
                        }

                        #bna-iframe-wrapper iframe {
                            width: 100%;
                            min-height: 600px;
                            border: none;
                            display: block;
                        }

                        .bna-payment-description {
                            background: #f8f9fa;
                            padding: 15px;
                            border-radius: 6px;
                            border-left: 4px solid #007bff;
                        }

                        /* Loading animation */
                        .bna-loading {
                            display: inline-block;
                            width: 20px;
                            height: 20px;
                            border: 3px solid #f3f3f3;
                            border-top: 3px solid #007bff;
                            border-radius: 50%;
                            animation: bna-spin 1s linear infinite;
                        }

                        @keyframes bna-spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }

                        /* Validation styles */
                        .bna-invalid {
                            border-color: #dc3545 !important;
                            box-shadow: 0 0 5px rgba(220, 53, 69, 0.3) !important;
                        }
                    `).appendTo('head');
                }

                // Add manual trigger button for testing
                if (!$('#bna-manual-trigger').length) {
                    $('<button id="bna-manual-trigger" type="button" style="position: fixed; top: 10px; right: 10px; z-index: 9999; padding: 5px 10px; background: #28a745; color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">🚀 Load BNA Iframe</button>')
                        .appendTo('body')
                        .on('click', function() {
                            console.log('🔍 Manual trigger clicked');

                            // Check if dynamic script is loaded
                            if (typeof window.reloadBnaIframe === 'function') {
                                window.reloadBnaIframe();
                            } else {
                                console.log('🔍 Dynamic script not loaded, calling AJAX directly...');

                                var billingData = {
                                    action: 'load_bna_iframe',
                                    nonce: window.bna_iframe_data.nonce,
                                    billing_email: $('#billing_email').val() || 'test@example.com',
                                    billing_first_name: $('#billing_first_name').val() || 'Test',
                                    billing_last_name: $('#billing_last_name').val() || 'User',
                                    billing_phone: $('#billing_phone').val() || '',
                                    billing_city: $('#billing_city').val() || '',
                                    billing_country: $('#billing_country').val() || 'CA',
                                    billing_state: $('#billing_state').val() || '',
                                    billing_postcode: $('#billing_postcode').val() || '',
                                    billing_address_1: $('#billing_address_1').val() || ''
                                };

                                console.log('🔍 Manual AJAX call with data:', billingData);

                                $.post(window.bna_iframe_data.ajax_url, billingData)
                                    .done(function(response) {
                                        console.log('🔍 Manual AJAX response:', response.substring(0, 200) + '...');
                                        $('#bna-iframe-wrapper').html(response);
                                    })
                                    .fail(function(xhr, status, error) {
                                        console.error('🔍 Manual AJAX failed:', error);
                                        $('#bna-iframe-wrapper').html('<div style="color:red; padding: 20px;">❌ AJAX Error: ' + error + '</div>');
                                    });
                            }
                        });
                }

                console.log('🔍 BNA Payment Gateway initialized successfully');
            });
        </script>
        <?php
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Mark as on-hold (we're awaiting the payment)
        $order->update_status('on-hold', __('Awaiting BNA payment confirmation', 'wc-bna-gateway'));

        // Reduce stock levels
        wc_reduce_stock_levels($order_id);

        // Remove cart
        WC()->cart->empty_cart();

        // Return thankyou redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Webhook handler for BNA payment notifications
     */
    public function webhook_handler() {
        $raw_body = file_get_contents('php://input');
        $data = json_decode($raw_body, true);

        if (!$data) {
            wp_die('Invalid JSON', 'Webhook Error', array('response' => 400));
        }

        // Log the webhook for debugging
        error_log('BNA Webhook received: ' . print_r($data, true));

        // Process the webhook data
        $this->process_webhook($data);

        // Respond with 200 OK
        status_header(200);
        exit;
    }

    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        // Extract order ID from webhook data
        $order_id = isset($data['orderId']) ? intval($data['orderId']) : 0;

        if (!$order_id) {
            error_log('BNA Webhook: No order ID found in webhook data');
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('BNA Webhook: Order not found - ID: ' . $order_id);
            return;
        }

        // Process based on payment status
        $status = isset($data['status']) ? strtoupper($data['status']) : '';
        $transaction_id = isset($data['transactionId']) ? $data['transactionId'] : '';

        switch ($status) {
            case 'COMPLETED':
            case 'APPROVED':
                $order->payment_complete($transaction_id);
                $order->add_order_note(
                    sprintf(__('BNA payment completed. Transaction ID: %s', 'wc-bna-gateway'), $transaction_id)
                );
                break;

            case 'FAILED':
            case 'DECLINED':
                $order->update_status('failed', __('BNA payment failed', 'wc-bna-gateway'));
                break;

            case 'CANCELLED':
                $order->update_status('cancelled', __('BNA payment cancelled', 'wc-bna-gateway'));
                break;

            case 'PENDING':
            default:
                $order->update_status('pending', __('BNA payment pending', 'wc-bna-gateway'));
                break;
        }

        // Save customer ID if provided
        if (isset($data['customerId']) && $order->get_user_id()) {
            update_user_meta($order->get_user_id(), 'bna_payorID', $data['customerId']);
        }
    }

    /**
     * Check if this gateway is available
     */
    public function is_available() {
        if ($this->enabled === 'no') {
            return false;
        }

        if (empty($this->access_key) || empty($this->secret_key) || empty($this->iframe_id)) {
            return false;
        }

        return true;
    }

    /**
     * Admin options
     */
    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>

        <div class="bna-admin-notice" style="background: #fff; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;">
            <h4 style="margin-top: 0;">🔧 Configuration Status</h4>
            <?php $this->display_configuration_status(); ?>
        </div>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <div class="bna-debug-info" style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 20px 0; border-radius: 6px;">
            <h4>🔍 Debug Information</h4>
            <p><strong>Plugin Version:</strong> 1.0.0</p>
            <p><strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?></p>
            <p><strong>WooCommerce Version:</strong> <?php echo defined('WC_VERSION') ? WC_VERSION : 'Not detected'; ?></p>
            <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
            <p><strong>Test URL:</strong> <a href="<?php echo admin_url('admin-ajax.php?action=load_bna_iframe&test=1'); ?>" target="_blank">Test AJAX endpoint</a></p>
        </div>
        <?php
    }

    /**
     * Display configuration status
     */
    private function display_configuration_status() {
        $access_key_ok = !empty($this->access_key);
        $secret_key_ok = !empty($this->secret_key);
        $iframe_id_ok = !empty($this->iframe_id);

        echo '<ul style="margin: 0; padding-left: 20px;">';
        echo '<li style="color: ' . ($access_key_ok ? 'green' : 'red') . ';">';
        echo ($access_key_ok ? '✅' : '❌') . ' Access Key: ' . ($access_key_ok ? 'Configured' : 'Missing');
        echo '</li>';

        echo '<li style="color: ' . ($secret_key_ok ? 'green' : 'red') . ';">';
        echo ($secret_key_ok ? '✅' : '❌') . ' Secret Key: ' . ($secret_key_ok ? 'Configured' : 'Missing');
        echo '</li>';

        echo '<li style="color: ' . ($iframe_id_ok ? 'green' : 'red') . ';">';
        echo ($iframe_id_ok ? '✅' : '❌') . ' Iframe ID: ' . ($iframe_id_ok ? 'Configured' : 'Missing');
        echo '</li>';

        echo '<li style="color: blue;">🌐 Environment: ' . esc_html($this->environment) . '</li>';
        echo '</ul>';

        if ($access_key_ok && $secret_key_ok && $iframe_id_ok) {
            echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin-top: 15px;">';
            echo '✅ <strong>Gateway is properly configured!</strong> You can now accept BNA payments.';
            echo '</div>';
        } else {
            echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; border-radius: 4px; margin-top: 15px;">';
            echo '⚠️ <strong>Configuration incomplete.</strong> Please fill in all required fields above.';
            echo '</div>';
        }
    }
}