/**
 * BNA iFrame Handler
 * Manages iFrame token creation, display, and payment processing
 */

(function($) {
    'use strict';

    // iFrame Handler Class
    window.BNA_IframeHandler = {
        
        // Configuration
        config: {
            container_id: 'bna-iframe-container',
            loader_id: 'bna-iframe-loader',
            iframe_id: 'bna-payment-iframe',
            status_check_interval: 3000,
            max_status_checks: 100,
            timeout_duration: 300000, // 5 minutes
            debug: false
        },

        // State variables
        state: {
            current_token: null,
            iframe_url: null,
            status_check_timer: null,
            status_check_count: 0,
            payment_completed: false,
            timeout_timer: null
        },

        /**
         * Initialize iFrame handler
         */
        init: function() {
            this.loadConfig();
            this.bindEvents();
            this.setupContainer();
            
            if (this.config.debug) {
                console.log('BNA iFrame Handler initialized');
            }
        },

        /**
         * Load configuration from WordPress localization
         */
        loadConfig: function() {
            if (typeof bna_iframe_params !== 'undefined') {
                $.extend(this.config, bna_iframe_params);
            }
            
            if (typeof bna_checkout_params !== 'undefined') {
                this.config.debug = bna_checkout_params.debug_mode;
                this.config.timeout_duration = (bna_checkout_params.iframe_timeout || 300) * 1000;
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Listen for payment method changes
            $(document.body).on('change', 'input[name="payment_method"]', function() {
                if ($(this).val() === 'bna_gateway') {
                    self.onPaymentMethodSelected();
                } else {
                    self.hideIframe();
                }
            });

            // Listen for checkout form changes
            $(document.body).on('change', '.bna-payment-fields select, .bna-payment-fields input', function() {
                self.onFormDataChanged();
            });

            // Handle checkout form submission
            $(document.body).on('checkout_place_order_bna_gateway', function() {
                return self.processCheckout();
            });

            // Handle page unload
            $(window).on('beforeunload', function() {
                self.cleanup();
            });
        },

        /**
         * Setup iFrame container HTML
         */
        setupContainer: function() {
            if ($('#' + this.config.container_id).length === 0) {
                var containerHtml = `
                    <div id="${this.config.container_id}" class="bna-iframe-container" style="display: none;">
                        <div id="${this.config.loader_id}" class="bna-iframe-loader">
                            <div class="bna-loader-spinner"></div>
                            <p class="bna-loader-text">${bna_checkout_params.messages.iframe_loading}</p>
                        </div>
                        <div class="bna-iframe-wrapper">
                            <iframe id="${this.config.iframe_id}" 
                                    class="bna-payment-iframe" 
                                    frameborder="0" 
                                    scrolling="auto"
                                    style="width: 100%; height: 600px; border: none;">
                            </iframe>
                        </div>
                        <div class="bna-iframe-actions">
                            <button type="button" class="button bna-refresh-iframe" style="display: none;">
                                ${bna_checkout_params.messages.refresh_payment || 'Refresh Payment Form'}
                            </button>
                        </div>
                    </div>
                `;
                
                $('.woocommerce-checkout-review-order').after(containerHtml);
            }

            // Bind refresh button
            $(document).on('click', '.bna-refresh-iframe', function(e) {
                e.preventDefault();
                BNA_IframeHandler.refreshToken();
            });
        },

        /**
         * Handle payment method selection
         */
        onPaymentMethodSelected: function() {
            this.showIframe();
            this.validateAndCreateToken();
        },

        /**
         * Handle form data changes
         */
        onFormDataChanged: function() {
            // Clear current token when form data changes
            if (this.state.current_token) {
                this.state.current_token = null;
                this.hideIframe();
            }
        },

        /**
         * Process checkout form submission
         */
        processCheckout: function() {
            var self = this;

            if (!this.state.current_token) {
                this.showError('Payment form not ready. Please wait or refresh the page.');
                return false;
            }

            if (this.state.payment_completed) {
                return true; // Allow form submission
            }

            // Block the form and wait for payment completion
            this.blockCheckoutForm();
            
            // Start payment status monitoring
            this.startStatusMonitoring();

            return false; // Prevent form submission until payment is complete
        },

        /**
         * Validate form data and create iFrame token
         */
        validateAndCreateToken: function() {
            var self = this;
            var formData = this.getCheckoutFormData();

            // Validate required fields
            if (!this.validateFormData(formData)) {
                this.showError('Please fill in all required fields.');
                return;
            }

            this.showLoader();

            // AJAX request to create iframe token
            $.ajax({
                url: bna_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'bna_create_iframe_token',
                    nonce: bna_checkout_params.checkout_nonce,
                    payment_method: formData.payment_method,
                    customer_type: formData.customer_type,
                    email: formData.email,
                    first_name: formData.first_name,
                    last_name: formData.last_name,
                    phone: formData.phone,
                    phone_code: formData.phone_code,
                    address_1: formData.address_1,
                    address_2: formData.address_2,
                    city: formData.city,
                    state: formData.state,
                    postcode: formData.postcode,
                    country: formData.country,
                    order_total: formData.order_total,
                    currency: formData.currency,
                    order_items: formData.order_items
                },
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        self.onTokenCreated(response.data);
                    } else {
                        self.onTokenError(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    self.onTokenError('Connection error: ' + error);
                }
            });
        },

        /**
         * Handle successful token creation
         */
        onTokenCreated: function(data) {
            this.state.current_token = data.token;
            this.state.iframe_url = data.iframe_url;
            
            if (this.config.debug) {
                console.log('Token created:', data.token.substring(0, 10) + '...');
            }

            this.loadIframe(data.iframe_url);
            this.startTimeout();
        },

        /**
         * Handle token creation error
         */
        onTokenError: function(message) {
            this.hideLoader();
            this.showError(message || 'Failed to create payment form.');
            
            if (this.config.debug) {
                console.error('Token creation failed:', message);
            }
        },

        /**
         * Load iFrame with payment form
         */
        loadIframe: function(iframe_url) {
            var self = this;
            var $iframe = $('#' + this.config.iframe_id);

            $iframe.on('load', function() {
                self.hideLoader();
                self.showIframeContent();
            });

            $iframe.on('error', function() {
                self.hideLoader();
                self.showError('Failed to load payment form.');
            });

            $iframe.attr('src', iframe_url);
        },

        /**
         * Start payment status monitoring
         */
        startStatusMonitoring: function() {
            var self = this;
            
            if (!this.state.current_token) {
                return;
            }

            this.state.status_check_count = 0;
            this.state.status_check_timer = setInterval(function() {
                self.checkPaymentStatus();
            }, this.config.status_check_interval);

            if (this.config.debug) {
                console.log('Started payment status monitoring');
            }
        },

        /**
         * Check payment status via AJAX
         */
        checkPaymentStatus: function() {
            var self = this;

            this.state.status_check_count++;

            if (this.state.status_check_count > this.config.max_status_checks) {
                this.stopStatusMonitoring();
                this.showError('Payment status check timeout.');
                return;
            }

            $.ajax({
                url: bna_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'bna_get_iframe_status',
                    nonce: bna_checkout_params.checkout_nonce,
                    transaction_token: this.state.current_token
                },
                timeout: 10000,
                success: function(response) {
                    if (response.success) {
                        self.onStatusReceived(response.data);
                    } else {
                        if (self.config.debug) {
                            console.log('Status check failed:', response.data.message);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    if (self.config.debug) {
                        console.log('Status check error:', error);
                    }
                }
            });
        },

        /**
         * Handle received payment status
         */
        onStatusReceived: function(data) {
            var status = data.status.toLowerCase();

            if (this.config.debug) {
                console.log('Payment status:', status);
            }

            switch (status) {
                case 'completed':
                case 'approved':
                case 'success':
                    this.onPaymentSuccess(data);
                    break;
                    
                case 'failed':
                case 'declined':
                case 'error':
                    this.onPaymentFailure(data);
                    break;
                    
                case 'cancelled':
                    this.onPaymentCancelled(data);
                    break;
                    
                case 'pending':
                    // Continue monitoring
                    break;
                    
                default:
                    if (this.config.debug) {
                        console.log('Unknown payment status:', status);
                    }
            }
        },

        /**
         * Handle successful payment
         */
        onPaymentSuccess: function(data) {
            this.state.payment_completed = true;
            this.stopStatusMonitoring();
            this.clearTimeout();
            
            this.showSuccess('Payment completed successfully!');
            
            // Submit the checkout form
            setTimeout(function() {
                $('form.checkout').submit();
            }, 1000);
        },

        /**
         * Handle failed payment
         */
        onPaymentFailure: function(data) {
            this.stopStatusMonitoring();
            this.clearTimeout();
            this.unblockCheckoutForm();
            
            var message = data.message || 'Payment failed. Please try again.';
            this.showError(message);
            
            // Show refresh button
            $('.bna-refresh-iframe').show();
        },

        /**
         * Handle cancelled payment
         */
        onPaymentCancelled: function(data) {
            this.stopStatusMonitoring();
            this.clearTimeout();
            this.unblockCheckoutForm();
            
            this.showError('Payment was cancelled.');
            $('.bna-refresh-iframe').show();
        },

        /**
         * Refresh expired token
         */
        refreshToken: function() {
            var self = this;

            if (!this.state.current_token) {
                this.validateAndCreateToken();
                return;
            }

            this.showLoader();
            $('.bna-refresh-iframe').hide();

            $.ajax({
                url: bna_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'bna_refresh_iframe_token',
                    nonce: bna_checkout_params.checkout_nonce,
                    old_token: this.state.current_token
                },
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        self.onTokenCreated(response.data);
                    } else {
                        self.onTokenError(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    self.onTokenError('Connection error: ' + error);
                }
            });
        },

        /**
         * Get checkout form data
         */
        getCheckoutFormData: function() {
            var $form = $('form.checkout');
            
            return {
                payment_method: $('input[name="payment_method"]:checked').val(),
                customer_type: $('#bna_customer_type').val() || 'Personal',
                email: $('#billing_email').val(),
                first_name: $('#billing_first_name').val(),
                last_name: $('#billing_last_name').val(),
                phone: $('#billing_phone').val(),
                phone_code: $('#billing_phone_code').val() || '+1',
                address_1: $('#billing_address_1').val(),
                address_2: $('#billing_address_2').val(),
                city: $('#billing_city').val(),
                state: $('#billing_state').val(),
                postcode: $('#billing_postcode').val(),
                country: $('#billing_country').val(),
                order_total: this.getOrderTotal(),
                currency: bna_checkout_params.currency_code,
                order_items: this.getOrderItems()
            };
        },

        /**
         * Validate form data
         */
        validateFormData: function(data) {
            var required_fields = ['email', 'first_name', 'last_name', 'phone', 'address_1', 'city', 'postcode'];
            
            for (var i = 0; i < required_fields.length; i++) {
                if (!data[required_fields[i]] || data[required_fields[i]].trim() === '') {
                    return false;
                }
            }
            
            return true;
        },

        /**
         * Get order total from WooCommerce
         */
        getOrderTotal: function() {
            var total_text = $('.order-total .amount').text();
            var total = parseFloat(total_text.replace(/[^0-9.-]+/g, ''));
            return isNaN(total) ? 0 : total;
        },

        /**
         * Get order items from cart
         */
        getOrderItems: function() {
            var items = [];
            
            $('.shop_table .cart_item').each(function() {
                var $row = $(this);
                var name = $row.find('.product-name').text().trim();
                var quantity = parseInt($row.find('.product-quantity').text()) || 1;
                var price_text = $row.find('.product-total .amount').text();
                var price = parseFloat(price_text.replace(/[^0-9.-]+/g, ''));
                
                if (name && !isNaN(price)) {
                    items.push({
                        name: name,
                        quantity: quantity,
                        price: price / quantity,
                        total: price
                    });
                }
            });
            
            return items;
        },

        /**
         * UI Helper Methods
         */
        showIframe: function() {
            $('#' + this.config.container_id).slideDown();
        },

        hideIframe: function() {
            $('#' + this.config.container_id).slideUp();
            this.cleanup();
        },

        showLoader: function() {
            $('#' + this.config.loader_id).show();
            $('.bna-iframe-wrapper').hide();
        },

        hideLoader: function() {
            $('#' + this.config.loader_id).hide();
        },

        showIframeContent: function() {
            $('.bna-iframe-wrapper').show();
        },

        showError: function(message) {
            this.hideLoader();
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-error">' + message + '</div>'
            );
            $('html, body').animate({
                scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100
            }, 500);
        },

        showSuccess: function(message) {
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-message">' + message + '</div>'
            );
        },

        blockCheckoutForm: function() {
            $('form.checkout').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        },

        unblockCheckoutForm: function() {
            $('form.checkout').unblock();
        },

        /**
         * Timer management
         */
        startTimeout: function() {
            var self = this;
            this.state.timeout_timer = setTimeout(function() {
                self.onTimeout();
            }, this.config.timeout_duration);
        },

        clearTimeout: function() {
            if (this.state.timeout_timer) {
                clearTimeout(this.state.timeout_timer);
                this.state.timeout_timer = null;
            }
        },

        onTimeout: function() {
            this.stopStatusMonitoring();
            this.showError(bna_checkout_params.messages.iframe_timeout);
            $('.bna-refresh-iframe').show();
        },

        stopStatusMonitoring: function() {
            if (this.state.status_check_timer) {
                clearInterval(this.state.status_check_timer);
                this.state.status_check_timer = null;
            }
        },

        /**
         * Cleanup resources
         */
        cleanup: function() {
            this.stopStatusMonitoring();
            this.clearTimeout();
            this.state.current_token = null;
            this.state.iframe_url = null;
            this.state.payment_completed = false;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_IframeHandler.init();
    });

})(jQuery);
