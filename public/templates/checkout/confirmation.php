<?php
/**
 * BNA Payment Confirmation Template
 * Displays payment confirmation details on thank you page
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Ensure we have order and transaction data
if (!isset($order) || !isset($transaction)) {
    return;
}

// Parse transaction data
$transaction_data = is_array($transaction['transaction_description']) 
    ? $transaction['transaction_description'] 
    : json_decode($transaction['transaction_description'], true);

// Get payment status
$payment_status = $transaction['transaction_status'] ?? 'unknown';
$reference_number = $transaction['reference_number'] ?? '';
$transaction_token = $transaction['transaction_token'] ?? '';

// Determine status display
$status_classes = array(
    'completed' => 'success',
    'approved' => 'success', 
    'success' => 'success',
    'processing' => 'processing',
    'pending' => 'pending',
    'failed' => 'error',
    'declined' => 'error',
    'cancelled' => 'cancelled'
);

$status_class = $status_classes[$payment_status] ?? 'unknown';
$status_icon = '';

switch ($status_class) {
    case 'success':
        $status_icon = 'check-circle';
        break;
    case 'processing':
        $status_icon = 'clock';
        break;
    case 'pending':
        $status_icon = 'clock';
        break;
    case 'error':
        $status_icon = 'x-circle';
        break;
    case 'cancelled':
        $status_icon = 'x-circle';
        break;
    default:
        $status_icon = 'help-circle';
}
?>

<div class="bna-payment-confirmation">
    
    <!-- Payment Status Header -->
    <div class="bna-confirmation-header bna-status-<?php echo esc_attr($status_class); ?>">
        <div class="bna-status-icon">
            <i class="bna-icon-<?php echo esc_attr($status_icon); ?>"></i>
        </div>
        <div class="bna-status-content">
            <h3 class="bna-status-title">
                <?php
                switch ($status_class) {
                    case 'success':
                        _e('Payment Completed Successfully', 'bna-payment-gateway');
                        break;
                    case 'processing':
                        _e('Payment is Processing', 'bna-payment-gateway');
                        break;
                    case 'pending':
                        _e('Payment is Pending', 'bna-payment-gateway');
                        break;
                    case 'error':
                        _e('Payment Failed', 'bna-payment-gateway');
                        break;
                    case 'cancelled':
                        _e('Payment Cancelled', 'bna-payment-gateway');
                        break;
                    default:
                        _e('Payment Status Unknown', 'bna-payment-gateway');
                }
                ?>
            </h3>
            <p class="bna-status-message">
                <?php
                switch ($status_class) {
                    case 'success':
                        _e('Your payment has been processed successfully. You will receive a confirmation email shortly.', 'bna-payment-gateway');
                        break;
                    case 'processing':
                        _e('Your payment is being processed. This may take a few minutes to complete.', 'bna-payment-gateway');
                        break;
                    case 'pending':
                        _e('Your payment is pending confirmation. You will be notified when it is complete.', 'bna-payment-gateway');
                        break;
                    case 'error':
                        _e('There was an issue processing your payment. Please contact support if you have been charged.', 'bna-payment-gateway');
                        break;
                    case 'cancelled':
                        _e('Your payment was cancelled. No charges have been made to your account.', 'bna-payment-gateway');
                        break;
                    default:
                        _e('We are verifying your payment status. Please contact support if you have any concerns.', 'bna-payment-gateway');
                }
                ?>
            </p>
        </div>
    </div>

    <!-- Payment Details -->
    <div class="bna-payment-details">
        <h4><?php _e('Payment Details', 'bna-payment-gateway'); ?></h4>
        
        <div class="bna-details-grid">
            
            <!-- Order Information -->
            <div class="bna-detail-section">
                <h5><?php _e('Order Information', 'bna-payment-gateway'); ?></h5>
                <div class="bna-detail-rows">
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Order Number:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value">#<?php echo esc_html($order->get_order_number()); ?></span>
                    </div>
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Order Date:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value"><?php echo esc_html($order->get_date_created()->format('F j, Y g:i A')); ?></span>
                    </div>
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Order Total:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
                    </div>
                </div>
            </div>

            <!-- Transaction Information -->
            <div class="bna-detail-section">
                <h5><?php _e('Transaction Information', 'bna-payment-gateway'); ?></h5>
                <div class="bna-detail-rows">
                    <?php if ($reference_number): ?>
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Reference Number:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value bna-reference-number"><?php echo esc_html($reference_number); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Transaction ID:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value bna-transaction-id"><?php echo esc_html(substr($transaction_token, 0, 12) . '...'); ?></span>
                    </div>
                    
                    <?php if (isset($transaction_data['payment_method'])): ?>
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Payment Method:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value">
                            <?php
                            $method_labels = array(
                                'card' => __('Credit/Debit Card', 'bna-payment-gateway'),
                                'eft' => __('Electronic Funds Transfer', 'bna-payment-gateway'),
                                'e-transfer' => __('E-Transfer', 'bna-payment-gateway')
                            );
                            echo esc_html($method_labels[$transaction_data['payment_method']] ?? ucfirst($transaction_data['payment_method']));
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Payment Status:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value bna-payment-status bna-status-<?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html(ucfirst($payment_status)); ?>
                        </span>
                    </div>
                    
                    <div class="bna-detail-row">
                        <span class="bna-detail-label"><?php _e('Processed:', 'bna-payment-gateway'); ?></span>
                        <span class="bna-detail-value"><?php echo esc_html($transaction['created_time']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Next Steps -->
    <?php if ($status_class === 'success'): ?>
    <div class="bna-next-steps">
        <h4><?php _e('What Happens Next?', 'bna-payment-gateway'); ?></h4>
        <div class="bna-steps-list">
            <div class="bna-step">
                <span class="bna-step-number">1</span>
                <div class="bna-step-content">
                    <strong><?php _e('Confirmation Email', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('You will receive a detailed email confirmation with your payment and order details.', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
            <div class="bna-step">
                <span class="bna-step-number">2</span>
                <div class="bna-step-content">
                    <strong><?php _e('Order Processing', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('Your order will be processed and prepared for shipment according to our fulfillment schedule.', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
            <div class="bna-step">
                <span class="bna-step-number">3</span>
                <div class="bna-step-content">
                    <strong><?php _e('Shipping Notification', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('Once your order ships, you will receive tracking information via email.', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($status_class === 'processing' || $status_class === 'pending'): ?>
    <div class="bna-next-steps">
        <h4><?php _e('What Happens Next?', 'bna-payment-gateway'); ?></h4>
        <div class="bna-info-box">
            <p><?php _e('We are currently processing your payment. This process may take a few minutes to several hours depending on your payment method.', 'bna-payment-gateway'); ?></p>
            <p><?php _e('You will receive an email notification once your payment has been confirmed.', 'bna-payment-gateway'); ?></p>
            <p><?php _e('If you have any questions, please contact our support team with your reference number.', 'bna-payment-gateway'); ?></p>
        </div>
    </div>
    <?php elseif ($status_class === 'error'): ?>
    <div class="bna-next-steps">
        <h4><?php _e('Need Help?', 'bna-payment-gateway'); ?></h4>
        <div class="bna-info-box bna-error-box">
            <p><?php _e('If your payment failed but you believe you were charged, please contact our support team immediately.', 'bna-payment-gateway'); ?></p>
            <p><?php _e('Please have your reference number ready when contacting support.', 'bna-payment-gateway'); ?></p>
            <div class="bna-support-actions">
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('contact'))); ?>" class="button bna-contact-support">
                    <?php _e('Contact Support', 'bna-payment-gateway'); ?>
                </a>
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button button-secondary">
                    <?php _e('Try Again', 'bna-payment-gateway'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Support Information -->
    <div class="bna-support-info">
        <h4><?php _e('Need Support?', 'bna-payment-gateway'); ?></h4>
        <div class="bna-support-grid">
            <div class="bna-support-item">
                <i class="bna-icon-mail"></i>
                <div class="bna-support-content">
                    <strong><?php _e('Email Support', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('Get help via email within 24 hours', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
            <div class="bna-support-item">
                <i class="bna-icon-phone"></i>
                <div class="bna-support-content">
                    <strong><?php _e('Phone Support', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('Speak with a support representative', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
            <div class="bna-support-item">
                <i class="bna-icon-help"></i>
                <div class="bna-support-content">
                    <strong><?php _e('Help Center', 'bna-payment-gateway'); ?></strong>
                    <p><?php _e('Find answers to common questions', 'bna-payment-gateway'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Save/Print Options -->
    <div class="bna-confirmation-actions">
        <button type="button" class="button bna-print-confirmation" onclick="window.print()">
            <i class="bna-icon-printer"></i>
            <?php _e('Print Confirmation', 'bna-payment-gateway'); ?>
        </button>
        
        <button type="button" class="button button-secondary bna-copy-reference" data-reference="<?php echo esc_attr($reference_number); ?>">
            <i class="bna-icon-copy"></i>
            <?php _e('Copy Reference Number', 'bna-payment-gateway'); ?>
        </button>
    </div>

    <!-- Powered by BNA -->
    <div class="bna-powered-by-footer">
        <p>
            <?php _e('Payment processing powered by', 'bna-payment-gateway'); ?> 
            <strong>BNA Smart Payment</strong>
        </p>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Copy reference number functionality
    $('.bna-copy-reference').on('click', function() {
        var reference = $(this).data('reference');
        if (reference) {
            // Try to use the Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(reference).then(function() {
                    alert('<?php _e('Reference number copied to clipboard!', 'bna-payment-gateway'); ?>');
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = reference;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    alert('<?php _e('Reference number copied to clipboard!', 'bna-payment-gateway'); ?>');
                } catch (err) {
                    alert('<?php _e('Failed to copy reference number. Please copy manually:', 'bna-payment-gateway'); ?> ' + reference);
                }
                document.body.removeChild(textArea);
            }
        }
    });
});
</script>
