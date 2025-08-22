<?php
/**
 * BNA Logger Class
 * Handles logging for debugging and monitoring
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Logger
 * Logs messages to database and file for debugging
 */
class BNA_Logger {

    private static $instance = null;
    private $enabled;
    private $log_level;
    private $log_levels = array(
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    );

    /**
     * Get singleton instance
     * @return BNA_Logger
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - setup logging configuration
     */
    private function __construct() {
        $this->enabled = get_option('bna_logging_enabled', true);
        $this->log_level = get_option('bna_log_level', 'info');
    }

    /**
     * Log a message
     * @param string $message
     * @param string $level
     * @param array $context
     * @param string $source
     */
    public static function log($message, $level = 'info', $context = array(), $source = '') {
        $instance = self::get_instance();
        
        if (!$instance->enabled) {
            return;
        }

        if (!$instance->should_log($level)) {
            return;
        }

        $instance->write_log($message, $level, $context, $source);
    }

    /**
     * Log debug message
     * @param string $message
     * @param array $context
     * @param string $source
     */
    public static function debug($message, $context = array(), $source = '') {
        self::log($message, 'debug', $context, $source);
    }

    /**
     * Log info message
     * @param string $message
     * @param array $context
     * @param string $source
     */
    public static function info($message, $context = array(), $source = '') {
        self::log($message, 'info', $context, $source);
    }

    /**
     * Log warning message
     * @param string $message
     * @param array $context
     * @param string $source
     */
    public static function warning($message, $context = array(), $source = '') {
        self::log($message, 'warning', $context, $source);
    }

    /**
     * Log error message
     * @param string $message
     * @param array $context
     * @param string $source
     */
    public static function error($message, $context = array(), $source = '') {
        self::log($message, 'error', $context, $source);
    }

    /**
     * Log critical message
     * @param string $message
     * @param array $context
     * @param string $source
     */
    public static function critical($message, $context = array(), $source = '') {
        self::log($message, 'critical', $context, $source);
    }

    /**
     * Log API request
     * @param string $url
     * @param array $request_data
     * @param array $response_data
     * @param int $response_code
     */
    public static function log_api_request($url, $request_data = array(), $response_data = array(), $response_code = 0) {
        $context = array(
            'url' => $url,
            'request' => $request_data,
            'response' => $response_data,
            'response_code' => $response_code
        );

        $level = ($response_code >= 200 && $response_code < 300) ? 'info' : 'error';
        $message = sprintf('API Request to %s - Response Code: %d', $url, $response_code);

        self::log($message, $level, $context, 'api');
    }

    /**
     * Log payment transaction
     * @param string $transaction_id
     * @param string $status
     * @param array $data
     */
    public static function log_payment($transaction_id, $status, $data = array()) {
        $context = array(
            'transaction_id' => $transaction_id,
            'status' => $status,
            'data' => $data
        );

        $message = sprintf('Payment transaction %s - Status: %s', $transaction_id, $status);
        self::log($message, 'info', $context, 'payment');
    }

    /**
     * Log order processing
     * @param int $order_id
     * @param string $action
     * @param array $data
     */
    public static function log_order($order_id, $action, $data = array()) {
        $context = array(
            'order_id' => $order_id,
            'action' => $action,
            'data' => $data
        );

        $message = sprintf('Order %d - Action: %s', $order_id, $action);
        self::log($message, 'info', $context, 'order');
    }

    /**
     * Check if message should be logged based on level
     * @param string $level
     * @return bool
     */
    private function should_log($level) {
        $level_value = isset($this->log_levels[$level]) ? $this->log_levels[$level] : 1;
        $min_level_value = isset($this->log_levels[$this->log_level]) ? $this->log_levels[$this->log_level] : 1;
        
        return $level_value >= $min_level_value;
    }

    /**
     * Write log message to database and file
     * @param string $message
     * @param string $level
     * @param array $context
     * @param string $source
     */
    private function write_log($message, $level, $context, $source) {
        // Write to database
        $this->write_to_database($message, $level, $context, $source);

        // Write to file for critical errors
        if ($level === 'critical' || $level === 'error') {
            $this->write_to_file($message, $level, $context, $source);
        }

        // Write to WordPress debug log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = sprintf('[BNA %s] %s', strtoupper($level), $message);
            if (!empty($context)) {
                $log_message .= ' | Context: ' . wp_json_encode($context);
            }
            error_log($log_message);
        }
    }

    /**
     * Write log to database
     * @param string $message
     * @param string $level
     * @param array $context
     * @param string $source
     */
    private function write_to_database($message, $level, $context, $source) {
        global $wpdb;

        $table = $wpdb->prefix . 'bna_logs';
        
        $wpdb->insert(
            $table,
            array(
                'level' => $level,
                'message' => $message,
                'context' => !empty($context) ? wp_json_encode($context) : null,
                'source' => $source,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Write log to file
     * @param string $message
     * @param string $level
     * @param array $context
     * @param string $source
     */
    private function write_to_file($message, $level, $context, $source) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/bna-payment-gateway/logs';
        $log_file = $log_dir . '/bna-' . date('Y-m-d') . '.log';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] %s: %s",
            $timestamp,
            strtoupper($level),
            $message
        );

        if (!empty($source)) {
            $log_entry .= " | Source: {$source}";
        }

        if (!empty($context)) {
            $log_entry .= " | Context: " . wp_json_encode($context);
        }

        $log_entry .= "\n";

        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get logs from database
     * @param array $args
     * @return array
     */
    public static function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'level' => '',
            'source' => '',
            'limit' => 100,
            'offset' => 0,
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);
        $table = $wpdb->prefix . 'bna_logs';
        $where_clauses = array('1=1');
        $values = array();

        if (!empty($args['level'])) {
            $where_clauses[] = 'level = %s';
            $values[] = $args['level'];
        }

        if (!empty($args['source'])) {
            $where_clauses[] = 'source = %s';
            $values[] = $args['source'];
        }

        $where_sql = implode(' AND ', $where_clauses);
        $order_sql = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at {$order_sql} LIMIT %d OFFSET %d";
        $values[] = (int) $args['limit'];
        $values[] = (int) $args['offset'];

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Clear old logs from database
     * @param int $days
     * @return int Number of deleted rows
     */
    public static function clear_old_logs($days = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'bna_logs';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff_date
            )
        );
    }

    /**
     * Get log counts by level
     * @return array
     */
    public static function get_log_counts() {
        global $wpdb;

        $table = $wpdb->prefix . 'bna_logs';
        $results = $wpdb->get_results("
            SELECT level, COUNT(*) as count 
            FROM {$table} 
            GROUP BY level
        ");

        $counts = array();
        foreach ($results as $result) {
            $counts[$result->level] = (int) $result->count;
        }

        return $counts;
    }

    /**
     * Enable logging
     */
    public static function enable() {
        update_option('bna_logging_enabled', true);
    }

    /**
     * Disable logging
     */
    public static function disable() {
        update_option('bna_logging_enabled', false);
    }

    /**
     * Set log level
     * @param string $level
     */
    public static function set_level($level) {
        $valid_levels = array('debug', 'info', 'warning', 'error', 'critical');
        if (in_array($level, $valid_levels)) {
            update_option('bna_log_level', $level);
        }
    }
}
