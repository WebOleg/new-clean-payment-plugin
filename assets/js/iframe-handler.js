/**
 * BNA Bridge iFrame Handler
 * Advanced iframe management and security handling
 * 
 * @package BNA_Payment_Bridge
 * @subpackage Assets
 */

(function($) {
    'use strict';

    // iFrame handler
    const BNAIframeHandler = {
        
        /**
         * Configuration
         */
        config: {
            iframeId: 'bna-bridge-iframe',
            containerId: 'bna-bridge-iframe-container',
            minHeight: 500,
            maxHeight: 800,
            resizeDebounce: 250,
            heartbeatInterval: 30000, // 30 seconds
            securityTimeout: 60000 // 1 minute
        },
        
        state: {
            iframe: null,
            isLoaded: false,
            lastHeartbeat: null,
            resizeObserver: null,
            securityTimer: null,
            allowedOrigins: []
        },

        /**
         * Initialize iframe handler
         */
        init: function() {
            console.log('[BNA iFrame] Handler initializing...');
            
            this.setupSecuritySettings();
            this.bindEvents();
            this.startHeartbeat();
            
            console.log('[BNA iFrame] Handler initialized');
        },

        /**
         * Setup security settings
         */
        setupSecuritySettings: function() {
            // Get allowed origins from localized script
            if (typeof bna_bridge_checkout !== 'undefined' && 
                bna_bridge_checkout.iframe && 
                bna_bridge_checkout.iframe.allowed_origins) {
                this.state.allowedOrigins = bna_bridge_checkout.iframe.allowed_origins;
            }
            
            console.log('[BNA iFrame] Allowed origins:', this.state.allowedOrigins);
        },

        /**
         * Bind iframe-related events
         */
        bindEvents: function() {
            const self = this;
            
            // Listen for iframe creation
            $(document).on('DOMNodeInserted', function(e) {
                if (e.target.id === self.config.iframeId) {
                    self.onIframeCreated(e.target);
                }
            });
            
            // Handle window resize
            let resizeTimeout;
            $(window).on('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    self.adjustIframeSize();
                }, self.config.resizeDebounce);
            });
            
            // Handle visibility change
            $(document).on('visibilitychange', function() {
                self.onVisibilityChange();
            });
            
            // Handle page unload
            $(window).on('beforeunload', function() {
                self.cleanup();
            });
        },

        /**
         * Handle iframe creation
         * 
         * @param {HTMLElement} iframe The iframe element
         */
        onIframeCreated: function(iframe) {
            console.log('[BNA iFrame] iFrame created, setting up handlers');
            
            this.state.iframe = iframe;
            this.setupIframeHandlers(iframe);
            this.setupResizeObserver(iframe);
            this.startSecurityTimer();
        },

        /**
         * Setup iframe event handlers
         * 
         * @param {HTMLElement} iframe The iframe element
         */
        setupIframeHandlers: function(iframe) {
            const self = this;
            
            // Handle iframe load
            iframe.onload = function() {
                console.log('[BNA iFrame] iFrame loaded successfully');
                self.onIframeLoaded();
            };
            
            // Handle iframe error
            iframe.onerror = function(error) {
                console.error('[BNA iFrame] iFrame load error:', error);
                self.onIframeError(error);
            };
            
            // Set initial size
            this.setIframeSize(iframe);
        },

        /**
         * Setup resize observer for iframe
         * 
         * @param {HTMLElement} iframe The iframe element
         */
        setupResizeObserver: function(iframe) {
            if (!window.ResizeObserver) {
                console.log('[BNA iFrame] ResizeObserver not supported, using fallback');
                return;
            }
            
            const self = this;
            
            this.state.resizeObserver = new ResizeObserver(function(entries) {
                self.handleResize(entries);
            });
            
            this.state.resizeObserver.observe(iframe);
        },

        /**
         * Handle iframe loaded event
         */
        onIframeLoaded: function() {
            this.state.isLoaded = true;
            this.state.lastHeartbeat = Date.now();
            
            // Send ready message to iframe
            this.sendMessageToIframe({
                type: 'checkout_ready',
                timestamp: Date.now()
            });
            
            // Setup iframe communication
            this.setupIframeCommunication();
            
            // Adjust initial size
            setTimeout(() => {
                this.adjustIframeSize();
            }, 1000);
        },

        /**
         * Handle iframe error
         * 
         * @param {Error} error The error object
         */
        onIframeError: function(error) {
            console.error('[BNA iFrame] Error loading iframe:', error);
            
            // Notify main checkout handler
            if (window.BNACheckout) {
                window.BNACheckout.showError('Failed to load payment form. Please try again.');
            }
            
            this.cleanup();
        },

        /**
         * Setup iframe communication
         */
        setupIframeCommunication: function() {
            const self = this;
            
            // Enhanced message listener for iframe-specific messages
            window.addEventListener('message', function(event) {
                self.handleEnhancedIframeMessage(event);
            });
        },

        /**
         * Handle enhanced iframe messages
         * 
         * @param {MessageEvent} event Message event
         */
        handleEnhancedIframeMessage: function(event) {
            // Security check
            if (!this.isOriginAllowed(event.origin)) {
                console.warn('[BNA iFrame] Message from unauthorized origin:', event.origin);
                return;
            }
            
            const data = event.data;
            
            if (!data || !data.type) {
                return;
            }
            
            // Update heartbeat for any message
            this.state.lastHeartbeat = Date.now();
            
            switch (data.type) {
                case 'iframe_resize':
                    this.handleIframeResize(data);
                    break;
                    
                case 'iframe_ready':
                    this.handleIframeReady(data);
                    break;
                    
                case 'iframe_error':
                    this.handleIframeSpecificError(data);
                    break;
                    
                case 'heartbeat':
                    this.handleHeartbeat(data);
                    break;
                    
                case 'navigation':
                    this.handleNavigation(data);
                    break;
                    
                default:
                    // Let main checkout handler process other messages
                    break;
            }
        },

        /**
         * Handle iframe resize request
         * 
         * @param {object} data Resize data
         */
        handleIframeResize: function(data) {
            if (data.height && typeof data.height === 'number') {
                const newHeight = Math.min(Math.max(data.height, this.config.minHeight), this.config.maxHeight);
                this.setIframeHeight(newHeight);
                console.log('[BNA iFrame] Resized to height:', newHeight);
            }
        },

        /**
         * Handle iframe ready message
         * 
         * @param {object} data Ready data
         */
        handleIframeReady: function(data) {
            console.log('[BNA iFrame] iFrame reported ready:', data);
            
            // Send checkout context to iframe
            this.sendCheckoutContext();
        },

        /**
         * Handle iframe-specific errors
         * 
         * @param {object} data Error data
         */
        handleIframeSpecificError: function(data) {
            console.error('[BNA iFrame] iFrame reported error:', data);
            
            if (window.BNACheckout) {
                window.BNACheckout.showError(data.message || 'Payment form encountered an error');
            }
        },

        /**
         * Handle heartbeat from iframe
         * 
         * @param {object} data Heartbeat data
         */
        handleHeartbeat: function(data) {
            console.log('[BNA iFrame] Heartbeat received:', data.timestamp);
            
            // Respond with heartbeat
            this.sendMessageToIframe({
                type: 'heartbeat_response',
                timestamp: Date.now()
            });
        },

        /**
         * Handle navigation within iframe
         * 
         * @param {object} data Navigation data
         */
        handleNavigation: function(data) {
            console.log('[BNA iFrame] Navigation event:', data);
            
            // Could be used for tracking or analytics
            if (data.step) {
                this.trackPaymentStep(data.step);
            }
        },

        /**
         * Send message to iframe
         * 
         * @param {object} message Message object
         */
        sendMessageToIframe: function(message) {
            if (!this.state.iframe || !this.state.isLoaded) {
                console.warn('[BNA iFrame] Cannot send message - iframe not ready');
                return;
            }
            
            try {
                this.state.iframe.contentWindow.postMessage(message, '*');
            } catch (error) {
                console.error('[BNA iFrame] Error sending message to iframe:', error);
            }
        },

        /**
         * Send checkout context to iframe
         */
        sendCheckoutContext: function() {
            const context = {
                type: 'checkout_context',
                currency: this.getCheckoutCurrency(),
                locale: this.getCheckoutLocale(),
                theme: this.getThemeInfo(),
                timestamp: Date.now()
            };
            
            this.sendMessageToIframe(context);
        },

        /**
         * Set iframe size
         * 
         * @param {HTMLElement} iframe The iframe element
         */
        setIframeSize: function(iframe) {
            iframe.style.width = '100%';
            iframe.style.height = this.config.minHeight + 'px';
            iframe.style.minHeight = this.config.minHeight + 'px';
            iframe.style.border = 'none';
        },

        /**
         * Set iframe height
         * 
         * @param {number} height Height in pixels
         */
        setIframeHeight: function(height) {
            if (this.state.iframe) {
                this.state.iframe.style.height = height + 'px';
            }
        },

        /**
         * Adjust iframe size based on content
         */
        adjustIframeSize: function() {
            if (!this.state.iframe) return;
            
            const container = document.getElementById(this.config.containerId);
            if (!container) return;
            
            const containerWidth = container.clientWidth;
            
            // Responsive adjustments
            if (containerWidth < 480) {
                // Mobile
                this.setIframeHeight(this.config.minHeight + 50);
            } else if (containerWidth < 768) {
                // Tablet
                this.setIframeHeight(this.config.minHeight + 25);
            } else {
                // Desktop
                this.setIframeHeight(this.config.minHeight);
            }
        },

        /**
         * Handle resize observer events
         * 
         * @param {array} entries Resize observer entries
         */
        handleResize: function(entries) {
            console.log('[BNA iFrame] Resize observed:', entries);
            // Could implement additional resize logic here
        },

        /**
         * Start heartbeat monitoring
         */
        startHeartbeat: function() {
            const self = this;
            
            setInterval(function() {
                self.checkHeartbeat();
            }, this.config.heartbeatInterval);
        },

        /**
         * Check heartbeat status
         */
        checkHeartbeat: function() {
            if (!this.state.isLoaded || !this.state.lastHeartbeat) {
                return;
            }
            
            const timeSinceLastHeartbeat = Date.now() - this.state.lastHeartbeat;
            
            if (timeSinceLastHeartbeat > this.config.heartbeatInterval * 2) {
                console.warn('[BNA iFrame] No heartbeat received, iframe may be unresponsive');
                
                // Could implement recovery logic here
                this.handleUnresponsiveIframe();
            }
        },

        /**
         * Handle unresponsive iframe
         */
        handleUnresponsiveIframe: function() {
            console.warn('[BNA iFrame] iFrame appears unresponsive, attempting recovery');
            
            if (window.BNACheckout) {
                window.BNACheckout.showError('Payment form is not responding. Please refresh and try again.');
            }
        },

        /**
         * Start security timer
         */
        startSecurityTimer: function() {
            const self = this;
            
            this.state.securityTimer = setTimeout(function() {
                self.handleSecurityTimeout();
            }, this.config.securityTimeout);
        },

        /**
         * Handle security timeout
         */
        handleSecurityTimeout: function() {
            console.warn('[BNA iFrame] Security timeout reached');
            
            if (window.BNACheckout) {
                window.BNACheckout.showError('Session expired. Please refresh and try again.');
            }
            
            this.cleanup();
        },

        /**
         * Handle visibility change
         */
        onVisibilityChange: function() {
            if (document.hidden) {
                console.log('[BNA iFrame] Page hidden');
            } else {
                console.log('[BNA iFrame] Page visible');
                
                // Send visibility message to iframe
                this.sendMessageToIframe({
                    type: 'visibility_change',
                    visible: true,
                    timestamp: Date.now()
                });
            }
        },

        /**
         * Check if origin is allowed
         * 
         * @param {string} origin Message origin
         * @return {boolean} True if origin is allowed
         */
        isOriginAllowed: function(origin) {
            return this.state.allowedOrigins.includes(origin);
        },

        /**
         * Get checkout currency
         * 
         * @return {string} Currency code
         */
        getCheckoutCurrency: function() {
            // Try to get from WooCommerce
            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.currency) {
                return wc_checkout_params.currency;
            }
            
            return 'CAD'; // Default
        },

        /**
         * Get checkout locale
         * 
         * @return {string} Locale code
         */
        getCheckoutLocale: function() {
            return document.documentElement.lang || 'en-CA';
        },

        /**
         * Get theme information
         * 
         * @return {object} Theme info
         */
        getThemeInfo: function() {
            return {
                name: 'woocommerce',
                version: '1.0',
                colors: this.extractThemeColors()
            };
        },

        /**
         * Extract theme colors for iframe styling
         * 
         * @return {object} Color scheme
         */
        extractThemeColors: function() {
            const computedStyle = getComputedStyle(document.documentElement);
            
            return {
                primary: computedStyle.getPropertyValue('--wc-color-primary') || '#0073aa',
                secondary: computedStyle.getPropertyValue('--wc-color-secondary') || '#666',
                success: computedStyle.getPropertyValue('--wc-color-success') || '#46b450',
                error: computedStyle.getPropertyValue('--wc-color-error') || '#dc3232'
            };
        },

        /**
         * Track payment step for analytics
         * 
         * @param {string} step Payment step
         */
        trackPaymentStep: function(step) {
            console.log('[BNA iFrame] Payment step:', step);
            
            // Could integrate with Google Analytics or other tracking
            if (typeof gtag === 'function') {
                gtag('event', 'payment_step', {
                    'custom_map': {'step': step},
                    'payment_method': 'bna_bridge'
                });
            }
        },

        /**
         * Cleanup iframe handler
         */
        cleanup: function() {
            console.log('[BNA iFrame] Cleaning up iframe handler');
            
            // Clear timers
            if (this.state.securityTimer) {
                clearTimeout(this.state.securityTimer);
            }
            
            // Disconnect resize observer
            if (this.state.resizeObserver) {
                this.state.resizeObserver.disconnect();
            }
            
            // Reset state
            this.state.iframe = null;
            this.state.isLoaded = false;
            this.state.lastHeartbeat = null;
        }
    };

    // Initialize iframe handler when document ready
    $(document).ready(function() {
        if (typeof bna_bridge_checkout !== 'undefined') {
            BNAIframeHandler.init();
            
            // Make available globally for debugging
            window.BNAIframeHandler = BNAIframeHandler;
        }
    });

})(jQuery);
