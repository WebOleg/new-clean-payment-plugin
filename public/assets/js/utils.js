/**
 * BNA Utilities
 * Common helper functions and utilities for all BNA modules
 */

(function($) {
    'use strict';

    // BNA Utilities Namespace
    window.BNA_Utils = {

        /**
         * Configuration and constants
         */
        config: {
            debug: false,
            currency_symbol: '$',
            currency_code: 'CAD',
            decimal_places: 2,
            thousand_separator: ',',
            decimal_separator: '.',
            ajax_timeout: 30000
        },

        /**
         * Initialize utilities
         */
        init: function() {
            this.loadConfig();
            this.setupAjaxDefaults();
            
            if (this.config.debug) {
                console.log('BNA Utils initialized');
            }
        },

        /**
         * Load configuration from WordPress localization
         */
        loadConfig: function() {
            if (typeof bna_checkout_params !== 'undefined') {
                this.config.debug = bna_checkout_params.debug_mode || false;
                this.config.currency_symbol = bna_checkout_params.currency_symbol || '$';
                this.config.currency_code = bna_checkout_params.currency_code || 'CAD';
            }
        },

        /**
         * Setup jQuery AJAX defaults
         */
        setupAjaxDefaults: function() {
            $.ajaxSetup({
                timeout: this.config.ajax_timeout,
                cache: false
            });
        },

        /**
         * AJAX Helper Functions
         */
        ajax: {
            /**
             * Make secure AJAX request with nonce
             */
            request: function(action, data, options) {
                var defaults = {
                    url: bna_checkout_params.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    timeout: BNA_Utils.config.ajax_timeout
                };

                var settings = $.extend({}, defaults, options);

                // Add nonce and action to data
                var requestData = $.extend({
                    action: action,
                    nonce: bna_checkout_params.checkout_nonce
                }, data);

                settings.data = requestData;

                return $.ajax(settings);
            },

            /**
             * Handle AJAX response consistently
             */
            handleResponse: function(response, successCallback, errorCallback) {
                if (response && response.success) {
                    if (typeof successCallback === 'function') {
                        successCallback(response.data);
                    }
                } else {
                    var message = (response && response.data && response.data.message) 
                        ? response.data.message 
                        : 'An error occurred';
                    
                    if (typeof errorCallback === 'function') {
                        errorCallback(message, response);
                    } else {
                        BNA_Utils.showError(message);
                    }
                }
            },

            /**
             * Handle AJAX error consistently
             */
            handleError: function(xhr, status, error, errorCallback) {
                var message = 'Connection error';
                
                if (status === 'timeout') {
                    message = 'Request timed out. Please try again.';
                } else if (status === 'abort') {
                    message = 'Request was cancelled.';
                } else if (error) {
                    message = 'Error: ' + error;
                }

                if (BNA_Utils.config.debug) {
                    console.error('AJAX Error:', {
                        status: status,
                        error: error,
                        xhr: xhr
                    });
                }

                if (typeof errorCallback === 'function') {
                    errorCallback(message, xhr, status, error);
                } else {
                    BNA_Utils.showError(message);
                }
            }
        },

        /**
         * Form Helper Functions
         */
        form: {
            /**
             * Get form data as object
             */
            getData: function(form_selector) {
                var $form = $(form_selector);
                var data = {};

                $form.find('input, select, textarea').each(function() {
                    var $field = $(this);
                    var name = $field.attr('name');
                    var value = $field.val();

                    if (name && value !== undefined) {
                        if ($field.is(':checkbox')) {
                            data[name] = $field.is(':checked');
                        } else if ($field.is(':radio')) {
                            if ($field.is(':checked')) {
                                data[name] = value;
                            }
                        } else {
                            data[name] = value;
                        }
                    }
                });

                return data;
            },

            /**
             * Validate required fields
             */
            validateRequired: function(data, required_fields) {
                var errors = [];

                for (var i = 0; i < required_fields.length; i++) {
                    var field = required_fields[i];
                    if (!data[field] || data[field].toString().trim() === '') {
                        errors.push(field + ' is required');
                    }
                }

                return errors;
            },

            /**
             * Highlight field errors
             */
            highlightErrors: function(fields) {
                // Remove existing error classes
                $('.woocommerce-invalid').removeClass('woocommerce-invalid');

                // Add error classes to specified fields
                for (var i = 0; i < fields.length; i++) {
                    var field_name = fields[i];
                    var $field = $('[name="' + field_name + '"]');
                    $field.closest('.form-row').addClass('woocommerce-invalid');
                }
            },

            /**
             * Clear field errors
             */
            clearErrors: function() {
                $('.woocommerce-invalid').removeClass('woocommerce-invalid');
                $('.woocommerce-error, .woocommerce-message').remove();
            }
        },

        /**
         * Validation Helper Functions
         */
        validate: {
            /**
             * Validate email address
             */
            email: function(email) {
                var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return pattern.test(email);
            },

            /**
             * Validate phone number
             */
            phone: function(phone) {
                var cleaned = phone.replace(/[^0-9]/g, '');
                return cleaned.length >= 7 && cleaned.length <= 15;
            },

            /**
             * Validate postal code by country
             */
            postalCode: function(postal_code, country) {
                var patterns = {
                    'CA': /^[A-Z][0-9][A-Z]\s?[0-9][A-Z][0-9]$/i,
                    'US': /^[0-9]{5}(-[0-9]{4})?$/,
                    'UK': /^[A-Z]{1,2}[0-9R][0-9A-Z]?\s?[0-9][A-Z]{2}$/i
                };

                var pattern = patterns[country];
                return pattern ? pattern.test(postal_code) : postal_code.length > 0;
            },

            /**
             * Validate amount
             */
            amount: function(amount, min_amount) {
                var num = parseFloat(amount);
                min_amount = parseFloat(min_amount) || 0;
                return !isNaN(num) && num > 0 && num >= min_amount;
            }
        },

        /**
         * Currency and Number Formatting
         */
        format: {
            /**
             * Format currency amount
             */
            currency: function(amount, include_symbol) {
                include_symbol = include_symbol !== false;
                
                var num = parseFloat(amount);
                if (isNaN(num)) {
                    num = 0;
                }

                var formatted = num.toFixed(BNA_Utils.config.decimal_places);
                
                // Add thousand separators
                formatted = formatted.replace(/\B(?=(\d{3})+(?!\d))/g, BNA_Utils.config.thousand_separator);

                if (include_symbol) {
                    formatted = BNA_Utils.config.currency_symbol + formatted;
                }

                return formatted;
            },

            /**
             * Parse currency string to number
             */
            parseCurrency: function(currency_string) {
                if (typeof currency_string !== 'string') {
                    return parseFloat(currency_string) || 0;
                }

                // Remove currency symbols and thousand separators
                var cleaned = currency_string.replace(/[^0-9.-]/g, '');
                return parseFloat(cleaned) || 0;
            },

            /**
             * Format percentage
             */
            percentage: function(value, decimal_places) {
                decimal_places = decimal_places || 2;
                var num = parseFloat(value);
                return isNaN(num) ? '0%' : num.toFixed(decimal_places) + '%';
            }
        },

        /**
         * DOM Helper Functions
         */
        dom: {
            /**
             * Show/hide loading state
             */
            setLoading: function(element, loading) {
                var $element = $(element);
                
                if (loading) {
                    $element.addClass('bna-loading').prop('disabled', true);
                    if (!$element.find('.bna-spinner').length) {
                        $element.append('<span class="bna-spinner"></span>');
                    }
                } else {
                    $element.removeClass('bna-loading').prop('disabled', false);
                    $element.find('.bna-spinner').remove();
                }
            },

            /**
             * Scroll to element smoothly
             */
            scrollTo: function(element, offset) {
                offset = offset || 100;
                var $element = $(element);
                
                if ($element.length) {
                    $('html, body').animate({
                        scrollTop: $element.offset().top - offset
                    }, 500);
                }
            },

            /**
             * Create notification element
             */
            createNotification: function(message, type) {
                type = type || 'error';
                
                return $('<div class="woocommerce-' + type + '">' + message + '</div>');
            },

            /**
             * Update element with loading animation
             */
            updateWithLoading: function(element, new_content, duration) {
                duration = duration || 300;
                var $element = $(element);
                
                $element.fadeOut(duration / 2, function() {
                    $element.html(new_content).fadeIn(duration / 2);
                });
            }
        },

        /**
         * Storage Helper Functions
         */
        storage: {
            /**
             * Set item in sessionStorage with expiry
             */
            set: function(key, value, expiry_minutes) {
                expiry_minutes = expiry_minutes || 60;
                
                var data = {
                    value: value,
                    expiry: Date.now() + (expiry_minutes * 60 * 1000)
                };

                try {
                    sessionStorage.setItem('bna_' + key, JSON.stringify(data));
                } catch (e) {
                    if (BNA_Utils.config.debug) {
                        console.warn('SessionStorage not available:', e);
                    }
                }
            },

            /**
             * Get item from sessionStorage
             */
            get: function(key) {
                try {
                    var item = sessionStorage.getItem('bna_' + key);
                    if (!item) {
                        return null;
                    }

                    var data = JSON.parse(item);
                    
                    // Check if expired
                    if (Date.now() > data.expiry) {
                        sessionStorage.removeItem('bna_' + key);
                        return null;
                    }

                    return data.value;
                } catch (e) {
                    if (BNA_Utils.config.debug) {
                        console.warn('Error reading from sessionStorage:', e);
                    }
                    return null;
                }
            },

            /**
             * Remove item from sessionStorage
             */
            remove: function(key) {
                try {
                    sessionStorage.removeItem('bna_' + key);
                } catch (e) {
                    if (BNA_Utils.config.debug) {
                        console.warn('Error removing from sessionStorage:', e);
                    }
                }
            },

            /**
             * Clear all BNA items from sessionStorage
             */
            clear: function() {
                try {
                    var keys = Object.keys(sessionStorage);
                    for (var i = 0; i < keys.length; i++) {
                        if (keys[i].startsWith('bna_')) {
                            sessionStorage.removeItem(keys[i]);
                        }
                    }
                } catch (e) {
                    if (BNA_Utils.config.debug) {
                        console.warn('Error clearing sessionStorage:', e);
                    }
                }
            }
        },

        /**
         * Event Management
         */
        events: {
            /**
             * Debounce function calls
             */
            debounce: function(func, wait, immediate) {
                var timeout;
                return function() {
                    var context = this, args = arguments;
                    var later = function() {
                        timeout = null;
                        if (!immediate) func.apply(context, args);
                    };
                    var callNow = immediate && !timeout;
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                    if (callNow) func.apply(context, args);
                };
            },

            /**
             * Throttle function calls
             */
            throttle: function(func, limit) {
                var inThrottle;
                return function() {
                    var args = arguments;
                    var context = this;
                    if (!inThrottle) {
                        func.apply(context, args);
                        inThrottle = true;
                        setTimeout(function() {
                            inThrottle = false;
                        }, limit);
                    }
                };
            }
        },

        /**
         * Debug and Logging
         */
        debug: {
            /**
             * Log message if debug mode enabled
             */
            log: function() {
                if (BNA_Utils.config.debug && console && console.log) {
                    console.log.apply(console, ['[BNA]'].concat(Array.prototype.slice.call(arguments)));
                }
            },

            /**
             * Log error message
             */
            error: function() {
                if (console && console.error) {
                    console.error.apply(console, ['[BNA ERROR]'].concat(Array.prototype.slice.call(arguments)));
                }
            },

            /**
             * Log warning message
             */
            warn: function() {
                if (console && console.warn) {
                    console.warn.apply(console, ['[BNA WARN]'].concat(Array.prototype.slice.call(arguments)));
                }
            }
        },

        /**
         * UI Helper Functions
         */
        showError: function(message) {
            var $notice = this.dom.createNotification(message, 'error');
            $('.woocommerce-notices-wrapper').html($notice);
            this.dom.scrollTo('.woocommerce-notices-wrapper');
        },

        showSuccess: function(message) {
            var $notice = this.dom.createNotification(message, 'message');
            $('.woocommerce-notices-wrapper').html($notice);
            this.dom.scrollTo('.woocommerce-notices-wrapper');
        },

        showNotice: function(message) {
            var $notice = this.dom.createNotification(message, 'info');
            $('.woocommerce-notices-wrapper').html($notice);
            this.dom.scrollTo('.woocommerce-notices-wrapper');
        },

        /**
         * Browser Detection
         */
        browser: {
            /**
             * Check if mobile device
             */
            isMobile: function() {
                return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            },

            /**
             * Check if touch device
             */
            isTouch: function() {
                return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
            },

            /**
             * Get browser info
             */
            getInfo: function() {
                var ua = navigator.userAgent;
                var browser = {
                    name: 'unknown',
                    version: 'unknown'
                };

                if (ua.indexOf('Chrome') > -1) {
                    browser.name = 'Chrome';
                } else if (ua.indexOf('Firefox') > -1) {
                    browser.name = 'Firefox';
                } else if (ua.indexOf('Safari') > -1) {
                    browser.name = 'Safari';
                } else if (ua.indexOf('Edge') > -1) {
                    browser.name = 'Edge';
                }

                return browser;
            }
        },

        /**
         * Utility Functions
         */
        utils: {
            /**
             * Generate random string
             */
            randomString: function(length) {
                length = length || 10;
                var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                var result = '';
                for (var i = 0; i < length; i++) {
                    result += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                return result;
            },

            /**
             * Deep clone object
             */
            clone: function(obj) {
                return JSON.parse(JSON.stringify(obj));
            },

            /**
             * Check if object is empty
             */
            isEmpty: function(obj) {
                return Object.keys(obj).length === 0;
            },

            /**
             * Merge objects
             */
            merge: function() {
                var result = {};
                for (var i = 0; i < arguments.length; i++) {
                    var obj = arguments[i];
                    for (var key in obj) {
                        if (obj.hasOwnProperty(key)) {
                            result[key] = obj[key];
                        }
                    }
                }
                return result;
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_Utils.init();
    });

})(jQuery);
