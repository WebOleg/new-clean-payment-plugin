(function($) {
    'use strict';

    /**
     * BNA Payment Handler Class
     */
    class BNAPaymentHandler {
        constructor() {
            this.config = null;
            this.iframe = null;
            this.processing = false;
            this.messageContainer = null;
            this.templates = this.initTemplates();
        }

        /**
         * Initialize handler
         */
        init() {
            this.config = window.bnaConfig || {};
            this.iframe = document.getElementById('bna-payment-iframe');
            this.messageContainer = document.getElementById('bna-messages-container');

            if (!this.config.orderId) {
                return;
            }

            this.bindEvents();
        }

        /**
         * Initialize HTML templates
         */
        initTemplates() {
            return {
                message: (type, text, showIcon = true) => `
                    <div class="bna-message ${type}">
                        ${showIcon ? `<span class="bna-message-icon"></span>` : ''}
                        <span class="bna-message-text">${text}</span>
                    </div>
                `
            };
        }

        /**
         * Bind event listeners
         */
        bindEvents() {
            window.addEventListener('message', this.handlePostMessage.bind(this), false);

            if (this.iframe) {
                this.iframe.addEventListener('load', this.onIframeLoad.bind(this));
                this.iframe.addEventListener('error', this.onIframeError.bind(this));
            }
        }

        /**
         * Handle iframe load event
         */
        onIframeLoad() {
            this.clearMessages();
        }

        /**
         * Handle iframe error event
         */
        onIframeError() {
            this.showMessage('error', 'Payment form failed to load.');
        }

        /**
         * Handle post messages from iframe
         */
        handlePostMessage(event) {
            if (this.config.apiOrigin && event.origin !== this.config.apiOrigin) {
                return;
            }

            const data = event.data;

            if (!data || !data.type) {
                return;
            }

            if (this.processing) {
                return;
            }

            switch(data.type) {
                case 'payment_success':
                    this.handlePaymentSuccess(data.data || {});
                    break;
                case 'payment_failed':
                    this.handlePaymentFailed(data.message || 'Payment failed');
                    break;
                case 'payment_error':
                    this.handlePaymentError(data.message || 'Payment error occurred');
                    break;
            }
        }

        /**
         * Handle successful payment
         */
        handlePaymentSuccess(paymentData) {
            if (this.processing) {
                return;
            }

            this.processing = true;
            this.showProcessingState();

            setTimeout(() => {
                this.updateOrderStatus(paymentData);
            }, 3000);
        }

        /**
         * Show processing state
         */
        showProcessingState() {
            const container = document.getElementById('bna-payment-container');
            if (container) {
                container.classList.add('processing');
            }

            this.showLoadingOverlay('Processing payment...');
        }

        /**
         * Show loading overlay
         */
        showLoadingOverlay(message) {
            const overlay = document.getElementById('bna-loading-overlay');
            if (overlay) {
                overlay.querySelector('.bna-loading-text').textContent = message;
                overlay.style.display = 'flex';
            }
        }

        /**
         * Hide loading overlay
         */
        hideLoadingOverlay() {
            const overlay = document.getElementById('bna-loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }

        /**
         * Update order status via AJAX
         */
        updateOrderStatus(paymentData) {
            if (!window.bna_ajax) {
                this.showError();
                return;
            }

            const ajaxData = {
                action: 'bna_complete_payment',
                order_id: parseInt(this.config.orderId),
                transaction_id: paymentData.id || '',
                _ajax_nonce: window.bna_ajax.nonce
            };

            $.post({
                url: window.bna_ajax.ajax_url,
                data: ajaxData,
                timeout: 15000
            })
                .done((response) => {
                    this.showSuccessState();
                    this.redirectToThankYou();
                })
                .fail((xhr, status, error) => {
                    this.showError();
                });
        }

        /**
         * Show success state
         */
        showSuccessState() {
            this.hideLoadingOverlay();
            this.showMessage('success', 'Payment completed successfully! Redirecting...');
        }

        /**
         * Redirect to thank you page
         */
        redirectToThankYou() {
            setTimeout(() => {
                window.location.href = this.config.thankYouUrl;
            }, 10000);
        }

        /**
         * Show error state
         */
        showError() {
            this.hideLoadingOverlay();
            this.showMessage('error', 'Payment was successful but order update failed. Please contact support.');
        }

        /**
         * Handle payment failure
         */
        handlePaymentFailed(message) {
            this.showMessage('error', message);
            setTimeout(() => {
                window.location.href = this.config.checkoutUrl;
            }, 3000);
        }

        /**
         * Handle payment error
         */
        handlePaymentError(message) {
            this.handlePaymentFailed('Payment error: ' + message);
        }

        /**
         * Show message in container
         */
        showMessage(type, message, showIcon = true) {
            if (!this.messageContainer) {
                this.messageContainer = document.getElementById('bna-messages-container');
            }

            if (this.messageContainer) {
                const messageDiv = document.createElement('div');
                messageDiv.innerHTML = this.templates.message(type, message, showIcon);
                this.messageContainer.appendChild(messageDiv.firstElementChild);
            }
        }

        /**
         * Clear all messages
         */
        clearMessages() {
            if (this.messageContainer) {
                this.messageContainer.innerHTML = '';
            }
        }
    }

    // Initialize global handler
    window.bnaPaymentHandler = new BNAPaymentHandler();

    // Initialize on DOM ready
    $(document).ready(function() {
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

})(jQuery);
