/**
 * BNA Payment Handler - Aggressive iframe destruction
 */
(function($) {
    'use strict';

    class BNAPaymentHandler {
        constructor() {
            this.config = null;
            this.iframe = null;
            this.processing = false;
            this.destroyed = false;
        }

        init() {
            this.config = window.bnaConfig || {};
            this.iframe = document.getElementById('bna-payment-iframe');

            if (!this.config.orderId) {
                console.error('BNA: Order ID not found');
                return;
            }

            console.log('BNA: Initial iframe state:', this.iframe ? 'FOUND' : 'NOT FOUND');
            this.bindEvents();
            console.log('BNA Payment Handler initialized');
        }

        bindEvents() {
            window.addEventListener('message', this.handlePostMessage.bind(this), false);
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
            console.log('BNA: Payment successful - starting aggressive cleanup');
            this.processing = true;

            // ОДРАЗУ і агресивно знищити iframe
            this.destroyIframeAggressively();

            // Додати індикатор що платіж завершений
            this.showCompletionMessage();

            // Швидкий редірект
            setTimeout(() => {
                console.log('BNA: Redirecting to:', this.config.thankYouUrl);
                // Використовуємо replace замість href для уникнення history
                window.location.replace(this.config.thankYouUrl);
            }, 1000);
        }

        destroyIframeAggressively() {
            console.log('🔥 BNA: AGGRESSIVE IFRAME DESTRUCTION START');

            // Знайти iframe знову на всякий випадок
            this.iframe = this.iframe || document.getElementById('bna-payment-iframe');

            if (this.iframe) {
                console.log('🔥 Found iframe, destroying...');

                try {
                    // 1. Спочатку заблокувати всі події
                    this.iframe.onload = null;
                    this.iframe.onerror = null;

                    // 2. Зупинити завантаження
                    this.iframe.src = 'about:blank';
                    console.log('🔥 Set src to about:blank');

                    // 3. Сховати повністю
                    this.iframe.style.cssText = 'display: none !important; visibility: hidden !important; position: absolute; left: -9999px; width: 0; height: 0;';
                    console.log('🔥 Hidden iframe');

                    // 4. Видалити атрибути
                    this.iframe.removeAttribute('src');
                    this.iframe.removeAttribute('srcdoc');
                    console.log('🔥 Removed attributes');

                    // 5. Замінити на пустий div
                    const replacement = document.createElement('div');
                    replacement.innerHTML = '<!-- BNA iframe removed -->';
                    this.iframe.parentNode.replaceChild(replacement, this.iframe);
                    console.log('🔥 Replaced with empty div');

                    this.iframe = null;
                    this.destroyed = true;

                } catch (error) {
                    console.error('🔥 Error destroying iframe:', error);
                    // Якщо не вдається - просто сховати
                    try {
                        this.iframe.style.display = 'none';
                        this.iframe.style.visibility = 'hidden';
                    } catch (e) {
                        console.error('🔥 Even hiding failed:', e);
                    }
                }
            } else {
                console.log('🔥 No iframe found to destroy');
            }

            // Знищити wrapper теж
            const wrapper = document.getElementById('bna-iframe-wrapper');
            if (wrapper) {
                console.log('🔥 Destroying wrapper');
                wrapper.innerHTML = '<div class="bna-destroyed">✓ Payment processing completed</div>';
                wrapper.style.cssText = 'text-align: center; padding: 40px; background: #d4edda; border: 2px solid #28a745; border-radius: 8px;';
            }

            console.log('🔥 BNA: AGGRESSIVE IFRAME DESTRUCTION COMPLETE');
        }

        showCompletionMessage() {
            // Додати глобальний індикатор
            const indicator = document.createElement('div');
            indicator.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #28a745;
                color: white;
                padding: 15px 25px;
                border-radius: 5px;
                font-weight: bold;
                z-index: 999999;
                box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            `;
            indicator.innerHTML = '✓ Payment Completed!';
            document.body.appendChild(indicator);
        }

        handlePaymentFailed(message) {
            console.log('BNA: Payment failed:', message);
            this.destroyIframeAggressively();
            setTimeout(() => {
                window.location.replace(this.config.checkoutUrl);
            }, 2000);
        }

        handlePaymentError(message) {
            this.handlePaymentFailed('Payment error: ' + message);
        }
    }

    // Initialize
    window.bnaPaymentHandler = new BNAPaymentHandler();

    $(document).ready(function() {
        if (window.bnaConfig) {
            window.bnaPaymentHandler.init();
        }
    });

    // Додатковий захист - якщо щось пішло не так
    window.addEventListener('beforeunload', function(e) {
        if (window.bnaPaymentHandler && window.bnaPaymentHandler.destroyed) {
            console.log('✅ BNA: Iframe destroyed, allowing navigation');
            return undefined; // Не показувати попередження
        }

        if (window.bnaPaymentHandler && window.bnaPaymentHandler.processing) {
            console.log('⚠️ BNA: Payment processing, but iframe not destroyed yet');
            // Спробувати знищити ще раз
            try {
                window.bnaPaymentHandler.destroyIframeAggressively();
            } catch (error) {
                console.error('Failed emergency iframe destruction:', error);
            }
        }
    });

})(jQuery);