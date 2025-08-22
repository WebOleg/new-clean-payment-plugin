<?php
/**
 * BNA Recent Payments Template
 * Displays recent BNA payments in My Account dashboard
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Ensure we have recent orders data
if (!isset($recent_orders) || empty($recent_orders)) {
    return;
}
?>

<div class="bna-recent-payments">
    
    <!-- Section Header -->
    <div class="bna-payments-header">
        <h3><?php _e('Recent BNA Payments', 'bna-payment-gateway'); ?></h3>
        <p class="bna-payments-description">
            <?php _e('Your recent transactions processed through BNA Smart Payment.', 'bna-payment-gateway'); ?>
        </p>
    </div>

    <!-- Payments List -->
    <div class="bna-payments-list">
        <?php foreach ($recent_orders as $order): ?>
            <?php
            // Get order data
            $order_id = $order->get_id();
            $order_status = $order->get_status();
            $order_date = $order->get_date_created();
            $order_total = $order->get_total();
            $currency = $order->get_currency();
            
            // Get BNA transaction data
            $order_handler = BNA_Order::get_instance();
            $transaction = $order_handler->get_transaction($order_id);
            
            if (!$transaction) {
                continue;
            }
            
            // Parse transaction data
            $transaction_data = is_array($transaction['transaction_description']) 
                ? $transaction['transaction_description'] 
                : json_decode($transaction['transaction_description'], true);
            
            $payment_status = $transaction['transaction_status'] ?? 'unknown';
            $reference_number = $transaction['reference_number'] ?? '';
            $payment_method = $transaction_data['payment_method'] ?? 'unknown';
            
            // Determine status class
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
            
            // Payment method labels
            $method_labels = array(
                'card' => __('Credit/Debit Card', 'bna-payment-gateway'),
                'eft' => __('Electronic Funds Transfer', 'bna-payment-gateway'),
                'e-transfer' => __('E-Transfer', 'bna-payment-gateway')
            );
            $method_label = $method_labels[$payment_method] ?? ucfirst($payment_method);
            ?>
            
            <div class="bna-payment-item">
                
                <!-- Payment Header -->
                <div class="bna-payment-header">
                    <div class="bna-payment-main-info">
                        <div class="bna-order-number">
                            <strong><?php _e('Order #', 'bna-payment-gateway'); ?><?php echo esc_html($order->get_order_number()); ?></strong>
                        </div>
                        <div class="bna-payment-date">
                            <?php echo esc_html($order_date->format('F j, Y')); ?>
                        </div>
                    </div>
                    
                    <div class="bna-payment-status">
                        <span class="bna-status-badge bna-status-<?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html(ucfirst($payment_status)); ?>
                        </span>
                    </div>
                    
                    <div class="bna-payment-amount">
                        <strong><?php echo wp_kses_post(wc_price($order_total, array('currency' => $currency))); ?></strong>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="bna-payment-details">
                    <div class="bna-payment-details-grid">
                        
                        <div class="bna-detail-item">
                            <span class="bna-detail-label"><?php _e('Payment Method:', 'bna-payment-gateway'); ?></span>
                            <span class="bna-detail-value"><?php echo esc_html($method_label); ?></span>
                        </div>
                        
                        <?php if ($reference_number): ?>
                        <div class="bna-detail-item">
                            <span class="bna-detail-label"><?php _e('Reference:', 'bna-payment-gateway'); ?></span>
                            <span class="bna-detail-value bna-reference-number"><?php echo esc_html($reference_number); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="bna-detail-item">
                            <span class="bna-detail-label"><?php _e('Transaction ID:', 'bna-payment-gateway'); ?></span>
                            <span class="bna-detail-value bna-transaction-id">
                                <?php echo esc_html(substr($transaction['transaction_token'], 0, 12) . '...'); ?>
                            </span>
                        </div>
                        
                        <div class="bna-detail-item">
                            <span class="bna-detail-label"><?php _e('Processed:', 'bna-payment-gateway'); ?></span>
                            <span class="bna-detail-value"><?php echo esc_html($transaction['created_time']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Actions -->
                <div class="bna-payment-actions">
                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="button bna-view-order">
                        <?php _e('View Order', 'bna-payment-gateway'); ?>
                    </a>
                    
                    <?php if ($reference_number): ?>
                    <button type="button" class="button button-secondary bna-copy-reference" data-reference="<?php echo esc_attr($reference_number); ?>">
                        <?php _e('Copy Reference', 'bna-payment-gateway'); ?>
                    </button>
                    <?php endif; ?>
                    
                    <?php if (in_array($payment_status, array('completed', 'approved', 'success')) && $order->is_download_permitted()): ?>
                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>#downloads" class="button bna-downloads">
                        <?php _e('Downloads', 'bna-payment-gateway'); ?>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Order Items Summary (for larger orders) -->
                <?php $items = $order->get_items(); ?>
                <?php if (count($items) <= 3): ?>
                <div class="bna-order-items-summary">
                    <div class="bna-items-list">
                        <?php foreach ($items as $item): ?>
                            <div class="bna-item">
                                <span class="bna-item-name"><?php echo esc_html($item->get_name()); ?></span>
                                <span class="bna-item-qty">×<?php echo esc_html($item->get_quantity()); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="bna-order-items-summary">
                    <div class="bna-items-count">
                        <?php printf(_n('%d item', '%d items', count($items), 'bna-payment-gateway'), count($items)); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- View All Payments Link -->
    <div class="bna-payments-footer">
        <a href="<?php echo esc_url(wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'))); ?>" class="button bna-view-all-orders">
            <?php _e('View All Orders', 'bna-payment-gateway'); ?>
        </a>
        
        <!-- Payment Statistics -->
        <div class="bna-payment-stats">
            <?php
            $successful_payments = 0;
            $total_amount = 0;
            
            foreach ($recent_orders as $order) {
                $order_handler = BNA_Order::get_instance();
                $transaction = $order_handler->get_transaction($order->get_id());
                
                if ($transaction && in_array($transaction['transaction_status'], array('completed', 'approved', 'success'))) {
                    $successful_payments++;
                    $total_amount += $order->get_total();
                }
            }
            ?>
            
            <div class="bna-stat-item">
                <span class="bna-stat-value"><?php echo esc_html($successful_payments); ?></span>
                <span class="bna-stat-label"><?php _e('Successful Payments', 'bna-payment-gateway'); ?></span>
            </div>
            
            <div class="bna-stat-item">
                <span class="bna-stat-value"><?php echo wp_kses_post(wc_price($total_amount)); ?></span>
                <span class="bna-stat-label"><?php _e('Total Paid', 'bna-payment-gateway'); ?></span>
            </div>
        </div>
    </div>

    <!-- Help Section -->
    <div class="bna-payments-help">
        <h4><?php _e('Need Help with a Payment?', 'bna-payment-gateway'); ?></h4>
        <div class="bna-help-content">
            <p><?php _e('If you have questions about any of these payments, please contact our support team with your reference number.', 'bna-payment-gateway'); ?></p>
            
            <div class="bna-help-actions">
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('contact'))); ?>" class="button button-secondary">
                    <?php _e('Contact Support', 'bna-payment-gateway'); ?>
                </a>
                
                <a href="#" class="bna-payment-faq">
                    <?php _e('Payment FAQ', 'bna-payment-gateway'); ?>
                </a>
            </div>
        </div>
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
                    // Show success feedback
                    var button = $('.bna-copy-reference[data-reference="' + reference + '"]');
                    var originalText = button.text();
                    button.text('<?php _e('Copied!', 'bna-payment-gateway'); ?>').addClass('copied');
                    
                    setTimeout(function() {
                        button.text(originalText).removeClass('copied');
                    }, 2000);
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = reference;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    // Show success feedback
                    var button = $('.bna-copy-reference[data-reference="' + reference + '"]');
                    var originalText = button.text();
                    button.text('<?php _e('Copied!', 'bna-payment-gateway'); ?>').addClass('copied');
                    
                    setTimeout(function() {
                        button.text(originalText).removeClass('copied');
                    }, 2000);
                } catch (err) {
                    alert('<?php _e('Please copy manually:', 'bna-payment-gateway'); ?> ' + reference);
                }
                document.body.removeChild(textArea);
            }
        }
    });
    
    // Expandable payment details on mobile
    $('.bna-payment-header').on('click', function() {
        if ($(window).width() <= 768) {
            $(this).closest('.bna-payment-item').toggleClass('expanded');
        }
    });
});
</script>
