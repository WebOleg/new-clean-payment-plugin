<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple BNA Admin Interface
 */
class BNA_Simple_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_bna_get_simple_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_bna_clear_simple_logs', array($this, 'ajax_clear_logs'));
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Logs',
            'BNA Logs',
            'manage_options',
            'bna-logs',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>BNA Payment Gateway Logs</h1>
            
            <div style="margin: 15px 0;">
                <button id="refresh-logs" class="button button-secondary">Refresh</button>
                <button id="clear-logs" class="button button-secondary">Clear Logs</button>
            </div>

            <div id="logs-container" style="background: #f1f1f1; padding: 15px; border: 1px solid #ccc; height: 500px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap;"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function loadLogs() {
                $.post(ajaxurl, {action: 'bna_get_simple_logs'}, function(response) {
                    if (response.success) {
                        $('#logs-container').text(response.data);
                        $('#logs-container').scrollTop($('#logs-container')[0].scrollHeight);
                    }
                });
            }

            $('#refresh-logs').click(loadLogs);
            $('#clear-logs').click(function() {
                if (confirm('Clear all logs?')) {
                    $.post(ajaxurl, {action: 'bna_clear_simple_logs'}, function(response) {
                        if (response.success) {
                            loadLogs();
                        }
                    });
                }
            });

            loadLogs();
            setInterval(loadLogs, 30000); // Auto-refresh every 30 seconds
        });
        </script>
        <?php
    }

    public function ajax_get_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $logs = BNA_Simple_Logger::get_logs(100);
        wp_send_json_success($logs);
    }

    public function ajax_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        BNA_Simple_Logger::clear_logs();
        wp_send_json_success();
    }
}
