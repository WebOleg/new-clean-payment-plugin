<?php
/**
 * Simple AJAX test to verify AJAX works
 */

// Add simple AJAX test
add_action('wp_ajax_simple_bna_test', 'simple_bna_test_callback');
add_action('wp_ajax_nopriv_simple_bna_test', 'simple_bna_test_callback');

function simple_bna_test_callback() {
    // Log the test
    error_log('BNA: Simple AJAX test called successfully');

    echo "✅ AJAX працює правильно! Час: " . date('H:i:s');
    wp_die();
}