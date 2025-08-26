<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Portal Test Admin Page - Complete Version
 */
class BNA_Portal_Test_Page {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_test_menu'));
        add_action('wp_ajax_bna_run_portal_test', array($this, 'ajax_run_test'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_test_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Portal Test',
            'BNA Portal Test',
            'manage_options',
            'bna-portal-test',
            array($this, 'render_test_page')
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_bna-portal-test') {
            return;
        }
        wp_enqueue_script('jquery');
    }

    public function render_test_page() {
        $gateway = new BNA_Gateway();
        $iframe_id = $gateway->get_option('iframe_id');
        $mode = $gateway->get_option('mode', 'development');
        ?>
        <div class="wrap">
            <h1>BNA Portal Requirements Tester</h1>
            
            <div class="bna-test-info" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin: 20px 0;">
                <h3>Current Configuration</h3>
                <p><strong>Mode:</strong> <?php echo esc_html($mode); ?></p>
                <p><strong>iFrame ID:</strong> <?php echo esc_html($iframe_id); ?></p>
                <p><strong>Status:</strong> <?php echo empty($iframe_id) ? '<span style="color: red;">Not Configured</span>' : '<span style="color: green;">Configured</span>'; ?></p>
            </div>

            <?php if (empty($iframe_id)): ?>
                <div class="notice notice-warning">
                    <p><strong>Warning:</strong> iFrame ID is not configured. Please configure your BNA settings first.</p>
                </div>
            <?php else: ?>
                <p>Test what your BNA Portal expects for different field configurations.</p>
                
                <button id="run-portal-test" class="button button-primary">Run Portal Test</button>
                <button id="clear-results" class="button button-secondary" style="margin-left: 10px;">Clear Results</button>
                
                <div id="test-progress" style="margin-top: 15px; display: none;">
                    <p><strong>Testing in progress...</strong></p>
                    <div style="width: 100%; background: #f1f1f1; border-radius: 4px;">
                        <div id="progress-bar" style="width: 0%; height: 20px; background: #0073aa; border-radius: 4px; transition: width 0.3s;"></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div id="test-results" style="margin-top: 20px;"></div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#run-portal-test').on('click', function() {
                const button = $(this);
                const originalText = button.text();
                
                button.prop('disabled', true).text('Testing...');
                $('#test-progress').show();
                $('#test-results').empty();
                
                // Simulate progress
                let progress = 0;
                const progressInterval = setInterval(function() {
                    progress += 10;
                    $('#progress-bar').css('width', progress + '%');
                    if (progress >= 90) {
                        clearInterval(progressInterval);
                    }
                }, 500);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bna_run_portal_test',
                        _ajax_nonce: '<?php echo wp_create_nonce("bna_portal_test"); ?>'
                    },
                    success: function(response) {
                        clearInterval(progressInterval);
                        $('#progress-bar').css('width', '100%');
                        
                        setTimeout(function() {
                            $('#test-progress').hide();
                            
                            if (response.success) {
                                $('#test-results').html(
                                    '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px;">' +
                                    '<h3>Test Results</h3>' +
                                    '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px;">' +
                                    '<strong>Recommendation:</strong> ' + response.data.recommendation +
                                    '</div>' +
                                    '<h4>Detailed Report:</h4>' +
                                    '<pre style="background: #f1f1f1; padding: 15px; border: 1px solid #ccc; white-space: pre-wrap; max-height: 500px; overflow-y: auto;">' + 
                                    response.data.report + 
                                    '</pre>' +
                                    '</div>'
                                );
                            } else {
                                $('#test-results').html(
                                    '<div class="notice notice-error" style="padding: 10px;">' +
                                    '<p><strong>Test Failed:</strong> ' + (response.data || 'Unknown error') + '</p>' +
                                    '</div>'
                                );
                            }
                        }, 500);
                    },
                    error: function(xhr, status, error) {
                        clearInterval(progressInterval);
                        $('#test-progress').hide();
                        $('#test-results').html(
                            '<div class="notice notice-error" style="padding: 10px;">' +
                            '<p><strong>Request Failed:</strong> ' + error + '</p>' +
                            '</div>'
                        );
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });

            $('#clear-results').on('click', function() {
                $('#test-results').empty();
                $('#test-progress').hide();
                $('#progress-bar').css('width', '0%');
            });
        });
        </script>

        <style>
        .bna-test-info {
            border-radius: 4px;
        }
        .bna-test-info h3 {
            margin-top: 0;
        }
        #test-results pre {
            font-size: 12px;
            line-height: 1.4;
        }
        </style>
        <?php
    }

    public function ajax_run_test() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'bna_portal_test')) {
            wp_send_json_error('Security check failed');
        }

        try {
            $gateway = new BNA_Gateway();
            $config = array(
                'mode' => $gateway->get_option('mode', 'development'),
                'iframe_id' => $gateway->get_option('iframe_id'),
                'access_key' => $gateway->get_option('access_key'),
                'secret_key' => $gateway->get_option('secret_key')
            );

            // Validate configuration
            if (empty($config['iframe_id']) || empty($config['access_key']) || empty($config['secret_key'])) {
                wp_send_json_error('BNA configuration is incomplete. Please configure your settings first.');
            }

            $tester = new BNA_Portal_Tester($config);
            $results = $tester->run_tests();
            $report = $tester->generate_report();

            wp_send_json_success(array(
                'report' => implode("\n", $report),
                'recommendation' => $tester->get_recommendation(),
                'results' => $results
            ));

        } catch (Exception $e) {
            wp_send_json_error('Test failed: ' . $e->getMessage());
        }
    }
}
