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

            console.log('BNA: Config:', this.config);
            console.log('BNA: AJAX:', window.bna_ajax);

            if (!this.config.orderId) {
                console.error('BNA: Order ID not found');
                return;
            }

            this.bindEvents();
            console.log('BNA: Payment Handler initialized for order:', this.config.orderId);
        }

        /**
         * Initialize HTML templates
         *
         * @return object
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
            console.log('BNA: Iframe loaded');
            this.clearMessages();
        }

        /**
         * Handle iframe error event
         */
        onIframeError() {
            console.error('BNA: Iframe failed to load');
            this.showMessage('error', 'Payment form failed to load.');
        }

        /**
         * Handle post messages from iframe
         *
         * @param {MessageEvent} event
         */
        handlePostMessage(event) {
            if (this.config.apiOrigin && event.origin !== this.config.apiOrigin) {
                console.log('BNA: Ignoring message from', event.origin);
                return;
            }

            const data = event.data;
            console.log('BNA: Received message:', data);

            if (!data || !data.type) {
                console.log('BNA: Invalid message data');
                return;
            }

            if (this.processing) {
                console.log('BNA: Already processing, ignoring duplicate message');
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
                default:
                    console.log('BNA: Unknown message type:', data.type);
            }
        }

        /**
         * Handle successful payment
         *
         * @param {object} paymentData
         */
        handlePaymentSuccess(paymentData) {
            console.log('BNA: SUCCESS! Transaction ID:', paymentData.id);

            if (this.processing) {
                console.log('BNA: Duplicate success - ignoring');
                return;
            }

            this.processing = true;
            this.showProcessingState();

            console.log('BNA: Waiting 3 seconds for iframe success display...');
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
         *
         * @param {string} message
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
         *
         * @param {object} paymentData
         */
        updateOrderStatus(paymentData) {
            console.log('BNA: Updating order status...');

            if (!window.bna_ajax) {
                console.error('BNA: AJAX config missing!');
                this.showError();
                return;
            }

            const ajaxData = {
                action: 'bna_complete_payment',
                order_id: parseInt(this.config.orderId),
                transaction_id: paymentData.id || '',
                _ajax_nonce: window.bna_ajax.nonce
            };

            console.log('BNA: Sending request:', ajaxData);

            $.post({
                url: window.bna_ajax.ajax_url,
                data: ajaxData,
                timeout: 15000
            })
                .done((response) => {
                    console.log('BNA: Success response:', response);
                    this.showSuccessState();
                    this.redirectToThankYou();
                })
                .fail((xhr, status, error) => {
                    console.error('BNA: Request failed:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText
                    });
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
            console.log('BNA: Redirecting to:', this.config.thankYouUrl);
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
         *
         * @param {string} message
         */
        handlePaymentFailed(message) {
            console.error('BNA: Payment failed:', message);
            this.showMessage('error', message);
            setTimeout(() => {
                window.location.href = this.config.checkoutUrl;
            }, 3000);
        }

        /**
         * Handle payment error
         *
         * @param {string} message
         */
        handlePaymentError(message) {
            this.handlePaymentFailed('Payment error: ' + message);
        }

        /**
         * Show message in container
         *
         * @param {string} type
         * @param {string} message
         * @param {boolean} showIcon
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
        console.log('BNA: DOM ready');
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

})(jQuery);