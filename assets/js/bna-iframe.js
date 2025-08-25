/**
 * BNA Payment Handler - Manual Redirect Only
 */
(function($) {
    'use strict';

    class BNAPaymentHandler {
        constructor() {
            this.config = null;
            this.iframe = null;
            this.processing = false;
            this.completed = false;
            this.messageContainer = null;
        }

        init() {
            this.config = window.bnaConfig || {};
            this.iframe = document.getElementById('bna-payment-iframe');
            this.messageContainer = document.getElementById('bna-messages-container');
            
            if (!this.config.orderId) {
                console.error('BNA: Order ID not found');
                return;
            }

            this.bindEvents();
            console.log('BNA Payment Handler initialized');
        }

        bindEvents() {
            window.addEventListener('message', this.handlePostMessage.bind(this), false);
            
            if (this.iframe) {
                this.iframe.addEventListener('load', this.onIframeLoad.bind(this));
                this.iframe.addEventListener('error', this.onIframeError.bind(this));
            }
        }

        onIframeLoad() {
            this.hideLoading();
            this.clearMessages();
        }

        onIframeError() {
            this.showMessage('error', 'Payment form failed to load.');
            this.hideLoading();
        }

        handlePostMessage(event) {
            if (this.config.apiOrigin && event.origin !== this.config.apiOrigin) {
                return;
            }

            const data = event.data;
            console.log('BNA: Received message:', data);

            if (!data || !data.type || this.processing) {
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

        handlePaymentSuccess(paymentData) {
            console.log('BNA: Payment successful:', paymentData);
            this.processing = true;
            
            // Одразу знищити iframe
            this.destroyIframe();
            
            this.showMessage('success', 'Payment successful!');
            this.showLoading('Updating order...');

            const requestData = {
                action: 'bna_update_order_status',
                order_id: this.config.orderId,
                status: 'success',
                transaction_id: paymentData.id || '',
                message: 'Payment completed successfully'
            };

            // Оновити замовлення БЕЗ WC redirect
            this.sendAjaxRequest(requestData)
                .then(response => {
                    if (response.success) {
                        console.log('BNA: Order updated, doing manual redirect');
                        this.doManualRedirect();
                    } else {
                        throw new Error('Order update failed');
                    }
                })
                .catch(error => {
                    console.error('BNA: Error:', error);
                    this.doManualRedirect(); // redirect anyway
                });
        }

        doManualRedirect() {
            this.hideLoading();
            this.showMessage('success', 'Redirecting to confirmation...');
            
            // Простий redirect без WC involvement
            setTimeout(() => {
                console.log('BNA: Manual redirect to:', this.config.thankYouUrl);
                window.location.href = this.config.thankYouUrl;
            }, 1500);
        }

        destroyIframe() {
            if (this.iframe) {
                this.iframe.src = 'about:blank';
                this.iframe.style.display = 'none';
                
                setTimeout(() => {
                    if (this.iframe && this.iframe.parentNode) {
                        this.iframe.parentNode.removeChild(this.iframe);
                        this.iframe = null;
                    }
                    
                    const wrapper = document.getElementById('bna-iframe-wrapper');
                    if (wrapper && wrapper.parentNode) {
                        wrapper.parentNode.removeChild(wrapper);
                    }
                }, 100);
            }
        }

        handlePaymentFailed(message) {
            this.destroyIframe();
            this.showMessage('error', message);
            setTimeout(() => {
                window.location.href = this.config.checkoutUrl;
            }, 3000);
        }

        handlePaymentError(message) {
            this.handlePaymentFailed('Payment error: ' + message);
        }

        sendAjaxRequest(data) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: bna_ajax.ajax_url,
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    timeout: 10000,
                    success: resolve,
                    error: reject
                });
            });
        }

        showLoading(message = 'Processing...') {
            const overlay = $('#bna-loading-overlay');
            if (overlay.length) {
                overlay.find('.bna-loading-text').text(message);
                overlay.show();
            }
        }

        hideLoading() {
            $('#bna-loading-overlay').hide();
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

    // Initialize
    window.bnaPaymentHandler = new BNAPaymentHandler();
    
    $(document).ready(function() {
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

})(jQuery);
