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

    <!-- Progress Steps -->
    <div class="bna-progress-steps" id="bna-progress-steps">
        <div class="bna-progress-step active" data-step="payment"></div>
        <div class="bna-progress-step" data-step="processing"></div>
        <div class="bna-progress-step" data-step="complete"></div>
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

    <!-- Security Notice -->
    <div class="bna-security-notice" style="margin-top: 20px; padding: 12px; background: #f8f9fa; border-radius: 6px; font-size: 14px; color: #6c757d; text-align: center;">
        <strong>🔒 Secure Payment:</strong> Your payment information is processed securely and is never stored on our servers.
    </div>
</div>

<script type="text/javascript">
    window.bnaConfig = {
        orderId: <?php echo json_encode($order_id); ?>,
        thankYouUrl: <?php echo json_encode($thank_you_url); ?>,
        checkoutUrl: <?php echo json_encode($checkout_url); ?>,
        apiOrigin: <?php echo json_encode($api_origin); ?>
    };

    jQuery(document).ready(function($) {
        // Initialize payment handler
        if (typeof window.bnaPaymentHandler !== 'undefined') {
            window.bnaPaymentHandler.init();
        }

        // Update progress steps based on payment flow
        function updateProgressStep(step) {
            const steps = document.querySelectorAll('.bna-progress-step');
            const stepMap = {
                'payment': 0,
                'processing': 1,
                'complete': 2
            };

            if (stepMap[step] !== undefined) {
                steps.forEach((stepEl, index) => {
                    stepEl.classList.remove('active');
                    if (index < stepMap[step]) {
                        stepEl.classList.add('completed');
                    } else if (index === stepMap[step]) {
                        stepEl.classList.add('active');
                    } else {
                        stepEl.classList.remove('completed');
                    }
                });
            }
        }

        // Listen for payment events to update progress
        window.addEventListener('message', function(event) {
            if (event.origin !== <?php echo json_encode($api_origin); ?>) {
                return;
            }

            const data = event.data;
            if (data && data.type) {
                switch(data.type) {
                    case 'payment_success':
                        updateProgressStep('processing');
                        $('#bna-payment-container').addClass('processing');
                        break;
                    case 'payment_complete':
                        updateProgressStep('complete');
                        break;
                }
            }
        });

        // Auto-hide messages after some time
        $(document).on('DOMNodeInserted', '#bna-messages-container .bna-message.success', function() {
            setTimeout(() => {
                $(this).fadeOut(500);
            }, 5000);
        });
    });
</script>

<style>
    /* Inline critical styles for immediate loading */
    .bna-payment-container.processing {
        pointer-events: none;
    }

    .bna-payment-container.processing .bna-payment-iframe {
        filter: blur(1px);
        opacity: 0.8;
    }

    .bna-messages-container {
        margin-top: 20px;
    }

    .bna-security-notice {
        opacity: 0.8;
        transition: opacity 0.3s ease;
    }

    .bna-security-notice:hover {
        opacity: 1;
    }
</style>