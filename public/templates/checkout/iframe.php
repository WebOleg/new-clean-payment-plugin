<?php
/**
 * BNA iFrame Container Template
 * Displays the iFrame container for BNA payment processing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if BNA gateway is selected
$selected_gateway = WC()->session ? WC()->session->get('chosen_payment_method') : '';
if ($selected_gateway !== 'bna_gateway') {
    return;
}

// Get gateway settings
$gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
$payment_methods = $gateway_settings['payment_methods'] ?? array('card', 'eft');
$customer_types = $gateway_settings['customer_types'] ?? array('Personal', 'Business');
$enable_fees = $gateway_settings['enable_fees'] === 'yes';
$debug_mode = $gateway_settings['debug_mode'] === 'yes';
?>

<div id="bna-checkout-container" class="bna-checkout-container" style="display: none;">
    
    <!-- Payment Method Selection (if multiple methods available) -->
    <?php if (count($payment_methods) > 1): ?>
    <div class="bna-payment-methods-section">
        <h4><?php _e('Payment Method', 'bna-payment-gateway'); ?></h4>
        <div class="bna-payment-methods-wrapper">
            <select id="bna_payment_method" name="bna_payment_method" class="bna-payment-method-select">
                <?php foreach ($payment_methods as $method): ?>
                    <?php
                    $method_labels = array(
                        'card' => __('Credit/Debit Card', 'bna-payment-gateway'),
                        'eft' => __('Electronic Funds Transfer', 'bna-payment-gateway'),
                        'e-transfer' => __('E-Transfer', 'bna-payment-gateway')
                    );
                    $label = $method_labels[$method] ?? ucfirst($method);
                    ?>
                    <option value="<?php echo esc_attr($method); ?>">
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Payment Method Information -->
        <div class="bna-method-info">
            <div class="bna-method-description"></div>
            <div class="bna-processing-time-wrapper">
                <span class="bna-processing-label"><?php _e('Processing Time:', 'bna-payment-gateway'); ?></span>
                <span class="bna-processing-time"></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Customer Type Selection (if multiple types available) -->
    <?php if (count($customer_types) > 1): ?>
    <div class="bna-customer-type-section">
        <h4><?php _e('Customer Type', 'bna-payment-gateway'); ?></h4>
        <div class="bna-customer-type-wrapper">
            <select id="bna_customer_type" name="bna_customer_type" class="bna-customer-type-select">
                <?php foreach ($customer_types as $type): ?>
                    <option value="<?php echo esc_attr($type); ?>">
                        <?php echo esc_html($type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fee Information Display -->
    <?php if ($enable_fees): ?>
    <div class="bna-fees-section">
        <div class="bna-fee-notice">
            <p class="bna-fee-info">
                <i class="bna-icon-info"></i>
                <?php _e('Processing fees may apply based on your selected payment method.', 'bna-payment-gateway'); ?>
            </p>
        </div>
        
        <!-- Fee Breakdown Container (populated by JavaScript) -->
        <div class="bna-fee-breakdown-container" style="display: none;"></div>
    </div>
    <?php endif; ?>

    <!-- iFrame Container -->
    <div id="bna-iframe-container" class="bna-iframe-container" style="display: none;">
        
        <!-- Loading State -->
        <div id="bna-iframe-loader" class="bna-iframe-loader">
            <div class="bna-loader-content">
                <div class="bna-loader-spinner">
                    <div class="bna-spinner-ring"></div>
                    <div class="bna-spinner-ring"></div>
                    <div class="bna-spinner-ring"></div>
                    <div class="bna-spinner-ring"></div>
                </div>
                <p class="bna-loader-text">
                    <?php _e('Loading secure payment form...', 'bna-payment-gateway'); ?>
                </p>
                <div class="bna-loader-subtext">
                    <?php _e('Please wait while we prepare your payment form.', 'bna-payment-gateway'); ?>
                </div>
            </div>
        </div>

        <!-- iFrame Wrapper -->
        <div class="bna-iframe-wrapper" style="display: none;">
            <div class="bna-iframe-header">
                <div class="bna-security-badges">
                    <span class="bna-security-badge">
                        <i class="bna-icon-lock"></i>
                        <?php _e('Secure Payment', 'bna-payment-gateway'); ?>
                    </span>
                    <span class="bna-security-badge">
                        <i class="bna-icon-shield"></i>
                        <?php _e('SSL Protected', 'bna-payment-gateway'); ?>
                    </span>
                </div>
            </div>
            
            <div class="bna-iframe-container-inner">
                <iframe id="bna-payment-iframe" 
                        class="bna-payment-iframe" 
                        frameborder="0" 
                        scrolling="auto"
                        allow="payment"
                        sandbox="allow-forms allow-scripts allow-same-origin"
                        title="<?php esc_attr_e('BNA Secure Payment Form', 'bna-payment-gateway'); ?>">
                </iframe>
            </div>
            
            <div class="bna-iframe-footer">
                <p class="bna-powered-by">
                    <?php _e('Powered by', 'bna-payment-gateway'); ?> 
                    <strong>BNA Smart Payment</strong>
                </p>
            </div>
        </div>

        <!-- Error State -->
        <div class="bna-iframe-error" style="display: none;">
            <div class="bna-error-content">
                <i class="bna-icon-warning"></i>
                <h4><?php _e('Payment Form Error', 'bna-payment-gateway'); ?></h4>
                <p class="bna-error-message"></p>
                <button type="button" class="button bna-retry-button">
                    <?php _e('Try Again', 'bna-payment-gateway'); ?>
                </button>
            </div>
        </div>

        <!-- iFrame Actions -->
        <div class="bna-iframe-actions">
            <button type="button" class="button bna-refresh-iframe" style="display: none;">
                <i class="bna-icon-refresh"></i>
                <?php _e('Refresh Payment Form', 'bna-payment-gateway'); ?>
            </button>
            
            <?php if ($debug_mode): ?>
            <button type="button" class="button bna-debug-info" style="display: none;">
                <i class="bna-icon-bug"></i>
                <?php _e('Debug Info', 'bna-payment-gateway'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Messages -->
    <div class="bna-status-container">
        <div class="bna-status-message" style="display: none;"></div>
    </div>

    <!-- Progress Indicator -->
    <div class="bna-progress-container" style="display: none;">
        <div class="bna-progress-bar">
            <div class="bna-progress-step active" data-step="1">
                <span class="bna-step-number">1</span>
                <span class="bna-step-label"><?php _e('Select Payment', 'bna-payment-gateway'); ?></span>
            </div>
            <div class="bna-progress-step" data-step="2">
                <span class="bna-step-number">2</span>
                <span class="bna-step-label"><?php _e('Enter Details', 'bna-payment-gateway'); ?></span>
            </div>
            <div class="bna-progress-step" data-step="3">
                <span class="bna-step-number">3</span>
                <span class="bna-step-label"><?php _e('Confirm Payment', 'bna-payment-gateway'); ?></span>
            </div>
        </div>
    </div>

    <!-- Hidden Fields for Form Data -->
    <input type="hidden" id="bna_transaction_token" name="bna_transaction_token" value="" />
    <input type="hidden" id="bna_payment_status" name="bna_payment_status" value="" />

</div>

<!-- Debug Information (only in debug mode) -->
<?php if ($debug_mode): ?>
<div class="bna-debug-panel" style="display: none;">
    <h4><?php _e('BNA Debug Information', 'bna-payment-gateway'); ?></h4>
    <div class="bna-debug-content">
        <div class="bna-debug-section">
            <strong><?php _e('Settings:', 'bna-payment-gateway'); ?></strong>
            <ul>
                <li><?php _e('Environment:', 'bna-payment-gateway'); ?> <?php echo esc_html($gateway_settings['api_environment'] ?? 'staging'); ?></li>
                <li><?php _e('Payment Methods:', 'bna-payment-gateway'); ?> <?php echo esc_html(implode(', ', $payment_methods)); ?></li>
                <li><?php _e('Customer Types:', 'bna-payment-gateway'); ?> <?php echo esc_html(implode(', ', $customer_types)); ?></li>
                <li><?php _e('Fees Enabled:', 'bna-payment-gateway'); ?> <?php echo $enable_fees ? 'Yes' : 'No'; ?></li>
            </ul>
        </div>
        <div class="bna-debug-section">
            <strong><?php _e('Current State:', 'bna-payment-gateway'); ?></strong>
            <div id="bna-debug-state"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<script type="text/javascript">
// Show the container when BNA gateway is selected
jQuery(document).ready(function($) {
    // Show container immediately if BNA is selected
    if ($('input[name="payment_method"]:checked').val() === 'bna_gateway') {
        $('#bna-checkout-container').show();
    }
    
    // Handle payment method changes
    $(document.body).on('change', 'input[name="payment_method"]', function() {
        if ($(this).val() === 'bna_gateway') {
            $('#bna-checkout-container').slideDown();
        } else {
            $('#bna-checkout-container').slideUp();
        }
    });
    
    <?php if ($debug_mode): ?>
    // Debug mode functions
    $('.bna-debug-info').on('click', function() {
        $('.bna-debug-panel').toggle();
        
        // Update debug state
        if (typeof BNA_Checkout !== 'undefined') {
            $('#bna-debug-state').html('<pre>' + JSON.stringify(BNA_Checkout.getState(), null, 2) + '</pre>');
        }
    });
    <?php endif; ?>
});
</script>
