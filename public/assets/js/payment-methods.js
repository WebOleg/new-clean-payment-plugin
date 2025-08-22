/**
 * BNA Payment Methods Handler
 * Manages payment method selection and UI updates
 */

(function($) {
    'use strict';

    // Payment Methods Handler Class
    window.BNA_PaymentMethods = {

        // Configuration
        config: {
            available_methods: ['card', 'eft'],
            current_method: 'card',
            selectors: {
                payment_method_select: '#bna_payment_method',
                payment_method_wrapper: '.bna-payment-methods-wrapper',
                method_info: '.bna-method-info',
                method_description: '.bna-method-description',
                processing_time: '.bna-processing-time',
                method_icon: '.bna-method-icon'
            },
            debug: false
        },

        // Payment method information
        methods_info: {
            'card': {
                label: 'Credit/Debit Card',
                description: 'Pay securely with your credit or debit card',
                icon: 'credit-card',
                processing_time: 'Instant',
                supports_refunds: true,
                fees_info: 'Processing fees may apply'
            },
            'eft': {
                label: 'Electronic Funds Transfer',
                description: 'Direct transfer from your bank account',
                icon: 'bank',
                processing_time: '1-2 business days',
                supports_refunds: true,
                fees_info: 'Lower processing fees'
            },
            'e-transfer': {
                label: 'E-Transfer',
                description: 'Email money transfer',
                icon: 'email',
                processing_time: '30 minutes - 2 hours',
                supports_refunds: false,
                fees_info: 'Fixed processing fee'
            }
        },

        // State variables
        state: {
            initialization_complete: false,
            method_change_in_progress: false,
            last_successful_method: null
        },

        /**
         * Initialize payment methods handler
         */
        init: function() {
            this.loadConfig();
            this.bindEvents();
            this.setupUI();
            this.loadCurrentMethod();
            
            this.state.initialization_complete = true;
            
            if (this.config.debug) {
                console.log('BNA Payment Methods initialized with methods:', this.config.available_methods);
            }
        },

        /**
         * Load configuration from WordPress localization
         */
        loadConfig: function() {
            if (typeof bna_checkout_params !== 'undefined') {
                this.config.available_methods = bna_checkout_params.payment_methods || ['card', 'eft'];
                this.config.debug = bna_checkout_params.debug_mode || false;
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Payment method selection change
            $(document.body).on('change', this.config.selectors.payment_method_select, function() {
                var selected_method = $(this).val();
                self.onMethodChanged(selected_method);
            });

            // Listen for BNA gateway selection
            $(document.body).on('change', 'input[name="payment_method"]', function() {
                if ($(this).val() === 'bna_gateway') {
                    self.onBNAGatewaySelected();
                } else {
                    self.onBNAGatewayDeselected();
                }
            });

            // Listen for checkout updates
            $(document.body).on('updated_checkout', function() {
                self.onCheckoutUpdated();
            });
        },

        /**
         * Setup UI elements
         */
        setupUI: function() {
            this.createMethodSelector();
            this.createMethodInfoDisplay();
            this.styleMethodElements();
        },

        /**
         * Load current method from form or default
         */
        loadCurrentMethod: function() {
            var $select = $(this.config.selectors.payment_method_select);
            var current_method = $select.val() || this.config.available_methods[0] || 'card';
            
            this.config.current_method = current_method;
            this.updateMethodDisplay(current_method, false); // Don't trigger AJAX on init
        },

        /**
         * Handle payment method change
         */
        onMethodChanged: function(method) {
            if (!method || method === this.config.current_method || this.state.method_change_in_progress) {
                return;
            }

            BNA_Utils.debug.log('Payment method changing from', this.config.current_method, 'to', method);

            this.state.method_change_in_progress = true;
            this.config.current_method = method;

            this.updateMethodDisplay(method, true);
            this.notifyOtherComponents(method);
        },

        /**
         * Handle BNA gateway selection
         */
        onBNAGatewaySelected: function() {
            this.showMethodSelector();
            this.loadCurrentMethod();
        },

        /**
         * Handle BNA gateway deselection
         */
        onBNAGatewayDeselected: function() {
            this.hideMethodSelector();
        },

        /**
         * Handle checkout update
         */
        onCheckoutUpdated: function() {
            // Re-initialize if needed
            if (!this.state.initialization_complete) {
                this.init();
            }
        },

        /**
         * Update payment method via AJAX
         */
        updateMethodViaAjax: function(method) {
            var self = this;
            var order_total = this.getOrderTotal();

            var request_data = {
                payment_method: method,
                order_total: order_total
            };

            this.showMethodLoader();

            BNA_Utils.ajax.request('bna_update_payment_method', request_data, {
                success: function(response) {
                    BNA_Utils.ajax.handleResponse(response,
                        function(data) {
                            self.onMethodUpdateSuccess(data);
                        },
                        function(message) {
                            self.onMethodUpdateError(message);
                        }
                    );
                },
                error: function(xhr, status, error) {
                    BNA_Utils.ajax.handleError(xhr, status, error, function(message) {
                        self.onMethodUpdateError(message);
                    });
                },
                complete: function() {
                    self.hideMethodLoader();
                    self.state.method_change_in_progress = false;
                }
            });
        },

        /**
         * Handle successful method update
         */
        onMethodUpdateSuccess: function(data) {
            this.state.last_successful_method = data.payment_method;
            this.updateMethodInfo(data.method_info);
            
            BNA_Utils.debug.log('Payment method updated successfully:', data);
        },

        /**
         * Handle method update error
         */
        onMethodUpdateError: function(message) {
            BNA_Utils.debug.error('Payment method update failed:', message);
            
            // Revert to last successful method
            if (this.state.last_successful_method) {
                this.revertToMethod(this.state.last_successful_method);
            }
            
            // Show error only in debug mode to avoid user confusion
            if (this.config.debug) {
                BNA_Utils.showError('Payment method update failed: ' + message);
            }
        },

        /**
         * Update method display
         */
        updateMethodDisplay: function(method, trigger_ajax) {
            trigger_ajax = trigger_ajax !== false;
            
            this.updateMethodSelector(method);
            this.updateMethodInfo(this.methods_info[method]);
            this.highlightSelectedMethod(method);
            
            if (trigger_ajax && this.state.initialization_complete) {
                this.updateMethodViaAjax(method);
            }
        },

        /**
         * Update method selector value
         */
        updateMethodSelector: function(method) {
            var $select = $(this.config.selectors.payment_method_select);
            if ($select.val() !== method) {
                $select.val(method);
            }
        },

        /**
         * Update method information display
         */
        updateMethodInfo: function(method_info) {
            if (!method_info) return;

            $(this.config.selectors.method_description).text(method_info.description || '');
            $(this.config.selectors.processing_time).text(method_info.processing_time || '');
            
            this.updateMethodIcon(method_info.icon);
            this.updateFeesInfo(method_info.fees_info);
        },

        /**
         * Update method icon
         */
        updateMethodIcon: function(icon_type) {
            var $icon = $(this.config.selectors.method_icon);
            if ($icon.length) {
                $icon.removeClass('icon-card icon-bank icon-email icon-credit-card')
                     .addClass('icon-' + icon_type);
            }
        },

        /**
         * Update fees information
         */
        updateFeesInfo: function(fees_info) {
            var $fees_info = $('.bna-fees-info');
            if ($fees_info.length) {
                $fees_info.text(fees_info || '');
            }
        },

        /**
         * Highlight selected method
         */
        highlightSelectedMethod: function(method) {
            $('.bna-method-option').removeClass('selected');
            $('.bna-method-option[data-method="' + method + '"]').addClass('selected');
        },

        /**
         * Create method selector if it doesn't exist
         */
        createMethodSelector: function() {
            var $existing = $(this.config.selectors.payment_method_select);
            
            if ($existing.length > 0) {
                return; // Already exists
            }

            // Only create if we have multiple methods
            if (this.config.available_methods.length <= 1) {
                return;
            }

            var selector_html = this.generateMethodSelectorHTML();
            this.insertMethodSelector(selector_html);
        },

        /**
         * Generate method selector HTML
         */
        generateMethodSelectorHTML: function() {
            var html = '<div class="bna-payment-methods-wrapper">';
            html += '<label for="bna_payment_method">Payment Method <span class="required">*</span></label>';
            html += '<select id="bna_payment_method" name="bna_payment_method" class="select">';

            for (var i = 0; i < this.config.available_methods.length; i++) {
                var method = this.config.available_methods[i];
                var method_info = this.methods_info[method];
                var selected = method === this.config.current_method ? 'selected' : '';
                
                html += '<option value="' + method + '" ' + selected + '>';
                html += method_info.label;
                html += '</option>';
            }

            html += '</select>';
            html += '</div>';

            return html;
        },

        /**
         * Insert method selector into DOM
         */
        insertMethodSelector: function(html) {
            // Insert after payment method radio buttons
            var $payment_methods = $('.wc_payment_methods');
            if ($payment_methods.length) {
                $payment_methods.after(html);
            } else {
                // Fallback - insert in payment box
                $('.payment_box.payment_method_bna_gateway').prepend(html);
            }
        },

        /**
         * Create method info display
         */
        createMethodInfoDisplay: function() {
            if ($('.bna-method-info').length > 0) {
                return; // Already exists
            }

            var info_html = '<div class="bna-method-info">';
            info_html += '<div class="bna-method-description"></div>';
            info_html += '<div class="bna-processing-time-wrapper">';
            info_html += '<span class="label">Processing Time:</span> ';
            info_html += '<span class="bna-processing-time"></span>';
            info_html += '</div>';
            info_html += '</div>';

            $(this.config.selectors.payment_method_wrapper).after(info_html);
        },

        /**
         * Style method elements
         */
        styleMethodElements: function() {
            // Add CSS classes for styling
            $(this.config.selectors.payment_method_select).addClass('bna-styled-select');
            $(this.config.selectors.method_info).addClass('bna-styled-info');
        },

        /**
         * Show/hide method selector
         */
        showMethodSelector: function() {
            $(this.config.selectors.payment_method_wrapper).slideDown();
            $(this.config.selectors.method_info).slideDown();
        },

        hideMethodSelector: function() {
            $(this.config.selectors.payment_method_wrapper).slideUp();
            $(this.config.selectors.method_info).slideUp();
        },

        /**
         * Show/hide method loading state
         */
        showMethodLoader: function() {
            BNA_Utils.dom.setLoading(this.config.selectors.payment_method_select, true);
        },

        hideMethodLoader: function() {
            BNA_Utils.dom.setLoading(this.config.selectors.payment_method_select, false);
        },

        /**
         * Revert to previous method
         */
        revertToMethod: function(method) {
            this.config.current_method = method;
            this.updateMethodDisplay(method, false);
        },

        /**
         * Notify other components of method change
         */
        notifyOtherComponents: function(method) {
            // Notify fee calculator
            if (typeof BNA_FeeCalculator !== 'undefined' && BNA_FeeCalculator.isEnabled()) {
                BNA_FeeCalculator.setPaymentMethod(method);
            }

            // Notify iframe handler that form data changed
            if (typeof BNA_IframeHandler !== 'undefined') {
                BNA_IframeHandler.onFormDataChanged();
            }

            // Trigger custom event
            $(document.body).trigger('bna_payment_method_changed', [method]);
        },

        /**
         * Helper functions
         */
        getOrderTotal: function() {
            if (typeof BNA_FeeCalculator !== 'undefined') {
                return BNA_FeeCalculator.getCurrentFees() > 0 
                    ? BNA_FeeCalculator.getOrderTotal() + BNA_FeeCalculator.getCurrentFees()
                    : BNA_FeeCalculator.getOrderTotal();
            }
            
            // Fallback to parsing from DOM
            var total_text = $('.order-total .amount').text();
            return BNA_Utils.format.parseCurrency(total_text);
        },

        getCurrentMethod: function() {
            return this.config.current_method;
        },

        getAvailableMethods: function() {
            return this.config.available_methods;
        },

        getMethodInfo: function(method) {
            return this.methods_info[method] || null;
        },

        /**
         * Public API methods
         */
        setMethod: function(method) {
            if (this.config.available_methods.indexOf(method) !== -1) {
                $(this.config.selectors.payment_method_select).val(method).trigger('change');
            }
        },

        refreshMethods: function() {
            this.loadConfig();
            this.createMethodSelector();
            this.loadCurrentMethod();
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_PaymentMethods.init();
    });

    // Re-initialize on checkout update
    $(document.body).on('updated_checkout', function() {
        if (typeof BNA_PaymentMethods !== 'undefined') {
            BNA_PaymentMethods.init();
        }
    });

})(jQuery);
