/**
 * BNA Checkout Controller
 * Main coordinator for all BNA checkout functionality
 */

(function($) {
    'use strict';

    // Main Checkout Controller
    window.BNA_Checkout = {

        // Configuration
        config: {
            gateway_id: 'bna_gateway',
            initialized: false,
            debug: false,
            selectors: {
                checkout_form: 'form.checkout',
                payment_box: '.payment_box.payment_method_bna_gateway',
                place_order_button: '#place_order',
                notices_wrapper: '.woocommerce-notices-wrapper'
            }
        },

        // Component references
        components: {
            iframe_handler: null,
            fee_calculator: null,
            payment_methods: null,
            utils: null
        },

        // State management
        state: {
            gateway_selected: false,
            components_ready: false,
            checkout_processing: false,
            payment_in_progress: false,
            initialization_attempts: 0,
            max_init_attempts: 3
        },

        /**
         * Initialize checkout controller
         */
        init: function() {
            if (this.config.initialized) {
                return;
            }

            this.loadConfig();
            this.initializeComponents();
            this.bindEvents();
            this.checkInitialState();
            this.setupErrorHandling();
            
            this.config.initialized = true;
            this.state.initialization_attempts++;

            if (this.config.debug) {
                console.log('BNA Checkout Controller initialized');
                this.logComponentStatus();
            }
        },

        /**
         * Load configuration from WordPress
         */
        loadConfig: function() {
            if (typeof bna_checkout_params !== 'undefined') {
                this.config.debug = bna_checkout_params.debug_mode || false;
            }
        },

        /**
         * Initialize all components
         */
        initializeComponents: function() {
            var self = this;

            // Wait for all components to be available
            this.waitForComponents(function() {
                self.components.utils = window.BNA_Utils;
                self.components.iframe_handler = window.BNA_IframeHandler;
                self.components.fee_calculator = window.BNA_FeeCalculator;
                self.components.payment_methods = window.BNA_PaymentMethods;

                self.state.components_ready = true;
                self.onComponentsReady();
            });
        },

        /**
         * Wait for all components to load
         */
        waitForComponents: function(callback) {
            var self = this;
            var check_interval = 100;
            var max_wait_time = 5000;
            var elapsed_time = 0;

            var check_components = function() {
                var all_ready = window.BNA_Utils && 
                               window.BNA_IframeHandler && 
                               window.BNA_FeeCalculator && 
                               window.BNA_PaymentMethods;

                if (all_ready) {
                    callback();
                } else if (elapsed_time < max_wait_time) {
                    elapsed_time += check_interval;
                    setTimeout(check_components, check_interval);
                } else {
                    self.handleComponentLoadError();
                }
            };

            check_components();
        },

        /**
         * Handle component load error
         */
        handleComponentLoadError: function() {
            if (this.config.debug) {
                console.error('BNA components failed to load within timeout');
            }

            // Retry initialization
            if (this.state.initialization_attempts < this.config.max_init_attempts) {
                setTimeout(function() {
                    BNA_Checkout.config.initialized = false;
                    BNA_Checkout.init();
                }, 1000);
            } else {
                this.showError('Payment system failed to initialize. Please refresh the page.');
            }
        },

        /**
         * Called when all components are ready
         */
        onComponentsReady: function() {
            this.setupComponentCommunication();
            this.syncComponentStates();
            
            if (this.config.debug) {
                console.log('All BNA components ready and synchronized');
            }
        },

        /**
         * Setup communication between components
         */
        setupComponentCommunication: function() {
            var self = this;

            // Listen for component events
            $(document.body).on('bna_payment_method_changed', function(e, method) {
                self.onPaymentMethodChanged(method);
            });

            $(document.body).on('bna_iframe_status_changed', function(e, status) {
                self.onIframeStatusChanged(status);
            });

            $(document.body).on('bna_fees_calculated', function(e, fees) {
                self.onFeesCalculated(fees);
            });
        },

        /**
         * Sync component states
         */
        syncComponentStates: function() {
            // Sync initial payment method
            if (this.isGatewaySelected()) {
                var current_method = this.components.payment_methods.getCurrentMethod();
                this.onPaymentMethodChanged(current_method);
            }
        },

        /**
         * Bind main event handlers
         */
        bindEvents: function() {
            var self = this;

            // WooCommerce checkout events
            $(document.body).on('updated_checkout', function() {
                self.onCheckoutUpdated();
            });

            $(document.body).on('checkout_error', function() {
                self.onCheckoutError();
            });

            // Payment method selection
            $(document.body).on('change', 'input[name="payment_method"]', function() {
                var selected_method = $(this).val();
                self.onGatewaySelection(selected_method);
            });

            // Form submission
            $(this.config.selectors.checkout_form).on('submit', function(e) {
                return self.onFormSubmit(e);
            });

            // Place order button
            $(document.body).on('click', this.config.selectors.place_order_button, function(e) {
                if (self.isGatewaySelected()) {
                    return self.onPlaceOrderClick(e);
                }
            });

            // Window events
            $(window).on('beforeunload', function() {
                self.cleanup();
            });
        },

        /**
         * Event handlers
         */
        onCheckoutUpdated: function() {
            // Re-check gateway selection
            this.checkGatewaySelection();
            
            // Re-initialize components if needed
            if (!this.state.components_ready) {
                this.initializeComponents();
            }

            if (this.config.debug) {
                console.log('Checkout updated, components synchronized');
            }
        },

        onCheckoutError: function() {
            this.state.checkout_processing = false;
            this.state.payment_in_progress = false;
            this.unblockUI();
        },

        onGatewaySelection: function(gateway_id) {
            var was_selected = this.state.gateway_selected;
            this.state.gateway_selected = (gateway_id === this.config.gateway_id);

            if (this.state.gateway_selected && !was_selected) {
                this.onBNAGatewaySelected();
            } else if (!this.state.gateway_selected && was_selected) {
                this.onBNAGatewayDeselected();
            }
        },

        onBNAGatewaySelected: function() {
            this.showPaymentBox();
            this.activateComponents();
            
            if (this.config.debug) {
                console.log('BNA Gateway selected, components activated');
            }
        },

        onBNAGatewayDeselected: function() {
            this.hidePaymentBox();
            this.deactivateComponents();
            
            if (this.config.debug) {
                console.log('BNA Gateway deselected, components deactivated');
            }
        },

        onPaymentMethodChanged: function(method) {
            if (this.config.debug) {
                console.log('Payment method changed to:', method);
            }

            // Ensure all components are synchronized
            this.syncPaymentMethod(method);
        },

        onIframeStatusChanged: function(status) {
            switch (status) {
                case 'loading':
                    this.showProcessingState('Loading payment form...');
                    break;
                case 'ready':
                    this.hideProcessingState();
                    break;
                case 'processing':
                    this.showProcessingState('Processing payment...');
                    this.state.payment_in_progress = true;
                    break;
                case 'completed':
                    this.onPaymentCompleted();
                    break;
                case 'failed':
                    this.onPaymentFailed();
                    break;
                case 'timeout':
                    this.onPaymentTimeout();
                    break;
            }
        },

        onFeesCalculated: function(fees) {
            if (this.config.debug) {
                console.log('Fees calculated:', fees);
            }
        },

        onFormSubmit: function(e) {
            if (!this.isGatewaySelected()) {
                return true; // Allow normal processing
            }

            if (this.state.payment_in_progress) {
                e.preventDefault();
                return false;
            }

            // Let iframe handler manage the submission
            return true;
        },

        onPlaceOrderClick: function(e) {
            if (!this.isGatewaySelected()) {
                return true;
            }

            if (this.state.checkout_processing || this.state.payment_in_progress) {
                e.preventDefault();
                return false;
            }

            // Validate form before processing
            if (!this.validateCheckoutForm()) {
                e.preventDefault();
                return false;
            }

            this.state.checkout_processing = true;
            return true;
        },

        onPaymentCompleted: function() {
            this.state.payment_in_progress = false;
            this.showProcessingState('Payment completed! Redirecting...');
            
            // Allow form submission to complete
            setTimeout(function() {
                $('.checkout').submit();
            }, 1000);
        },

        onPaymentFailed: function() {
            this.state.payment_in_progress = false;
            this.state.checkout_processing = false;
            this.hideProcessingState();
            this.unblockUI();
        },

        onPaymentTimeout: function() {
            this.state.payment_in_progress = false;
            this.state.checkout_processing = false;
            this.hideProcessingState();
            this.unblockUI();
        },

        /**
         * Component management
         */
        activateComponents: function() {
            // Components activate themselves when gateway is selected
        },

        deactivateComponents: function() {
            // Clean up component states
            if (this.components.iframe_handler) {
                this.components.iframe_handler.cleanup();
            }
        },

        syncPaymentMethod: function(method) {
            // Ensure all components have the same payment method
            if (this.components.fee_calculator && this.components.fee_calculator.isEnabled()) {
                this.components.fee_calculator.setPaymentMethod(method);
            }
        },

        /**
         * UI management
         */
        showPaymentBox: function() {
            $(this.config.selectors.payment_box).slideDown();
        },

        hidePaymentBox: function() {
            $(this.config.selectors.payment_box).slideUp();
        },

        showProcessingState: function(message) {
            this.blockUI();
            this.updateStatusMessage(message);
        },

        hideProcessingState: function() {
            this.unblockUI();
            this.clearStatusMessage();
        },

        blockUI: function() {
            $(this.config.selectors.checkout_form).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        },

        unblockUI: function() {
            $(this.config.selectors.checkout_form).unblock();
        },

        updateStatusMessage: function(message) {
            var $status = $('.bna-status-message');
            if ($status.length === 0) {
                $status = $('<div class="bna-status-message"></div>');
                $(this.config.selectors.notices_wrapper).append($status);
            }
            $status.text(message).show();
        },

        clearStatusMessage: function() {
            $('.bna-status-message').remove();
        },

        /**
         * Validation
         */
        validateCheckoutForm: function() {
            // Basic validation - WooCommerce handles most validation
            var required_fields = ['billing_email', 'billing_first_name', 'billing_last_name'];
            var errors = [];

            for (var i = 0; i < required_fields.length; i++) {
                var field = required_fields[i];
                var value = $('[name="' + field + '"]').val();
                if (!value || value.trim() === '') {
                    errors.push(field + ' is required');
                }
            }

            if (errors.length > 0) {
                this.showError('Please fill in all required fields.');
                return false;
            }

            return true;
        },

        /**
         * Error handling
         */
        setupErrorHandling: function() {
            var self = this;

            // Global error handler for unhandled errors
            window.addEventListener('error', function(e) {
                if (e.filename && e.filename.indexOf('bna') !== -1) {
                    self.handleJavaScriptError(e);
                }
            });
        },

        handleJavaScriptError: function(error) {
            if (this.config.debug) {
                console.error('BNA JavaScript Error:', error);
            }

            // Don't show errors to users unless in debug mode
            if (this.config.debug) {
                this.showError('A JavaScript error occurred. Please check the browser console.');
            }
        },

        showError: function(message) {
            if (this.components.utils) {
                this.components.utils.showError(message);
            } else {
                // Fallback error display
                var $notice = $('<div class="woocommerce-error">' + message + '</div>');
                $(this.config.selectors.notices_wrapper).html($notice);
            }
        },

        showSuccess: function(message) {
            if (this.components.utils) {
                this.components.utils.showSuccess(message);
            }
        },

        /**
         * Utility methods
         */
        isGatewaySelected: function() {
            return $('input[name="payment_method"]:checked').val() === this.config.gateway_id;
        },

        checkGatewaySelection: function() {
            this.onGatewaySelection($('input[name="payment_method"]:checked').val());
        },

        checkInitialState: function() {
            this.checkGatewaySelection();
        },

        logComponentStatus: function() {
            console.log('BNA Component Status:', {
                utils: !!this.components.utils,
                iframe_handler: !!this.components.iframe_handler,
                fee_calculator: !!this.components.fee_calculator,
                payment_methods: !!this.components.payment_methods,
                components_ready: this.state.components_ready,
                gateway_selected: this.state.gateway_selected
            });
        },

        /**
         * Public API
         */
        getState: function() {
            return {
                initialized: this.config.initialized,
                gateway_selected: this.state.gateway_selected,
                components_ready: this.state.components_ready,
                checkout_processing: this.state.checkout_processing,
                payment_in_progress: this.state.payment_in_progress
            };
        },

        getComponents: function() {
            return this.components;
        },

        triggerCheckoutUpdate: function() {
            $(document.body).trigger('updated_checkout');
        },

        reset: function() {
            this.state.checkout_processing = false;
            this.state.payment_in_progress = false;
            this.unblockUI();
            this.clearStatusMessage();
        },

        /**
         * Cleanup
         */
        cleanup: function() {
            if (this.components.iframe_handler) {
                this.components.iframe_handler.cleanup();
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_Checkout.init();
    });

    // Re-initialize on checkout updates
    $(document.body).on('updated_checkout', function() {
        if (!BNA_Checkout.config.initialized) {
            BNA_Checkout.init();
        }
    });

    // Expose to global scope for debugging
    if (typeof bna_checkout_params !== 'undefined' && bna_checkout_params.debug_mode) {
        window.BNA_DEBUG = {
            checkout: BNA_Checkout,
            utils: function() { return window.BNA_Utils; },
            iframe: function() { return window.BNA_IframeHandler; },
            fees: function() { return window.BNA_FeeCalculator; },
            methods: function() { return window.BNA_PaymentMethods; }
        };
    }

})(jQuery);
