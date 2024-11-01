<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class WigWag_Blocks_Support extends AbstractPaymentMethodType {
    public WigWag_Client $client;
    public WigWag_Payment_Gateway $gateway;
    protected $name = 'wigwag';

    public function initialize(): void {
        $this->gateway = new WigWag_Payment_Gateway();
        $client_id = $this->gateway->get_option('client_id');
        $client_secret = $this->gateway->get_option('client_secret');

        $this->client = new WigWag_Client($client_id, $client_secret);
    }

    public function is_active(): bool {
        return true;
    }

    public function get_payment_method_script_handles() {
        $script_path = '/build/index.js';
        $script_asset_path = WIGWAG_PLUGIN_PATH.'/build/index.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require ($script_asset_path)
            : ['dependencies' => [], 'version' => filemtime(WIGWAG_PLUGIN_PATH.$script_path)];
        $script_url = WIGWAG_PLUGIN_URL.$script_path;

        $dependencies = [];
        wp_register_script(
            'wigwag-blocks-integration',
            $script_url,
            $dependencies,
            $script_asset['version'],
            true
        );

        wp_enqueue_style(
            'wigwag-blocks-checkout-style',
            WIGWAG_PLUGIN_URL.'/build/index.css',
            [],
            filemtime(WIGWAG_PLUGIN_PATH.'/build/index.css')
        );

        return ['wigwag-blocks-integration'];
    }

    public function get_payment_method_data(): array {
        $icon_block_visa = WIGWAG_PLUGIN_URL.'/assets/blocks/visa.svg';
        $icon_block_master_card = WIGWAG_PLUGIN_URL.'/assets/blocks/master-card.svg';
        $icon_block_capitec_pay = WIGWAG_PLUGIN_URL.'/assets/blocks/capitec-pay.svg';
        $icon_block_happy_pay = WIGWAG_PLUGIN_URL.'/assets/blocks/happy-pay.svg';
        $icon_block_google_pay = WIGWAG_PLUGIN_URL.'/assets/blocks/google-pay.svg';
        $icon_block_apple_pay = WIGWAG_PLUGIN_URL.'/assets/blocks/apple-pay.svg';

        return [
            'icon_block_visa' => $icon_block_visa,
            'icon_block_master_card' => $icon_block_master_card,
            'icon_block_capitec_pay' => $icon_block_capitec_pay,
            'icon_block_happy_pay' => $icon_block_happy_pay,
            'icon_block_apple_pay' => $icon_block_apple_pay,
            'icon_block_google_pay' => $icon_block_google_pay,
        ];
    }
}
