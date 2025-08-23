jQuery(function ($) {
    let reloadTimer = null;
    let isLoading = false;

    // Debug: Check if script is loading
    console.log('🔍 BNA Script started');
    console.log('🔍 jQuery available:', !!$);
    console.log('🔍 BNA data available:', !!window.bna_iframe_data);

    const inputFields = [
        '#billing_email',
        '#billing_first_name',
        '#billing_last_name',
        '#billing_postcode',
        '#billing_phone',
        '#billing_address_1',
        '#billing_city'
    ];

    const changeFields = [
        '#billing_country',
        '#billing_state'
    ];

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function debugFormFields() {
        console.log('🔍 DEBUG: Checking form fields...');
        inputFields.forEach(selector => {
            const $el = $(selector);
            const value = $el.val();
            console.log(`🔍 ${selector}: exists=${$el.length > 0}, value="${value}"`);
        });
    }

    function isValidField(field, value) {
        switch (field) {
            case '#billing_email':
                return value === '' || emailRegex.test(value);
            case '#billing_phone':
                return value === '' || /^\+?\d{4,}$/.test(value);
            default:
                return true;
        }
    }

    function validateForm() {
        console.log('🔍 DEBUG: Validating form...');
        let isValid = true;

        inputFields.forEach(selector => {
            const $el = $(selector);
            if ($el.length) {
                const value = $el.val();
                const valid = isValidField(selector, value);
                console.log(`🔍 ${selector}: valid=${valid}, value="${value}"`);

                if (!valid) {
                    $el.addClass('bna-invalid');
                    isValid = false;
                } else {
                    $el.removeClass('bna-invalid');
                }
            }
        });

        console.log('🔍 Form validation result:', isValid);
        return isValid;
    }

    function reloadBnaIframe() {
        console.log('🔍 DEBUG: reloadBnaIframe called');

        // Prevent multiple simultaneous requests
        if (isLoading) {
            console.log('🔍 Already loading, skipping request...');
            return;
        }

        // Debug form fields first
        debugFormFields();

        if (!validateForm()) {
            console.warn('🔍 Validation failed, iframe not reloaded.');
            return;
        }

        const email = $('#billing_email').val();
        const firstName = $('#billing_first_name').val();
        const lastName = $('#billing_last_name').val();

        console.log('🔍 Required fields check:');
        console.log('🔍 Email:', email);
        console.log('🔍 First Name:', firstName);
        console.log('🔍 Last Name:', lastName);

        if (!email || !firstName || !lastName) {
            console.warn('🔍 Required fields missing, showing message...');
            $('#bna-iframe-wrapper').html(
                '<div style="text-align: center; padding: 30px; color: #888; background: #f8f9fa; border-radius: 8px;">' +
                '<h4 style="margin-bottom: 10px;">📧 Заповніть дані для оплати</h4>' +
                '<p>Введіть email адресу та дані для завантаження платіжної форми</p>' +
                '<div style="margin-top: 15px; font-size: 12px; color: #999;">' +
                'Debug: email=' + (email ? 'OK' : 'MISSING') +
                ', firstName=' + (firstName ? 'OK' : 'MISSING') +
                ', lastName=' + (lastName ? 'OK' : 'MISSING') +
                '</div>' +
                '</div>'
            );
            return;
        }

        // Check if we have AJAX data
        if (!window.bna_iframe_data || !window.bna_iframe_data.ajax_url || !window.bna_iframe_data.nonce) {
            console.error('🔍 BNA iframe data missing!');
            $('#bna-iframe-wrapper').html(
                '<div style="text-align: center; padding: 30px; color: #dc3545; background: #f8d7da; border-radius: 8px;">' +
                '<h4>❌ Configuration Error</h4>' +
                '<p>AJAX data is not available. Please check plugin configuration.</p>' +
                '</div>'
            );
            return;
        }

        isLoading = true;
        console.log('🔍 Starting AJAX request...');

        // Show loading with animation
        $('#bna-iframe-wrapper').html(
            '<div style="text-align: center; padding: 30px; color: #666; background: #f8f9fa; border-radius: 8px;">' +
            '<div class="bna-spinner" style="display: inline-block; width: 40px; height: 40px; border: 3px solid #f3f3f3; border-top: 3px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 15px;"></div>' +
            '<h4 style="margin-bottom: 10px;">🔄 Завантаження платіжної форми...</h4>' +
            '<p style="margin: 0;">Підготовка безпечного з\'єднання з BNA...</p>' +
            '<div style="margin-top: 10px; font-size: 11px; color: #999;">Sending AJAX request...</div>' +
            '</div>'
        );

        // Get billing data
        var billingData = {
            action: 'load_bna_iframe',
            nonce: window.bna_iframe_data.nonce,
            billing_email: email,
            billing_first_name: firstName,
            billing_last_name: lastName,
            billing_phone: $('#billing_phone').val() || '',
            billing_city: $('#billing_city').val() || '',
            billing_country: $('#billing_country').val() || 'CA',
            billing_state: $('#billing_state').val() || '',
            billing_postcode: $('#billing_postcode').val() || '',
            billing_address_1: $('#billing_address_1').val() || ''
        };

        console.log('🔍 Sending billing data:', billingData);

        // AJAX request to load iframe
        $.post(window.bna_iframe_data.ajax_url, billingData)
            .done(function(response) {
                console.log('🔍 AJAX response received, length:', response.length);
                console.log('🔍 Response preview:', response.substring(0, 200) + '...');

                // Check if response contains BNA error messages or user-friendly messages
                if (response.includes('Вітаємо назад!') ||
                    response.includes('Customer Management Issue') ||
                    response.includes('409') ||
                    response.includes('already exists')) {

                    $('#bna-iframe-wrapper').html(response);
                    console.log('🔍 Customer conflict message displayed');

                } else if (response.includes('<iframe')) {
                    $('#bna-iframe-wrapper').html(response);
                    console.log('🔍 BNA iframe loaded successfully');

                    // Setup message listener for iframe events
                    setupIframeMessageListener();
                    sessionStorage.setItem('bna_last_successful_email', email);

                } else if (response.includes('❌') || response.includes('Error')) {
                    $('#bna-iframe-wrapper').html(response);
                    console.log('🔍 BNA API error displayed');

                } else {
                    $('#bna-iframe-wrapper').html(response);
                    console.log('🔍 Response displayed (unknown format)');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('🔍 AJAX request failed:', {xhr, status, error});
                $('#bna-iframe-wrapper').html(
                    '<div style="color:red; padding: 20px; text-align: center; background: #ffe6e6; border-radius: 8px;">' +
                    '<h4 style="margin-bottom: 15px;">❌ Помилка з\'єднання</h4>' +
                    '<p style="margin-bottom: 15px;">Не вдалося завантажити платіжну форму.</p>' +
                    '<div style="margin-bottom: 15px;"><small style="color: #666;">Error: ' + error + '</small></div>' +
                    '<div style="margin-bottom: 15px;"><small style="color: #666;">Status: ' + status + '</small></div>' +
                    '<div style="margin-bottom: 15px;"><small style="color: #666;">URL: ' + window.bna_iframe_data.ajax_url + '</small></div>' +
                    '<button onclick="location.reload()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">🔄 Перезавантажити</button>' +
                    '</div>'
                );
            })
            .always(function() {
                isLoading = false;
                console.log('🔍 AJAX request completed, loading state reset');
            });
    }

    // Setup iframe message listener for payment events
    function setupIframeMessageListener() {
        console.log('🔍 Setting up iframe message listener');
        window.addEventListener('message', function(event) {
            const allowedOrigins = [
                'https://api.bnasmartpayment.com',
                'https://stage-api-service.bnasmartpayment.com'
            ];

            console.log('🔍 Message received from:', event.origin);

            if (!allowedOrigins.includes(event.origin)) {
                console.log('🔍 Message from unknown origin ignored:', event.origin);
                return;
            }

            const data = event.data;
            console.log('🔍 BNA iframe message:', data);

            if (data && data.type) {
                switch(data.type) {
                    case 'payment_success':
                        console.log('🔍 Payment succeeded:', data.data);
                        showPaymentSuccess(data.data);
                        break;

                    case 'payment_failed':
                    case 'payment_error':
                        console.log('🔍 Payment failed/error:', data.message);
                        showPaymentError(data.message);
                        break;

                    default:
                        console.log('🔍 Unknown message type:', data.type);
                }
            }
        });
    }

    // Show payment success
    function showPaymentSuccess(paymentData) {
        $('#bna-iframe-wrapper').html(
            '<div style="text-align: center; padding: 30px; background: #d4edda; border-radius: 8px; border: 1px solid #c3e6cb;">' +
            '<div style="font-size: 48px; margin-bottom: 15px;">✅</div>' +
            '<h3 style="color: #155724; margin-bottom: 15px;">Оплата успішна!</h3>' +
            '<p style="color: #155724;">Ваш платіж прийнято та обробляється.</p>' +
            '</div>'
        );
    }

    // Show payment error
    function showPaymentError(message) {
        $('#bna-iframe-wrapper').html(
            '<div style="text-align: center; padding: 30px; background: #f8d7da; border-radius: 8px; border: 1px solid #f5c6cb;">' +
            '<div style="font-size: 48px; margin-bottom: 15px;">❌</div>' +
            '<h3 style="color: #721c24; margin-bottom: 15px;">Помилка оплати</h3>' +
            '<p style="color: #721c24; margin-bottom: 15px;">' + (message || 'Сталася помилка під час обробки платежу') + '</p>' +
            '<button onclick="location.reload()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Спробувати ще раз</button>' +
            '</div>'
        );
    }

    // Debounced reload function
    function debounceReload() {
        console.log('🔍 Debounce reload triggered');
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(function() {
            console.log('🔍 Debounce timer fired, calling reloadBnaIframe');
            reloadBnaIframe();
        }, 1000);
    }

    // Email change handler to clear 409 errors
    $(document).on('blur change', '#billing_email', function() {
        console.log('🔍 Email field changed');
        const currentEmail = $(this).val();
        const storedEmail = sessionStorage.getItem('bna_last_email');

        if (currentEmail !== storedEmail && currentEmail.length > 0) {
            sessionStorage.setItem('bna_last_email', currentEmail);

            const currentContent = $('#bna-iframe-wrapper').html();
            if (currentContent && (
                currentContent.includes('Customer Management Issue') ||
                currentContent.includes('already exists') ||
                currentContent.includes('Вітаємо назад!')
            )) {
                console.log('🔍 Email changed, clearing customer conflict...');
                $('#bna-iframe-wrapper').html(
                    '<div style="text-align: center; padding: 20px; color: #666;">🔄 Оновлення форми...</div>'
                );

                setTimeout(() => {
                    debounceReload();
                }, 500);
            }
        }
    });

    // Event listeners for input fields
    inputFields.forEach(selector => {
        $(document).on('input', selector, function() {
            console.log('🔍 Input field changed:', selector);
            debounceReload();
        });
    });

    // Event listeners for select/dropdown fields
    changeFields.forEach(selector => {
        $(document).on('change', selector, function() {
            console.log('🔍 Select field changed:', selector);
            debounceReload();
        });
    });

    // Manual trigger button for debugging
    function addDebugButton() {
        if ($('#bna-debug-button').length === 0) {
            $('<button id="bna-debug-button" style="position: fixed; top: 10px; right: 10px; z-index: 9999; padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">🔍 Load BNA</button>')
                .appendTo('body')
                .on('click', function() {
                    console.log('🔍 Manual trigger clicked');
                    reloadBnaIframe();
                });
        }
    }

    // Initial load
    $(document).ready(function() {
        console.log('🔍 Document ready, initializing...');

        // Add debug button in development
        addDebugButton();

        // Add CSS animations
        if (!$('#bna-iframe-styles').length) {
            $('<style id="bna-iframe-styles">').text(`
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .bna-invalid {
                    border-color: #dc3545 !important;
                    box-shadow: 0 0 5px rgba(220, 53, 69, 0.3) !important;
                }
                
                #bna-iframe-wrapper {
                    min-height: 200px;
                    transition: all 0.3s ease;
                }
                
                #bna-iframe-wrapper iframe {
                    transition: opacity 0.3s ease;
                    width: 100%;
                    min-height: 600px;
                    border: none;
                    display: block;
                }
            `).appendTo('head');
        }

        // Debug initial state
        setTimeout(() => {
            console.log('🔍 Checking initial state after 2 seconds...');
            debugFormFields();

            const email = $('#billing_email').val();
            console.log('🔍 Initial email check:', email);

            if (email && emailRegex.test(email) && $('#billing_first_name').val() && $('#billing_last_name').val()) {
                console.log('🔍 All required fields filled, starting initial load...');
                debounceReload();
            } else {
                console.log('🔍 Required fields missing, showing instruction message...');
                $('#bna-iframe-wrapper').html(
                    '<div style="text-align: center; padding: 30px; color: #888; background: #f8f9fa; border-radius: 8px;">' +
                    '<h4 style="margin-bottom: 10px;">📧 Заповніть дані для оплати</h4>' +
                    '<p>Введіть email адресу та дані для завантаження платіжної форми</p>' +
                    '<div style="margin-top: 15px; font-size: 12px; color: #999;">' +
                    'Debug info: Check console (F12) for detailed logs' +
                    '</div>' +
                    '</div>'
                );
            }
        }, 2000);
    });

    // Final debug log
    console.log('🔍 BNA Dynamic Iframe Script loaded successfully');
    if (window.bna_iframe_data) {
        console.log('🔍 AJAX URL:', window.bna_iframe_data.ajax_url);
        console.log('🔍 Nonce available:', !!window.bna_iframe_data.nonce);
    } else {
        console.error('🔍 BNA iframe data not found!');
    }
});