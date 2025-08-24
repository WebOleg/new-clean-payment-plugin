/**
 * BNA Bridge Admin JavaScript
 * Handles admin interface functionality and connection testing
 * 
 * @package BNA_Payment_Bridge
 * @subpackage Assets
 */

(function($) {
    'use strict';

    // Admin handler
    const BNAAdmin = {
        
        /**
         * Configuration
         */
        config: {
            testButtonId: '#bna-bridge-test-connection',
            resultSpanId: '#bna-test-result',
            statusIndicatorId: '#bna-connection-indicator',
            debugInfoId: '#bna-bridge-debug-info',
            debugOutputId: '#bna-debug-output',
            accessKeyField: '#woocommerce_bna_bridge_access_key',
            secretKeyField: '#woocommerce_bna_bridge_secret_key',
            testModeField: '#woocommerce_bna_bridge_testmode'
        },
        
        state: {
            testing: false,
            lastTestResult: null
        },

        /**
         * Initialize admin functionality
         */
        init: function() {
            console.log('[BNA Admin] Initializing...');
            
            this.bindEvents();
            this.setupFieldValidation();
            
            console.log('[BNA Admin] Initialized successfully');
        },

        /**
         * Bind admin events
         */
        bindEvents: function() {
            const self = this;
            
            // Test connection button
            $(this.config.testButtonId).on('click', function(e) {
                e.preventDefault();
                self.testConnection();
            });
            
            // Field changes - clear previous results
            $(this.config.accessKeyField + ', ' + this.config.secretKeyField).on('input', function() {
                self.clearTestResults();
            });
            
            // Test mode toggle
            $(this.config.testModeField).on('change', function() {
                self.onTestModeChange();
                self.clearTestResults();
            });
            
            // Show/hide debug info
            $(document).on('click', '[data-toggle="bna-debug"]', function(e) {
                e.preventDefault();
                self.toggleDebugInfo();
            });
        },

        /**
         * Setup field validation
         */
        setupFieldValidation: function() {
            const self = this;
            
            // Real-time validation for access key
            $(this.config.accessKeyField).on('blur', function() {
                self.validateAccessKey($(this).val());
            });
            
            // Real-time validation for secret key
            $(this.config.secretKeyField).on('blur', function() {
                self.validateSecretKey($(this).val());
            });
        },

        /**
         * Test API connection
         */
        testConnection: function() {
            if (this.state.testing) {
                console.log('[BNA Admin] Test already in progress');
                return;
            }
            
            const credentials = this.getCredentials();
            
            if (!this.validateCredentials(credentials)) {
                return;
            }
            
            this.startTest();
            
            const testData = {
                action: 'bna_bridge_test_connection',
                nonce: bna_bridge_admin.nonce,
                access_key: credentials.accessKey,
                secret_key: credentials.secretKey,
                testmode: credentials.testMode ? 'yes' : 'no'
            };
            
            $.ajax({
                url: bna_bridge_admin.ajax_url,
                type: 'POST',
                data: testData,
                timeout: 30000, // 30 seconds
                success: (response) => {
                    this.handleTestSuccess(response);
                },
                error: (xhr, status, error) => {
                    this.handleTestError(xhr, status, error);
                }
            });
        },

        /**
         * Get credentials from form
         * 
         * @return {object} Credentials object
         */
        getCredentials: function() {
            return {
                accessKey: $(this.config.accessKeyField).val().trim(),
                secretKey: $(this.config.secretKeyField).val().trim(),
                testMode: $(this.config.testModeField).is(':checked')
            };
        },

        /**
         * Validate credentials before testing
         * 
         * @param {object} credentials Credentials to validate
         * @return {boolean} True if valid
         */
        validateCredentials: function(credentials) {
            if (!credentials.accessKey) {
                this.showError(bna_bridge_admin.messages.missing_credentials);
                $(this.config.accessKeyField).focus().addClass('error');
                return false;
            }
            
            if (!credentials.secretKey) {
                this.showError(bna_bridge_admin.messages.missing_credentials);
                $(this.config.secretKeyField).focus().addClass('error');
                return false;
            }
            
            // Clear error states
            $(this.config.accessKeyField + ', ' + this.config.secretKeyField).removeClass('error');
            
            return true;
        },

        /**
         * Start connection test
         */
        startTest: function() {
            this.state.testing = true;
            
            $(this.config.testButtonId)
                .prop('disabled', true)
                .text(bna_bridge_admin.messages.testing_connection);
            
            $(this.config.resultSpanId)
                .removeClass('success error')
                .addClass('loading')
                .html('<span class="bna-admin-loading"></span>');
            
            $(this.config.statusIndicatorId)
                .removeClass('success error')
                .addClass('testing')
                .text('Testing...');
            
            this.clearDebugOutput();
        },

        /**
         * Handle successful test response
         * 
         * @param {object} response AJAX response
         */
        handleTestSuccess: function(response) {
            console.log('[BNA Admin] Test response:', response);
            
            this.state.testing = false;
            
            if (response.success) {
                this.showSuccess(response.data);
            } else {
                this.showError(response.data);
            }
            
            this.resetTestButton();
            this.updateDebugInfo(response);
        },

        /**
         * Handle test error
         * 
         * @param {object} xhr XHR object
         * @param {string} status Error status
         * @param {string} error Error message
         */
        handleTestError: function(xhr, status, error) {
            console.error('[BNA Admin] Test error:', status, error);
            
            this.state.testing = false;
            
            let errorMessage = 'Connection test failed: ';
            
            if (status === 'timeout') {
                errorMessage += 'Request timed out. Please check your server connection.';
            } else if (xhr.status === 0) {
                errorMessage += 'Network error. Please check your internet connection.';
            } else {
                errorMessage += `${status} (${xhr.status})`;
            }
            
            this.showError({
                message: errorMessage,
                code: xhr.status
            });
            
            this.resetTestButton();
            this.updateDebugInfo({
                error: true,
                status: status,
                xhr_status: xhr.status,
                error_message: error
            });
        },

        /**
         * Show success result
         * 
         * @param {object} data Success data
         */
        showSuccess: function(data) {
            $(this.config.resultSpanId)
                .removeClass('loading error')
                .addClass('success')
                .text('✅ ' + (data.message || bna_bridge_admin.messages.connection_success));
            
            $(this.config.statusIndicatorId)
                .removeClass('testing error')
                .addClass('success')
                .text('Connected ✅');
            
            this.state.lastTestResult = {
                success: true,
                data: data,
                timestamp: new Date().toISOString()
            };
        },

        /**
         * Show error result
         * 
         * @param {object} data Error data
         */
        showError: function(data) {
            const message = data.message || 'Connection test failed';
            
            $(this.config.resultSpanId)
                .removeClass('loading success')
                .addClass('error')
                .text('❌ ' + message);
            
            $(this.config.statusIndicatorId)
                .removeClass('testing success')
                .addClass('error')
                .text('Connection Failed ❌');
            
            this.state.lastTestResult = {
                success: false,
                data: data,
                timestamp: new Date().toISOString()
            };
        },

        /**
         * Reset test button to original state
         */
        resetTestButton: function() {
            $(this.config.testButtonId)
                .prop('disabled', false)
                .text('Test Connection');
        },

        /**
         * Clear test results
         */
        clearTestResults: function() {
            $(this.config.resultSpanId)
                .removeClass('success error loading')
                .empty();
            
            $(this.config.statusIndicatorId)
                .removeClass('success error testing')
                .text('Click "Test Connection" to verify');
        },

        /**
         * Handle test mode change
         */
        onTestModeChange: function() {
            const isTestMode = $(this.config.testModeField).is(':checked');
            const endpoint = isTestMode ? 'Staging' : 'Production';
            
            console.log('[BNA Admin] Test mode changed to:', endpoint);
            
            // Could show different UI based on test mode
            if (isTestMode) {
                this.showNotice('Test mode enabled - using staging environment', 'warning');
            } else {
                this.showNotice('Production mode enabled - using live environment', 'info');
            }
        },

        /**
         * Validate access key format
         * 
         * @param {string} accessKey Access key to validate
         */
        validateAccessKey: function(accessKey) {
            const field = $(this.config.accessKeyField);
            
            if (!accessKey) {
                field.removeClass('success').addClass('error');
                return false;
            }
            
            // Basic validation - adjust based on BNA key format
            if (accessKey.length < 10) {
                field.removeClass('success').addClass('error');
                this.showFieldMessage(field, 'Access key appears to be too short', 'error');
                return false;
            }
            
            field.removeClass('error').addClass('success');
            this.clearFieldMessage(field);
            return true;
        },

        /**
         * Validate secret key format
         * 
         * @param {string} secretKey Secret key to validate
         */
        validateSecretKey: function(secretKey) {
            const field = $(this.config.secretKeyField);
            
            if (!secretKey) {
                field.removeClass('success').addClass('error');
                return false;
            }
            
            // Basic validation - adjust based on BNA key format
            if (secretKey.length < 10) {
                field.removeClass('success').addClass('error');
                this.showFieldMessage(field, 'Secret key appears to be too short', 'error');
                return false;
            }
            
            field.removeClass('error').addClass('success');
            this.clearFieldMessage(field);
            return true;
        },

        /**
         * Show field validation message
         * 
         * @param {jQuery} field Field element
         * @param {string} message Message to show
         * @param {string} type Message type (error/success)
         */
        showFieldMessage: function(field, message, type) {
            this.clearFieldMessage(field);
            
            const messageElement = $('<div class="field-validation-message ' + type + '">' + message + '</div>');
            field.after(messageElement);
        },

        /**
         * Clear field validation message
         * 
         * @param {jQuery} field Field element
         */
        clearFieldMessage: function(field) {
            field.siblings('.field-validation-message').remove();
        },

        /**
         * Update debug information
         * 
         * @param {object} data Debug data to display
         */
        updateDebugInfo: function(data) {
            const debugOutput = {
                timestamp: new Date().toISOString(),
                test_result: data,
                environment: $(this.config.testModeField).is(':checked') ? 'staging' : 'production',
                browser: navigator.userAgent,
                wp_version: window.wp ? window.wp.version : 'unknown'
            };
            
            $(this.config.debugOutputId).val(JSON.stringify(debugOutput, null, 2));
            
            // Auto-show debug info on error
            if (!data.success) {
                this.showDebugInfo();
            }
        },

        /**
         * Clear debug output
         */
        clearDebugOutput: function() {
            $(this.config.debugOutputId).val('');
        },

        /**
         * Show debug information panel
         */
        showDebugInfo: function() {
            $(this.config.debugInfoId).show();
        },

        /**
         * Hide debug information panel
         */
        hideDebugInfo: function() {
            $(this.config.debugInfoId).hide();
        },

        /**
         * Toggle debug information panel
         */
        toggleDebugInfo: function() {
            $(this.config.debugInfoId).toggle();
        },

        /**
         * Show admin notice
         * 
         * @param {string} message Notice message
         * @param {string} type Notice type
         */
        showNotice: function(message, type = 'info') {
            const noticeHtml = `<div class="bna-admin-notice ${type}"><p>${message}</p></div>`;
            
            // Remove existing notices
            $('.bna-admin-notice').remove();
            
            // Add new notice
            $(this.config.testButtonId).closest('p').after(noticeHtml);
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                $('.bna-admin-notice').fadeOut();
            }, 5000);
        }
    };

    // Initialize when document ready
    $(document).ready(function() {
        // Only initialize on BNA settings page
        if (typeof bna_bridge_admin !== 'undefined') {
            BNAAdmin.init();
            
            // Make available globally for debugging
            window.BNAAdmin = BNAAdmin;
        }
    });

})(jQuery);
