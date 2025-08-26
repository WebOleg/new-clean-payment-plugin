<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple BNA Logger with Payload Support
 */
class BNA_Simple_Logger {

    private static $log_file;

    public static function init() {
        self::$log_file = WP_CONTENT_DIR . '/bna-simple.log';
    }

    public static function log($message, $data = null) {
        if (!self::$log_file) {
            self::init();
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";
        
        if ($data) {
            $log_entry .= "Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
        }
        
        $log_entry .= str_repeat('-', 60) . "\n\n";
        
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    public static function log_api_request($message, $payload, $response_data = null) {
        if (!self::$log_file) {
            self::init();
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";
        $log_entry .= "PAYLOAD SENT:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        
        if ($response_data) {
            $log_entry .= "RESPONSE:\n" . json_encode($response_data, JSON_PRETTY_PRINT) . "\n";
        }
        
        $log_entry .= str_repeat('=', 60) . "\n\n";
        
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    public static function get_logs($lines = 50) {
        if (!file_exists(self::$log_file)) {
            return 'No logs found';
        }
        
        $content = file_get_contents(self::$log_file);
        $all_lines = explode("\n", $content);
        $recent_lines = array_slice($all_lines, -$lines);
        
        return implode("\n", $recent_lines);
    }

    public static function clear_logs() {
        if (file_exists(self::$log_file)) {
            unlink(self::$log_file);
        }
    }
}
