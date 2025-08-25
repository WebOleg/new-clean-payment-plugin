(function($) {
    'use strict';

    class BNAPaymentHandler {
        constructor() {
            this.config = null;
            this.iframe = null;
            this.processing = false;
            this.messageContainer = null;
        }

        init() {
            this.config = window.bnaConfig || {};
            this.iframe = document.getElementById('bna-payment-iframe');
            this.messageContainer = document.getElementById('bna-messages-container');
            
            console.log('BNA NEW VERSION: Config:', this.config);
            console.log('BNA NEW VERSION: AJAX:', window.bna_ajax);
            
            if (!this.config.orderId) {
                console.error('BNA NEW: Order ID not found');
                return;
            }

            this.bindEvents();
            console.log('BNA NEW: Payment Handler initialized for order:', this.config.orderId);
        }

        bindEvents() {
            window.addEventListener('message', this.handlePostMessage.bind(this), false);
            
            if (this.iframe) {
                this.iframe.addEventListener('load', this.onIframeLoad.bind(this));
                this.iframe.addEventListener('error', this.onIframeError.bind(this));
            }
        }

        onIframeLoad() {
            console.log('BNA NEW: Iframe loaded');
            this.clearMessages();
        }

        onIframeError() {
            console.error('BNA NEW: Iframe failed to load');
            this.showMessage('error', 'Payment form failed to load.');
        }

        handlePostMessage(event) {
            if (this.config.apiOrigin && event.origin !== this.config.apiOrigin) {
                console.log('BNA NEW: Ignoring message from', event.origin);
                return;
            }

            const data = event.data;
            console.log('BNA NEW: Received message:', data);

            if (!data || !data.type) {
                console.log('BNA NEW: Invalid message data');
                return;
            }

            if (this.processing) {
                console.log('BNA NEW: Already processing, ignoring duplicate message');
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
                    console.log('BNA NEW: Unknown message type:', data.type);
            }
        }

        handlePaymentSuccess(paymentData) {
            console.log('BNA NEW: SUCCESS! Transaction ID:', paymentData.id);
            
            if (this.processing) {
                console.log('BNA NEW: Duplicate success - ignoring');
                return;
            }
            
            this.processing = true;
            
            console.log('BNA NEW: Waiting 3 seconds for iframe success display...');
            setTimeout(() => {
                this.updateOrderStatus(paymentData);
            }, 3000);
        }

        updateOrderStatus(paymentData) {
            console.log('BNA NEW: Updating order status...');
            
            if (!window.bna_ajax) {
                console.error('BNA NEW: AJAX config missing!');
                this.showError();
                return;
            }
            
            const ajaxData = {
                action: 'bna_complete_payment',
                order_id: parseInt(this.config.orderId),
                transaction_id: paymentData.id || '',
                _ajax_nonce: window.bna_ajax.nonce
            };
            
            console.log('BNA NEW: Sending request:', ajaxData);

            $.post({
                url: window.bna_ajax.ajax_url,
                data: ajaxData,
                timeout: 15000
            })
            .done((response) => {
                console.log('BNA NEW: Success response:', response);
                this.redirectToThankYou();
            })
            .fail((xhr, status, error) => {
                console.error('BNA NEW: Request failed:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText
                });
                this.showError();
            });
        }

        redirectToThankYou() {
            console.log('BNA NEW: Redirecting to:', this.config.thankYouUrl);
            setTimeout(() => {
                window.location.href = this.config.thankYouUrl;
            }, 1000);
        }

        showError() {
            this.showMessage('error', 'Payment was successful but order update failed. Please contact support.');
        }

        handlePaymentFailed(message) {
            console.error('BNA NEW: Payment failed:', message);
            this.showMessage('error', message);
            setTimeout(() => {
                window.location.href = this.config.checkoutUrl;
            }, 3000);
        }

        handlePaymentError(message) {
            this.handlePaymentFailed('Payment error: ' + message);
        }

        showMessage(type, message) {
            if (!this.messageContainer) {
                this.messageContainer = document.getElementById('bna-messages-container');
            }

            if (this.messageContainer) {
                const messageDiv = document.createElement('div');
                messageDiv.className = `bna-message ${type}`;
                messageDiv.innerHTML = `
                    <span class="bna-message-icon"></span>
                    <span class="bna-message-text">${message}</span>
                `;
                this.messageContainer.appendChild(messageDiv);
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
        console.log('BNA NEW: DOM ready');
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

})(jQuery);
