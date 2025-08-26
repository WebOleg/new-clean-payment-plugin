<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="bna-payment-container">
    <div class="bna-message error">
        <strong>Payment Session Expired</strong>
        <p>Your payment session has expired. Please try again to complete your payment.</p>
    </div>

    <div class="bna-session-info">
        <h4>What happened?</h4>
        <ul>
            <li>Payment sessions expire after 30 minutes for security</li>
            <li>Your order is still saved and waiting for payment</li>
            <li>Click the button below to restart the payment process</li>
        </ul>
    </div>

    <div class="bna-error-actions">
        <a href="<?php echo esc_url($retry_url); ?>" class="bna-retry-button">
            Retry Payment
        </a>

        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-retry-button" style="background: #666; margin-left: 10px;">
            Back to Checkout
        </a>
    </div>
</div>
