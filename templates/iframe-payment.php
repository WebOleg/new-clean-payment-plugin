<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="bna-payment-container" id="bna-payment-container">
    <h2 class="bna-payment-title">Complete Your Payment</h2>
    <p class="bna-payment-description">
        Please complete your payment of <strong><?php echo esc_html($order_total); ?></strong>
        for Order #<?php echo esc_html($order_number); ?> in the secure form below:
    </p>

    <div class="bna-payment-details">
        <div class="bna-order-summary">
            <span class="bna-label">Amount:</span>
            <span class="bna-amount"><?php echo esc_html($order_total); ?></span>
        </div>
        <div class="bna-order-summary">
            <span class="bna-label">Order:</span>
            <span class="bna-order">#<?php echo esc_html($order_number); ?></span>
        </div>
        <div class="bna-order-summary">
            <span class="bna-label">Currency:</span>
            <span class="bna-currency"><?php echo esc_html($currency); ?></span>
        </div>
    </div>

    <!-- DEBUG INFO -->
    <div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace; font-size: 12px;">
        <strong>DEBUG INFO:</strong><br>
        iFrame URL: <?php echo esc_html($iframe_url); ?><br>
        API Origin: <?php echo esc_html($api_origin); ?><br>
        Token Length: <?php echo strlen($token ?? ''); ?> chars
    </div>

    <div id="bna-iframe-wrapper" class="bna-iframe-wrapper">
        <iframe id="bna-payment-iframe"
                class="bna-payment-iframe"
                src="<?php echo esc_url($iframe_url); ?>"
                width="100%"
                height="600"
                frameborder="0"
                scrolling="auto"
                title="BNA Payment Form">
            <p>Your browser does not support iframes. Please update your browser or contact support.</p>
        </iframe>

        <div id="bna-loading-overlay" class="bna-loading-overlay" style="display: none;">
            <div class="bna-loading-text">Processing your payment...</div>
            <div class="bna-spinner"></div>
        </div>
    </div>

    <div id="bna-messages-container" class="bna-messages-container"></div>

</div>

<script type="text/javascript">
    window.bnaConfig = {
        orderId: <?php echo json_encode($order_id); ?>,
        thankYouUrl: <?php echo json_encode($thank_you_url); ?>,
        checkoutUrl: <?php echo json_encode($checkout_url); ?>,
        apiOrigin: <?php echo json_encode($api_origin); ?>
    };

    // Debug iframe URL
    console.log('BNA: iFrame URL being loaded:', <?php echo json_encode($iframe_url); ?>);
    console.log('BNA: API Origin expected:', <?php echo json_encode($api_origin); ?>);

    jQuery(document).ready(function($) {
        if (typeof window.bnaPaymentHandler !== 'undefined') {
            window.bnaPaymentHandler.init();
        }

        $(document).on('DOMNodeInserted', '#bna-messages-container .bna-message.success', function() {
            setTimeout(() => {
                $(this).fadeOut(500);
            }, 5000);
        });

        // Check iframe load errors
        $('#bna-payment-iframe').on('error', function() {
            console.error('BNA: iFrame failed to load the URL:', this.src);
        });
    });
</script>
