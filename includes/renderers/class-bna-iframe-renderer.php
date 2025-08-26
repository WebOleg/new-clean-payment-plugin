<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Iframe Renderer Class
 */
class BNA_Iframe_Renderer {

    private $config;
    private $api_url;
    private $token;
    private $order;

    public function __construct($config, $token, $order) {
        $this->config = $config;
        $this->token = $token;
        $this->order = $order;

        $api_helper = new BNA_Api_Helper($config);
        $this->api_url = $api_helper->get_api_url();
    }

    /**
     * Render iframe payment template
     */
    public function render() {
        $template_data = $this->prepare_template_data();
        extract($template_data);

        include BNA_GATEWAY_PLUGIN_PATH . 'templates/iframe-payment.php';
    }

    /**
     * Prepare data for template
     */
    private function prepare_template_data() {
        $iframe_url = $this->api_url . '/v1/checkout/' . $this->token;

        $currency_symbol = get_woocommerce_currency_symbol($this->order->get_currency());
        $clean_total = $currency_symbol . number_format($this->order->get_total(), 2);

        return array(
            'iframe_url' => $iframe_url,
            'order_id' => $this->order->get_id(),
            'thank_you_url' => $this->order->get_checkout_order_received_url(),
            'checkout_url' => wc_get_checkout_url(),
            'api_origin' => $this->api_url,
            'order_total' => $clean_total,
            'order_total_raw' => $this->order->get_total(),
            'currency' => $this->order->get_currency(),
            'currency_symbol' => $currency_symbol,
            'order_number' => $this->order->get_order_number()
        );
    }

    /**
     * Render status message
     */
    public static function render_message($type, $message, $show_icon = true) {
        $template_data = array(
            'type' => $type,
            'message' => $message,
            'show_icon' => $show_icon
        );
        extract($template_data);

        include BNA_GATEWAY_PLUGIN_PATH . 'templates/status-message.php';
    }
}
