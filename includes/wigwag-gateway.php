<?php

defined('ABSPATH') || exit;

class WigWag_Payment_Gateway extends WC_Payment_Gateway {
    private object $logger;

    public function __construct() {
        $this->id = 'wigwag';
        $this->icon = WIGWAG_PLUGIN_URL.'/assets/wc-logo.svg';
        $this->has_fields = false;
        $this->title = 'Pay with Apple | Google | Capitec | Card';
        $this->description = 'Secure payments powered by WigWag';
        $this->method_title = 'WigWag';
        $this->method_description = 'Pay with WigWag';
        $this->countries = ['ZA'];
        $this->supports = [
            'products',
        ];
        $this->includes();
        $this->init_form_fields();
        $this->init_settings();
        $this->logger = wc_get_logger();

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
    }

    public function needs_setup(): bool {
        return !$this->get_option('client_id') || !$this->get_option('client_secret');
    }

    public function includes(): void {
        require_once plugin_dir_path(__FILE__).'wigwag-client.php';
    }

    public function admin_options(): void {
        parent::admin_options();
        ?>
            <h4>Redirect URL</h4>
            <p>Make sure to add this as a redirect URL on your WigWag account page</p>
            <p>
                <?php echo site_url('index.php'); ?>
            </p>
        <?php
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'wigwag'),
                'type' => 'checkbox',
                'label' => __('Enable WigWag Payments', 'wigwag'),
                'default' => 'yes',
            ],
            'client_id' => [
                'title' => __('Client ID', 'wigwag'),
                'type' => 'text',
                'description' => __('WigWag Client ID', 'wigwag'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'client_secret' => [
                'title' => __('Client Secret', 'wigwag'),
                'type' => 'password',
                'description' => __('WigWag Client Secret', 'wigwag'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'skip_checkout' => [
                'title' => __('Skip Checkout Page', 'wigwag'),
                'type' => 'checkbox',
                'label' => __('Skip the WigWag checkout page', 'wigwag'),
                'default' => 'yes',
            ],
        ];
    }

    public function process_payment($order_id): null|array {
        $order = new WC_Order($order_id);
        $order->update_status('awaiting-payment', __('Awaiting payment', 'woocommerce'));

        $total_in_cents = (int) ($order->get_total() * 100); // TODO: Check rounding
        $customer_name = substr(
            $order->get_billing_first_name().
            ' '.
            $order->get_billing_last_name(),
            0,
            20
        );
        $merchant_ref = $this->generate_merchant_reference($order);
        $wigwag_client = new WigWag_Client($this->get_option('client_id'), $this->get_option('client_secret'));
        $skip_checkout_page = $this->get_option('skip_checkout') === 'yes';

        try {
            $url = $wigwag_client->create_payment_link($total_in_cents, $customer_name, $merchant_ref, $skip_checkout_page);
        } catch (Exception $exception) {
            $this->handle_api_error($exception, 'Payment failed. Please try again.');

            return null;
        }

        return [
            'result' => 'success',
            'redirect' => $url.'?'.http_build_query(['redirect_url' => $this->generate_redirect_url()]),
        ];
    }

    private function generate_merchant_reference(WC_Order $order): string {
        return 'WC-'.$order->get_id();
    }

    private function generate_redirect_url(): string {
        $params = http_build_query(['rest_route' => '/wigwag/v1/callback/']);

        return site_url('/index.php').'?'.$params;
    }

    private function handle_api_error(Exception $exception, string $message): void {
        $this->logger->error($exception, ['source' => ' wigwag']);
        wc_add_notice($message, 'error');
    }
}
