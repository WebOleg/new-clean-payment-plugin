<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Admin Debug Interface
 *
 * Admin interface for viewing and managing debug logs
 */
class BNA_Admin_Debug {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_debug_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_debug_scripts'));
    }

    /**
     * Add debug menu to WordPress admin
     *
     * @return void
     */
    public function add_debug_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Debug Logs',
            'BNA Debug',
            'manage_options',
            'bna-debug',
            array($this, 'render_debug_page')
        );
    }

    /**
     * Enqueue debug page scripts
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_debug_scripts($hook) {
        if ($hook !== 'woocommerce_page_bna-debug') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'bna_debug_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bna_debug_nonce')
        ));
    }

    /**
     * Render debug page
     *
     * @return void
     */
    public function render_debug_page() {
        $debug_stats = BNA_Debug_Helper::get_debug_stats();
        ?>
        <div class="wrap">
            <h1>BNA Gateway Debug Logs</h1>

            <?php $this->render_debug_stats($debug_stats); ?>

            <div class="bna-debug-section">
                <h2>Real-time Debug Log</h2>
                <?php echo BNA_Debug_Helper::get_debug_viewer_html(); ?>
            </div>

            <div class="bna-debug-section">
                <h2>Quick Debug Actions</h2>
                <?php $this->render_debug_actions(); ?>
            </div>

        </div>

        <style>
        .bna-debug-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 20px 0;
            padding: 20px;
        }

        .bna-debug-stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }

        .bna-stat-box {
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            text-align: center;
            min-width: 120px;
        }

        .bna-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }

        .bna-stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .bna-status-active { color: #46b450; }
        .bna-status-inactive { color: #dc3232; }

        .bna-debug-actions {
            display: flex;
            gap: 10px;
            margin: 15px 0;
        }
        </style>
        <?php
    }

    /**
     * Render debug statistics
     *
     * @param array $stats Debug statistics
     * @return void
     */
    private function render_debug_stats($stats) {
        ?>
        <div class="bna-debug-section">
            <h2>Debug Status</h2>
            <div class="bna-debug-stats">
                <div class="bna-stat-box">
                    <div class="bna-stat-value <?php echo $stats['debug_mode'] ? 'bna-status-active' : 'bna-status-inactive'; ?>">
                        <?php echo $stats['debug_mode'] ? 'ON' : 'OFF'; ?>
                    </div>
                    <div class="bna-stat-label">Debug Mode</div>
                </div>

                <div class="bna-stat-box">
                    <div class="bna-stat-value">
                        <?php echo $stats['log_file_exists'] ? number_format($stats['log_file_size'] / 1024, 1) . 'KB' : '0KB'; ?>
                    </div>
                    <div class="bna-stat-label">Log File Size</div>
                </div>

                <div class="bna-stat-box">
                    <div class="bna-stat-value <?php echo $stats['wp_debug'] ? 'bna-status-active' : 'bna-status-inactive'; ?>">
                        <?php echo $stats['wp_debug'] ? 'YES' : 'NO'; ?>
                    </div>
                    <div class="bna-stat-label">WP Debug</div>
                </div>
            </div>

            <?php if (!$stats['debug_mode']): ?>
                <div class="notice notice-warning">
                    <p><strong>Debug mode is disabled.</strong> To enable debugging, add <code>define('WP_DEBUG', true);</code> to your wp-config.php file.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render debug actions
     *
     * @return void
     */
    private function render_debug_actions() {
        ?>
        <div class="bna-debug-actions">
            <button id="bna-test-debug" class="button button-secondary">Test Debug Logging</button>
            <button id="bna-test-api" class="button button-secondary">Test API Connection</button>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#bna-test-debug').click(function() {
                $.post(ajaxurl, {
                    action: 'bna_test_debug_logging',
                    _ajax_nonce: '<?php echo wp_create_nonce("bna_debug_nonce"); ?>'
                }, function(response) {
                    alert(response.success ? 'Debug test completed! Check the log above.' : 'Debug test failed: ' + response.data);
                });
            });

            $('#bna-test-api').click(function() {
                $(this).text('Testing...').prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'bna_test_api_connection',
                    _ajax_nonce: '<?php echo wp_create_nonce("bna_debug_nonce"); ?>'
                }, function(response) {
                    alert(response.success ? 'API test completed! Check the log above.' : 'API test failed: ' + response.data);
                }).always(function() {
                    $('#bna-test-api').text('Test API Connection').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }
}
