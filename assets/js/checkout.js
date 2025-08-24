/**
 * BNA Bridge Checkout JavaScript
 * Handles checkout form integration and iframe loading
 * 
 * @package BNA_Payment_Bridge
 * @subpackage Assets
 */

(function($) {
    'use strict';

    // Main checkout handler
    const BNACheckout = {
        
        /**
         * Configuration and state
         */
        config: {
            gatewayId: 'bna_bridge',
            iframeContainer: '#bna-bridge-iframe-container',
            loadingElement: '#bna-bridge-iframe-loading',
            paymentResultField: '#bna-bridge-payment-result',
            transactionIdField: '#bna-bridge-transaction-id',
            loadingTimeout: 30000 // 30 seconds
        },
        
        state: {
            iframeLoaded: false,
            paymentInProgress: false,
            currentToken: null,
            lastCustomerData: null
        },

        /**
         * Initialize checkout functionality
         */
        init: function() {
            console.log('[BNA Checkout] Initializing...');
            
            this.bindEvents();
            this.setupMessageListener();
            
            // Load iframe if BNA gateway is selected
            if (this.isGatewaySelected()) {
                this.loadIframe();
            }
            
            console.log('[BNA Checkout] Initialized successfully');
        },

        /**
         * Bind checkout events
         */
        bindEvents: function() {
            const self = this;
            
            // Payment method change
            $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                if ($(this).val() === self.config.gatewayId) {
                    self.onGatewaySelected();
                } else {
                    self.onGatewayDeselected();
                }
            });
            
            // Customer data change (reload iframe with new data)
            const customerFields = [
                '#billing_email', '#billing_first_name', '#billing_last_name',
                '#billing_phone', '#billing_address_1', '#billing_address_2',
                '#billing_city', '#billing_state', '#billing_postcode', '#billing_country'
            ];
            
            let reloadTimer;
            $(customerFields.join(', ')).on('input change', function() {
                if (!self.isGatewaySelected()) return;
                
                // Debounce iframe reload
                clearTimeout(reloadTimer);
                reloadTimer = setTimeout(() => {
                    if (self.hasCustomerDataChanged()) {
                        self.reloadIframe();
                    }
                }, 1000);
            });
            
            // Checkout form submission
            $('form.checkout').on('submit', function() {
                return self.onCheckoutSubmit();
            });
        },

        /**
         * Setup message listener for iframe communication
         */
        setupMessageListener: function() {
            const self = this;
            
            window.addEventListener('message', function(event) {
                self.handleIframeMessage(event);
            });
        },

        /**
         * Check if BNA gateway is selected
         * 
         * @return {boolean} True if BNA gateway is selected
         */
        isGatewaySelected: function() {
            return $('input[name="payment_method"]:checked').val() === this.config.gatewayId;
        },

        /**
         * Handle gateway selection
         */
        onGatewaySelected: function() {
            console.log('[BNA Checkout] Gateway selected, loading iframe...');
            this.showContainer();
            this.loadIframe();
        },

        /**
         * Handle gateway deselection
         */
        onGatewayDeselected: function() {
            console.log('[BNA Checkout] Gateway deselected');
            this.hideContainer();
            this.clearPaymentResult();
        },

        /**
         * Show iframe container
         */
        showContainer: function() {
            $(this.config.iframeContainer).show();
        },

        /**
         * Hide iframe container
         */
        hideContainer: function() {
            $(this.config.iframeContainer).hide();
        },

        /**
         * Load iframe with customer data
         */
        loadIframe: function() {
            const self = this;
            
            if (this.state.paymentInProgress) {
                console.log('[BNA Checkout] Payment in progress, skipping iframe load');
                return;
            }
            
            this.showLoading();
            this.clearPaymentResult();
            
            const customerData = this.getCustomerData();
            
            // Validate required fields
            if (!this.validateCustomerData(customerData)) {
                this.showError(bna_bridge_checkout.messages.error_incomplete);
                return;
            }
            
            // Make AJAX request to load iframe
            $.ajax({
                url: bna_bridge_checkout.ajax_url,
                type: 'POST',
                data: {
                    action: 'bna_bridge_load_iframe',
                    nonce: bna_bridge_checkout.nonce,
                    ...customerData
                },
                timeout: this.config.loadingTimeout,
                success: function(response) {
                    if (response.success) {
                        self.renderIframe(response.data);
                    } else {
                        self.showError(response.data.message || bna_bridge_checkout.messages.error_loading);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[BNA Checkout] AJAX error:', status, error);
                    self.showError(bna_bridge_checkout.messages.connection_error);
                }
            });
            
            // Store current customer data
            this.state.lastCustomerData = JSON.stringify(customerData);
        },

        /**
         * Reload iframe if customer data changed
         */
        reloadIframe: function() {
            console.log('[BNA Checkout] Reloading iframe with updated customer data');
            this.state.iframeLoaded = false;
            this.loadIframe();
        },

        /**
         * Check if customer data has changed
         * 
         * @return {boolean} True if data changed
         */
        hasCustomerDataChanged: function() {
            const currentData = JSON.stringify(this.getCustomerData());
            return currentData !== this.state.lastCustomerData;
        },

        /**
         * Get customer data from form
         * 
         * @return {object} Customer data object
         */
        getCustomerData: function() {
            return {
                billing_email: $('#billing_email').val() || '',
                billing_first_name: $('#billing_first_name').val() || '',
                billing_last_name: $('#billing_last_name').val() || '',
                billing_phone: $('#billing_phone').val() || '',
                billing_address_1: $('#billing_address_1').val() || '',
                billing_address_2: $('#billing_address_2').val() || '',
                billing_city: $('#billing_city').val() || '',
                billing_state: $('#billing_state').val() || '',
                billing_postcode: $('#billing_postcode').val() || '',
                billing_country: $('#billing_country').val() || ''
            };
        },

        /**
         * Validate customer data
         * 
         * @param {object} data Customer data
         * @return {boolean} True if valid
         */
        validateCustomerData: function(data) {
            const required = ['billing_email', 'billing_first_name', 'billing_last_name'];
            
            for (let field of required) {
                if (!data[field] || data[field].trim() === '') {
                    console.log(`[BNA Checkout] Missing required field: ${field}`);
                    return false;
                }
            }
            
            return true;
        },

        /**
         * Render iframe in container
         * 
         * @param {object} data Response data with iframe_url
         */
        renderIframe: function(data) {
            const self = this;
            
            console.log('[BNA Checkout] Rendering iframe:', data.iframe_url);
            
            this.state.currentToken = data.token;
            
            const iframeHtml = `
                <iframe 
                    id="bna-bridge-iframe" 
                    src="${data.iframe_url}"
                    class="bna-bridge-iframe"
                    frameborder="0"
                    allowtransparency="true"
                    allow="payment"
                    sandbox="allow-forms allow-scripts allow-same-origin allow-top-navigation">
                </iframe>
            `;
            
            $(this.config.iframeContainer).html(iframeHtml);
            
            // Set iframe load timeout
            const iframe = document.getElementById('bna-bridge-iframe');
            let loadTimeout = setTimeout(() => {
                if (!self.state.iframeLoaded) {
                    self.showError(bna_bridge_checkout.messages.error_loading);
                }
            }, this.config.loadingTimeout);
            
            // Handle iframe load
            iframe.onload = function() {
                clearTimeout(loadTimeout);
                self.state.iframeLoaded = true;
                console.log('[BNA Checkout] Iframe loaded successfully');
            };
            
            iframe.onerror = function() {
                clearTimeout(loadTimeout);
                self.showError(bna_bridge_checkout.messages.error_loading);
            };
        },

        /**
         * Handle messages from iframe
         * 
         * @param {MessageEvent} event Message event from iframe
         */
        handleIframeMessage: function(event) {
            // Validate origin
            const allowedOrigins = bna_bridge_checkout.iframe.allowed_origins || [];
            if (!allowedOrigins.includes(event.origin)) {
                console.warn('[BNA Checkout] Message from unauthorized origin:', event.origin);
                return;
            }
            
            console.log('[BNA Checkout] Received iframe message:', event.data);
            
            const data = event.data;
            
            if (!data || !data.type) {
                return;
            }
            
            switch (data.type) {
                case 'payment_success':
                    this.handlePaymentSuccess(data);
                    break;
                    
                case 'payment_failed':
                    this.handlePaymentFailure(data);
                    break;
                    
                case 'payment_error':
                    this.handlePaymentError(data);
                    break;
                    
                default:
                    console.log('[BNA Checkout] Unknown iframe message type:', data.type);
            }
        },

        /**
         * Handle successful payment
         * 
         * @param {object} data Payment success data
         */
        handlePaymentSuccess: function(data) {
            console.log('[BNA Checkout] Payment successful:', data);
            
            this.setPaymentResult('success', data.data ? data.data.id : '');
            this.showSuccess(bna_bridge_checkout.messages.payment_success);
            
            // Auto-submit checkout form
            setTimeout(() => {
                this.submitCheckout();
            }, 1500);
        },

        /**
         * Handle payment failure
         * 
         * @param {object} data Payment failure data
         */
        handlePaymentFailure: function(data) {
            console.log('[BNA Checkout] Payment failed:', data);
            
            this.setPaymentResult('failed');
            this.showError(data.message || bna_bridge_checkout.messages.payment_failed);
            this.state.paymentInProgress = false;
        },

        /**
         * Handle payment error
         * 
         * @param {object} data Payment error data
         */
        handlePaymentError: function(data) {
            console.log('[BNA Checkout] Payment error:', data);
            
            this.setPaymentResult('error');
            this.showError(data.message || bna_bridge_checkout.messages.payment_failed);
            this.state.paymentInProgress = false;
        },

        /**
         * Set payment result in hidden field
         * 
         * @param {string} result Payment result (success/failed/error)
         * @param {string} transactionId Transaction ID for successful payments
         */
        setPaymentResult: function(result, transactionId = '') {
            $(this.config.paymentResultField).val(result);
            $(this.config.transactionIdField).val(transactionId);
        },

        /**
         * Clear payment result
         */
        clearPaymentResult: function() {
            $(this.config.paymentResultField).val('');
            $(this.config.transactionIdField).val('');
        },

        /**
         * Handle checkout form submission
         * 
         * @return {boolean} True to allow submission
         */
        onCheckoutSubmit: function() {
            if (!this.isGatewaySelected()) {
                return true; // Allow submission for other gateways
            }
            
            const paymentResult = $(this.config.paymentResultField).val();
            
            if (paymentResult === 'success') {
                console.log('[BNA Checkout] Allowing form submission with successful payment');
                return true;
            }
            
            if (this.state.paymentInProgress) {
                console.log('[BNA Checkout] Payment in progress, preventing form submission');
                return false;
            }
            
            // No payment result yet - prevent submission
            console.log('[BNA Checkout] No payment result, preventing form submission');
            this.showError(bna_bridge_checkout.messages.error_incomplete);
            return false;
        },

        /**
         * Submit checkout form programmatically
         */
        submitCheckout: function() {
            this.state.paymentInProgress = true;
            $('form.checkout').submit();
        },

        /**
         * Show loading state
         */
        showLoading: function() {
            $(this.config.iframeContainer).html(`
                <div id="bna-bridge-iframe-loading" style="padding: 40px; text-align: center; color: #666;">
                    <p>${bna_bridge_checkout.messages.loading}</p>
                    <div class="bna-loading-spinner"></div>
                </div>
            `);
        },

        /**
         * Show error message
         * 
         * @param {string} message Error message
         */
        showError: function(message) {
            $(this.config.iframeContainer).html(`
                <div class="bna-bridge-error">
                    <p><strong>Error:</strong> ${message}</p>
                    <button type="button" onclick="BNACheckout.loadIframe()" class="button">Try Again</button>
                </div>
            `);
        },

        /**
         * Show success message
         * 
         * @param {string} message Success message
         */
        showSuccess: function(message) {
            $(this.config.iframeContainer).html(`
                <div class="bna-bridge-success">
                    <p><strong>Success:</strong> ${message}</p>
                    <p>Redirecting...</p>
                </div>
            `);
        }
    };

    // Initialize when document ready
    $(document).ready(function() {
        // Only initialize if we're on checkout page with BNA gateway available
        if (typeof bna_bridge_checkout !== 'undefined') {
            BNACheckout.init();
            
            // Make BNACheckout available globally for debugging
            window.BNACheckout = BNACheckout;
        }
    });

})(jQuery);
