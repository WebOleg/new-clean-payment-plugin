<?php
/**
 * Simple AJAX test for BNA Bridge
 * Add to functions.php temporarily for debugging
 */

// Add this to functions.php for testing
add_action('wp_ajax_bna_test_simple', 'bna_test_simple_ajax');
add_action('wp_ajax_nopriv_bna_test_simple', 'bna_test_simple_ajax');

function bna_test_simple_ajax() {
    error_log('BNA: Simple AJAX test called successfully');
    wp_send_json_success(array(
        'message' => 'AJAX is working!',
        'timestamp' => current_time('mysql')
    ));
}
