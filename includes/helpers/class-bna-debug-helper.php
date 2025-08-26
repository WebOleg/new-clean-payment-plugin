<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Debug Helper Class
 */
class BNA_Debug_Helper {

    private static $log_file;
    private static $initialized = false;

    /**
     * Initialize debug helper
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $debug_dir = $upload_dir['basedir'] . '/bna-debug';
        
        if (!file_exists($debug_dir)) {
            wp_mkdir_p($debug_dir);
        }

        self::$log_file = $debug_dir . '/bna-debug.log';

        // Create log file if it doesn't exist
        if (!file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, "[" . current_time('Y-m-d H:i:s') . "] BNA Debug Log Started\n");
        }

        self::$initialized = true;
    }

    /**
     * Log debug message
     */
    public static function log($message, $context = array(), $level = 'INFO') {
        self::init();

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = self::format_log_message($timestamp, $level, $message, $context);

        // Write to BNA debug log file
        file_put_contents(self::$log_file, $formatted_message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Format log message
     */
    private static function format_log_message($timestamp, $level, $message, $context) {
        $formatted = "[$timestamp] [$level] $message";

        if (!empty($context)) {
            $formatted .= " | Context: " . json_encode($context);
        }

        return $formatted;
    }

    /**
     * Get log file path
     */
    public static function get_log_file() {
        self::init();
        return self::$log_file;
    }

    /**
     * Clear log file
     */
    public static function clear_log() {
        self::init();
        if (file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, "[" . current_time('Y-m-d H:i:s') . "] BNA Debug Log Cleared\n");
        }
    }

    /**
     * Get log contents
     */
    public static function get_log_contents($lines = 100) {
        self::init();
        if (!file_exists(self::$log_file)) {
            return "Log file not found";
        }

        $content = file_get_contents(self::$log_file);
        $log_lines = explode("\n", $content);
        
        if (count($log_lines) > $lines) {
            $log_lines = array_slice($log_lines, -$lines);
        }
        
        return implode("\n", $log_lines);
    }
}
