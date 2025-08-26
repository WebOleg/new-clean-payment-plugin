<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="bna-payment-container">
    <div class="bna-message error">
        <strong>Payment Error</strong>
        <p><?php echo esc_html($message); ?></p>
    </div>

    <div class="bna-error-actions">
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-retry-button">
            Return to Checkout
        </a>

        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="bna-retry-button" style="background: #666; margin-left: 10px;">
            View Cart
        </a>
    </div>
</div>
