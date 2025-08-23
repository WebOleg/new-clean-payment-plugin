<?php
// Оновлений тест для перевірки Login і Secret Key
// Відкрийте цей файл в браузері: your-site.com/wp-content/plugins/bna-wordpress-plugin/debug-test.php

require_once('../../../wp-config.php');

echo "<h2>🔍 BNA Plugin Debug Info (Updated)</h2>";

// Перевірка чи WooCommerce активний
if (class_exists('WC_Payment_Gateway')) {
    echo "✅ WooCommerce is active<br>";
} else {
    echo "❌ WooCommerce not found<br>";
}

// Перевірка налаштувань
$settings = get_option('woocommerce_bna_payment_gateway_settings');
if ($settings) {
    echo "✅ Plugin settings found<br><br>";
    echo "<strong>📋 Current Settings:</strong><br>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th style='padding: 8px; background: #f0f0f0;'>Field</th><th style='padding: 8px; background: #f0f0f0;'>Status</th><th style='padding: 8px; background: #f0f0f0;'>Value</th></tr>";
    
    // Old fields (може залишилися)
    echo "<tr><td style='padding: 8px;'>Access Key (old)</td><td style='padding: 8px;'>" . (!empty($settings['access_key']) ? '✅ Present' : '❌ Missing') . "</td><td style='padding: 8px;'>" . (isset($settings['access_key']) ? esc_html(substr($settings['access_key'], 0, 10) . '...') : 'Not set') . "</td></tr>";
    echo "<tr><td style='padding: 8px;'>Secret Key (old)</td><td style='padding: 8px;'>" . (!empty($settings['secret_key']) ? '✅ Present' : '❌ Missing') . "</td><td style='padding: 8px;'>" . (isset($settings['secret_key']) ? '***' : 'Not set') . "</td></tr>";
    
    // New fields (потрібні для API)
    echo "<tr style='background: #e8f5e8;'><td style='padding: 8px;'><strong>Login (API)</strong></td><td style='padding: 8px;'>" . (!empty($settings['login']) ? '✅ Present' : '❌ Missing') . "</td><td style='padding: 8px;'>" . (isset($settings['login']) ? esc_html($settings['login']) : 'Not set') . "</td></tr>";
    echo "<tr style='background: #e8f5e8;'><td style='padding: 8px;'><strong>Secret Key (API)</strong></td><td style='padding: 8px;'>" . (!empty($settings['secretKey']) ? '✅ Present' : '❌ Missing') . "</td><td style='padding: 8px;'>" . (isset($settings['secretKey']) ? '***' : 'Not set') . "</td></tr>";
    
    // Other fields
    echo "<tr><td style='padding: 8px;'>iFrame ID</td><td style='padding: 8px;'>" . (!empty($settings['iframe_id']) ? '✅ Present' : '❌ Missing') . "</td><td style='padding: 8px;'>" . (isset($settings['iframe_id']) ? esc_html(substr($settings['iframe_id'], 0, 20) . '...') : 'Not set') . "</td></tr>";
    echo "<tr><td style='padding: 8px;'>Environment</td><td style='padding: 8px;'>ℹ️ Set</td><td style='padding: 8px;'>" . ($settings['environment'] ?? 'Not set') . "</td></tr>";
    echo "<tr><td style='padding: 8px;'>Enabled</td><td style='padding: 8px;'>ℹ️ Set</td><td style='padding: 8px;'>" . ($settings['enabled'] ?? 'no') . "</td></tr>";
    echo "</table>";
    
    // API Requirements check
    echo "<br><strong>🔑 API Requirements:</strong><br>";
    $login_ok = !empty($settings['login']);
    $secret_ok = !empty($settings['secretKey']);
    $iframe_ok = !empty($settings['iframe_id']);
    
    if ($login_ok && $secret_ok && $iframe_ok) {
        echo "<div style='background: #e8f5e8; padding: 10px; border: 1px solid #4CAF50; border-radius: 4px;'>";
        echo "✅ <strong>All API requirements met!</strong><br>";
        echo "• Login: ✅<br>";
        echo "• Secret Key (API): ✅<br>";
        echo "• iFrame ID: ✅<br>";
        echo "<br><em>Ready for API integration!</em>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 4px;'>";
        echo "⚠️ <strong>Missing API requirements:</strong><br>";
        if (!$login_ok) echo "• Login field is empty<br>";
        if (!$secret_ok) echo "• Secret Key (API) field is empty<br>";
        if (!$iframe_ok) echo "• iFrame ID is empty<br>";
        echo "</div>";
    }
    
} else {
    echo "❌ Plugin settings not found<br>";
    echo "Please configure the plugin in WooCommerce settings<br>";
}

// Перевірка AJAX
echo "<br><strong>🔗 AJAX Test:</strong><br>";
echo "AJAX URL: " . admin_url('admin-ajax.php') . "<br>";

// Перевірка nonce
$nonce = wp_create_nonce('bna_iframe_nonce');
echo "Test nonce: " . $nonce . "<br>";

echo "<br><strong>💡 Next Steps:</strong><br>";
echo "1. Fill Login and Secret Key (API) fields in WooCommerce settings<br>";
echo "2. Test the checkout page<br>";
echo "3. Check WordPress error logs for API responses<br>";

echo "<br><em>If you see this page, WordPress is working correctly.</em>";
