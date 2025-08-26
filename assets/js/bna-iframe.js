(function($) {
    'use strict';

    class BNAPaymentHandler {
        constructor() {
            this.config = null;
            this.iframe = null;
            this.processing = false;
            this.messageContainer = null;
            this.templates = this.initTemplates();
        }

        init() {
            this.config = window.bnaConfig || {};
            this.iframe = document.getElementById('bna-payment-iframe');
            this.messageContainer = document.getElementById('bna-messages-container');

            console.log('BNA: Config:', this.config);

            if (!this.config.orderId) {
                console.error('BNA: Order ID not found');
                return;
            }

            this.bindEvents();
            console.log('BNA: Payment Handler initialized for order:', this.config.orderId);
        }

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

        bindEvents() {
            window.addEventListener('message', this.handlePostMessage.bind(this), false);

            if (this.iframe) {
                this.iframe.addEventListener('load', this.onIframeLoad.bind(this));
                this.iframe.addEventListener('error', this.onIframeError.bind(this));
            }
        }

        onIframeLoad() {
            console.log('BNA: Iframe loaded');
            this.clearMessages();
        }

        onIframeError() {
            console.error('BNA: Iframe failed to load');
            this.showMessage('error', 'Payment form failed to load.');
        }

        handlePostMessage(event) {
            // Validate origin
            if (this.config.apiOrigin && event.origin !== this.config.apiOrigin) {
                console.log('BNA: Ignoring message from', event.origin);
                return;
            }

            console.log('BNA: Message received from iframe:', event);

            let data;
            try {
                // Handle case where event.data is already an object
                data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            } catch (e) {
                console.error('BNA: Failed to parse message data:', e, 'Raw data:', event.data);
                
                // Check if it's an HTML error page (503, 500, etc.)
                if (typeof event.data === 'string' && event.data.includes('upstream')) {
                    this.handleServerError('BNA server is temporarily unavailable (503 error)');
                    return;
                }
                
                this.handlePaymentError('Invalid message format received from payment system');
                return;
            }

            console.log('BNA: Parsed message data:', data);

            if (!data || !data.type) {
                console.log('BNA: Invalid message structure');
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

        handleServerError(message) {
            console.error('BNA: Server Error:', message);
            this.showMessage('error', message);
            
            // Log to simple logger if available
            if (typeof BNA_Simple_Logger !== 'undefined') {
                BNA_Simple_Logger.log('Server error in iframe', {
                    order_id: this.config.orderId,
                    error: message
                });
            }
            
            // Don't redirect immediately on server errors
            setTimeout(() => {
                this.showMessage('info', 'Please try refreshing the page or contact support if the issue persists.');
            }, 2000);
        }

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

        showProcessingState() {
            const container = document.getElementById('bna-payment-container');
            if (container) {
                container.classList.add('processing');
            }

            this.showLoadingOverlay('Processing payment...');
        }

        showLoadingOverlay(message) {
            const overlay = document.getElementById('bna-loading-overlay');
            if (overlay) {
                overlay.querySelector('.bna-loading-text').textContent = message;
                overlay.style.display = 'flex';
            }
        }

        hideLoadingOverlay() {
            const overlay = document.getElementById('bna-loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }

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

            console.log('BNA: Sending AJAX request:', ajaxData);

            $.post({
                url: window.bna_ajax.ajax_url,
                data: ajaxData,
                timeout: 15000
            })
                .done((response) => {
                    console.log('BNA: AJAX Success:', response);
                    this.showSuccessState();
                    this.redirectToThankYou();
                })
                .fail((xhr, status, error) => {
                    console.error('BNA: AJAX Failed:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                    this.showError();
                });
        }

        showSuccessState() {
            this.hideLoadingOverlay();
            this.showMessage('success', 'Payment completed successfully! Redirecting...');
        }

        redirectToThankYou() {
            console.log('BNA: Redirecting to:', this.config.thankYouUrl);
            setTimeout(() => {
                window.location.href = this.config.thankYouUrl;
            }, 10000);
        }

        showError() {
            this.hideLoadingOverlay();
            this.showMessage('error', 'Payment was successful but order update failed. Please contact support.');
        }

        handlePaymentFailed(message) {
            console.error('BNA: Payment failed:', message);
            this.showMessage('error', 'Payment failed: ' + message);
            
            setTimeout(() => {
                this.showMessage('info', 'Redirecting back to checkout...');
                setTimeout(() => {
                    window.location.href = this.config.checkoutUrl;
                }, 2000);
            }, 3000);
        }

        handlePaymentError(message) {
            console.error('BNA: Payment error:', message);
            this.showMessage('error', 'Payment error: ' + message);
            console.log('BNA: Showing payment error, NOT redirecting');
            
            // Don't redirect on errors - let user try again
            setTimeout(() => {
                this.showMessage('info', 'You can try again or refresh the page.');
            }, 3000);
        }

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

        clearMessages() {
            if (this.messageContainer) {
                this.messageContainer.innerHTML = '';
            }
        }
    }

    window.bnaPaymentHandler = new BNAPaymentHandler();

    $(document).ready(function() {
        console.log('BNA: DOM ready');
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

})(jQuery);
