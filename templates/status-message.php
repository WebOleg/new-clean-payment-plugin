<?php
if (!defined('ABSPATH')) {
    exit;
}

$valid_types = array('success', 'error', 'processing', 'info');
$type = in_array($type, $valid_types) ? $type : 'info';
?>

<div class="bna-message <?php echo esc_attr($type); ?>">
    <?php if ($show_icon): ?>
        <span class="bna-message-icon"></span>
    <?php endif; ?>
    <span class="bna-message-text"><?php echo esc_html($message); ?></span>
</div>
