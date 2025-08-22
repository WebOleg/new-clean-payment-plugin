/**
 * BNA Fee Calculator
 * Handles dynamic fee calculation and display based on payment method
 */

(function($) {
    'use strict';

    // Fee Calculator Class
    window.BNA_FeeCalculator = {

        // Configuration
        config: {
            enabled: false,
            current_method: 'card',
            fees: {
                card_flat: 0,
                card_percent: 0,
                eft_flat: 0,
                eft_percent: 0,
                etransfer_flat: 0
            },
            tax_rate: 0.13,
            selectors: {
                payment_method: 'input[name="payment_method"], select[name="bna_payment_method"]',
                fee_display: '.bna-fee-display',
                total_display: '.order-total .amount',
                order_review: '.woocommerce-checkout-review-order-table',
                fee_row: '.bna-fee-row',
                subtotal_row: '.cart-subtotal'
            },
            debug: false
        },

        // State variables
        state: {
            original_total: 0,
            current_fees: 0,
            fee_breakdown: {},
            calculation_in_progress: false,
            last_method: null
        },

        /**
         * Initialize fee calculator
         */
        init: function() {
            this.loadConfig();
            
            if (!this.config.enabled) {
                BNA_Utils.debug.log('Fee calculation disabled');
                return;
            }

            this.bindEvents();
            this.setupUI();
            this.loadInitialData();
            
            if (this.config.debug) {
                console.log('BNA Fee Calculator initialized');
            }
        },

        /**
         * Load configuration from WordPress localization
         */
        loadConfig: function() {
            if (typeof bna_fee_params !== 'undefined') {
                $.extend(this.config, bna_fee_params);
            }
            
            if (typeof bna_checkout_params !== 'undefined') {
                this.config.enabled = bna_checkout_params.enable_fees || false;
                this.config.debug = bna_checkout_params.debug_mode || false;
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Listen for payment method changes
            $(document.body).on('change', this.config.selectors.payment_method, function() {
                var method = $(this).val();
                if (method === 'bna_gateway' || $(this).closest('.bna-payment-fields').length) {
                    var payment_type = self.getSelectedPaymentType();
                    self.onPaymentMethodChanged(payment_type);
                } else {
                    self.hideFeeDisplay();
                }
            });

            // Listen for BNA specific payment method changes
            $(document.body).on('change', '#bna_payment_method', function() {
                var method = $(this).val();
                self.onPaymentMethodChanged(method);
            });

            // Listen for cart updates
            $(document.body).on('updated_checkout', function() {
                self.onCheckoutUpdated();
            });

            // Listen for order total changes
            $(document.body).on('updated_cart_totals', function() {
                self.onCartTotalsUpdated();
            });

            // Debounced calculation trigger
            this.debouncedCalculate = BNA_Utils.events.debounce(function() {
                self.calculateFees();
            }, 500);
        },

        /**
         * Setup UI elements for fee display
         */
        setupUI: function() {
            this.createFeeDisplayElements();
            this.initializeOrderTotal();
        },

        /**
         * Load initial data and calculate fees
         */
        loadInitialData: function() {
            this.initializeOrderTotal();
            
            var selected_method = this.getSelectedPaymentType();
            if (selected_method && this.isBNAGatewaySelected()) {
                this.onPaymentMethodChanged(selected_method);
            }
        },

        /**
         * Handle payment method change
         */
        onPaymentMethodChanged: function(payment_method) {
            if (!payment_method || payment_method === this.state.last_method) {
                return;
            }

            this.state.last_method = payment_method;
            this.config.current_method = payment_method;

            BNA_Utils.debug.log('Payment method changed to:', payment_method);

            if (this.isBNAGatewaySelected()) {
                this.showFeeDisplay();
                this.debouncedCalculate();
            } else {
                this.hideFeeDisplay();
            }
        },

        /**
         * Handle checkout update
         */
        onCheckoutUpdated: function() {
            this.initializeOrderTotal();
            
            if (this.isBNAGatewaySelected()) {
                var method = this.getSelectedPaymentType();
                if (method) {
                    this.debouncedCalculate();
                }
            }
        },

        /**
         * Handle cart totals update
         */
        onCartTotalsUpdated: function() {
            this.initializeOrderTotal();
            this.updateFeeDisplay();
        },

        /**
         * Calculate fees via AJAX
         */
        calculateFees: function() {
            var self = this;

            if (this.state.calculation_in_progress) {
                return;
            }

            var order_total = this.getOrderTotal();
            var payment_method = this.config.current_method;

            if (!payment_method || order_total <= 0) {
                this.clearFees();
                return;
            }

            this.state.calculation_in_progress = true;
            this.showCalculationLoader();

            var request_data = {
                payment_method: payment_method,
                order_total: order_total,
                currency: bna_checkout_params.currency_code
            };

            BNA_Utils.ajax.request('bna_calculate_fees', request_data, {
                success: function(response) {
                    BNA_Utils.ajax.handleResponse(response, 
                        function(data) {
                            self.onFeesCalculated(data);
                        },
                        function(message) {
                            self.onCalculationError(message);
                        }
                    );
                },
                error: function(xhr, status, error) {
                    BNA_Utils.ajax.handleError(xhr, status, error, function(message) {
                        self.onCalculationError(message);
                    });
                },
                complete: function() {
                    self.state.calculation_in_progress = false;
                    self.hideCalculationLoader();
                }
            });
        },

        /**
         * Handle successful fee calculation
         */
        onFeesCalculated: function(data) {
            this.state.fee_breakdown = data;
            this.state.current_fees = data.fee_with_tax || 0;

            BNA_Utils.debug.log('Fees calculated:', data);

            this.updateFeeDisplay();
            this.updateOrderTotal();
            this.showFeeBreakdown(data);
        },

        /**
         * Handle fee calculation error
         */
        onCalculationError: function(message) {
            BNA_Utils.debug.error('Fee calculation failed:', message);
            this.clearFees();
            
            // Show error only if user is actively interacting
            if (this.config.debug) {
                BNA_Utils.showError('Fee calculation error: ' + message);
            }
        },

        /**
         * Update fee display in UI
         */
        updateFeeDisplay: function() {
            var fee_amount = this.state.current_fees;
            var $fee_row = $(this.config.selectors.fee_row);
            
            if (fee_amount > 0) {
                var formatted_fee = BNA_Utils.format.currency(fee_amount);
                
                if ($fee_row.length) {
                    $fee_row.find('.amount').html(formatted_fee);
                } else {
                    this.addFeeRow(formatted_fee);
                }
                
                $fee_row.show();
            } else {
                $fee_row.hide();
            }
        },

        /**
         * Update order total
         */
        updateOrderTotal: function() {
            var original_total = this.state.original_total;
            var fee_amount = this.state.current_fees;
            var new_total = original_total + fee_amount;

            var formatted_total = BNA_Utils.format.currency(new_total);
            $(this.config.selectors.total_display).html(formatted_total);

            // Update hidden input if exists
            $('input[name="order_total"]').val(new_total.toFixed(2));

            BNA_Utils.debug.log('Total updated:', {
                original: original_total,
                fee: fee_amount,
                new_total: new_total
            });
        },

        /**
         * Show detailed fee breakdown
         */
        showFeeBreakdown: function(fee_data) {
            if (!fee_data.fees_enabled || fee_data.total_fee <= 0) {
                this.hideFeeBreakdown();
                return;
            }

            var breakdown_html = this.generateFeeBreakdownHTML(fee_data);
            this.displayFeeBreakdown(breakdown_html);
        },

        /**
         * Generate fee breakdown HTML
         */
        generateFeeBreakdownHTML: function(fee_data) {
            var html = '<div class="bna-fee-breakdown">';
            html += '<h4>Fee Breakdown</h4>';
            html += '<div class="bna-fee-details">';

            // Flat fee
            if (fee_data.flat_fee > 0) {
                html += '<div class="bna-fee-line">';
                html += '<span class="fee-label">Processing Fee:</span>';
                html += '<span class="fee-amount">' + BNA_Utils.format.currency(fee_data.flat_fee) + '</span>';
                html += '</div>';
            }

            // Percentage fee
            if (fee_data.percentage_amount > 0) {
                html += '<div class="bna-fee-line">';
                html += '<span class="fee-label">Percentage Fee (' + fee_data.percentage_fee + '%):</span>';
                html += '<span class="fee-amount">' + BNA_Utils.format.currency(fee_data.percentage_amount) + '</span>';
                html += '</div>';
            }

            // Tax
            if (fee_data.tax_amount > 0) {
                html += '<div class="bna-fee-line">';
                html += '<span class="fee-label">Tax (' + BNA_Utils.format.percentage(fee_data.tax_rate * 100) + '):</span>';
                html += '<span class="fee-amount">' + BNA_Utils.format.currency(fee_data.tax_amount) + '</span>';
                html += '</div>';
            }

            html += '</div></div>';
            return html;
        },

        /**
         * Display fee breakdown
         */
        displayFeeBreakdown: function(html) {
            var $container = $('.bna-fee-breakdown-container');
            
            if ($container.length === 0) {
                $container = $('<div class="bna-fee-breakdown-container"></div>');
                $(this.config.selectors.order_review).after($container);
            }
            
            $container.html(html).slideDown();
        },

        /**
         * Hide fee breakdown
         */
        hideFeeBreakdown: function() {
            $('.bna-fee-breakdown-container').slideUp();
        },

        /**
         * Create fee display elements
         */
        createFeeDisplayElements: function() {
            // Fee display will be added dynamically when needed
        },

        /**
         * Add fee row to order review table
         */
        addFeeRow: function(formatted_fee) {
            var fee_row_html = '<tr class="bna-fee-row fee-total">';
            fee_row_html += '<th>BNA Processing Fee (Includes Tax)</th>';
            fee_row_html += '<td><strong><span class="amount">' + formatted_fee + '</span></strong></td>';
            fee_row_html += '</tr>';

            var $order_table = $(this.config.selectors.order_review);
            var $total_row = $order_table.find('.order-total');
            
            if ($total_row.length) {
                $total_row.before(fee_row_html);
            } else {
                $order_table.find('tbody').append(fee_row_html);
            }
        },

        /**
         * Show/hide fee display
         */
        showFeeDisplay: function() {
            $(this.config.selectors.fee_display).show();
        },

        hideFeeDisplay: function() {
            $(this.config.selectors.fee_display).hide();
            $(this.config.selectors.fee_row).hide();
            this.hideFeeBreakdown();
            this.clearFees();
        },

        /**
         * Show/hide calculation loader
         */
        showCalculationLoader: function() {
            var $fee_row = $(this.config.selectors.fee_row);
            if ($fee_row.length) {
                BNA_Utils.dom.setLoading($fee_row.find('.amount'), true);
            }
        },

        hideCalculationLoader: function() {
            var $fee_row = $(this.config.selectors.fee_row);
            if ($fee_row.length) {
                BNA_Utils.dom.setLoading($fee_row.find('.amount'), false);
            }
        },

        /**
         * Helper functions
         */
        getOrderTotal: function() {
            // Try to get from stored original total first
            if (this.state.original_total > 0) {
                return this.state.original_total;
            }

            // Parse from DOM
            var total_text = $(this.config.selectors.total_display).text();
            return BNA_Utils.format.parseCurrency(total_text);
        },

        getSelectedPaymentType: function() {
            // Check BNA specific payment method selector
            var bna_method = $('#bna_payment_method').val();
            if (bna_method) {
                return bna_method;
            }

            // Default to card if BNA gateway is selected
            if (this.isBNAGatewaySelected()) {
                return 'card';
            }

            return null;
        },

        isBNAGatewaySelected: function() {
            var selected_gateway = $('input[name="payment_method"]:checked').val();
            return selected_gateway === 'bna_gateway';
        },

        initializeOrderTotal: function() {
            var current_total = this.getOrderTotal();
            
            // Store original total only if fees are not included
            if (this.state.current_fees === 0 || this.state.original_total === 0) {
                this.state.original_total = current_total;
            }

            BNA_Utils.debug.log('Order total initialized:', this.state.original_total);
        },

        clearFees: function() {
            this.state.current_fees = 0;
            this.state.fee_breakdown = {};
            
            // Reset total to original
            if (this.state.original_total > 0) {
                var formatted_total = BNA_Utils.format.currency(this.state.original_total);
                $(this.config.selectors.total_display).html(formatted_total);
            }
            
            $(this.config.selectors.fee_row).remove();
            this.hideFeeBreakdown();
        },

        /**
         * Public API methods
         */
        recalculate: function() {
            if (this.config.enabled && this.isBNAGatewaySelected()) {
                this.calculateFees();
            }
        },

        setPaymentMethod: function(method) {
            this.config.current_method = method;
            this.recalculate();
        },

        getCurrentFees: function() {
            return this.state.current_fees;
        },

        getFeeBreakdown: function() {
            return this.state.fee_breakdown;
        },

        isEnabled: function() {
            return this.config.enabled;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_FeeCalculator.init();
    });

    // Also initialize on checkout update
    $(document.body).on('updated_checkout', function() {
        if (typeof BNA_FeeCalculator !== 'undefined') {
            BNA_FeeCalculator.init();
        }
    });

})(jQuery);
