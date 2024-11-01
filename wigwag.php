<?php

/*
 * Plugin Name:          WigWag
 * Description:          Use WigWag to easily and securely accept Card payment on your WooCommerce store.
 * Plugin URI:           https://wordpress.org/plugins/wigwag/
 * Version:              1.2.3
 * Requires at least:    6.5
 * Requires PHP:         8.0
 * WC requires at least: 8.0
 * WC tested up to:      9.3
 * Author:               WigWag
 * Author URI:           https://wigwag.me
 * License:              GPL v3
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          wigwag
 */

defined('ABSPATH') || exit;

define('WIGWAG_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('WIGWAG_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));

add_action('plugins_loaded', 'wigwag_init_gateway', 11);
function wigwag_init_gateway(): void {
    if (!class_exists('WC_Payment_Gateway')) {
        // WooCommerce is not defined
        return;
    }

    if (class_exists('WigWag_Payment_Gateway')) {
        // Already initialised
        return;
    }

    require_once WIGWAG_PLUGIN_PATH.'/includes/wigwag-gateway.php';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'wigwag_plugin_admin_links');
function wigwag_plugin_admin_links(array $links): array {
    $link = '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=wigwag').'">'.__('Settings', 'wigwag').'</a>';
    array_unshift($links, $link);

    return $links;
}

add_filter('woocommerce_payment_gateways', 'add_wigwag_gateway');
function add_wigwag_gateway(array $methods): array {
    $methods[] = 'WigWag_Payment_Gateway';

    return $methods;
}

add_action('rest_api_init', 'wigwag_register_rest_api_routes');
function wigwag_register_rest_api_routes(): void {
    register_rest_route(
        'wigwag/v1',
        '/callback',
        [
            'methods' => 'GET',
            'callback' => 'wigwag_handle_callback',
            'permission_callback' => '__return_true',
        ]
    );
}

function wigwag_handle_callback(WP_REST_Request $request): WP_REST_Response {
    $gateway = new WigWag_Payment_Gateway();
    $logger = wc_get_logger();

    $reference = $request->get_param('reference');
    $payment_id = $request->get_param('payment_id');
    $split_string = explode('-', $reference);
    $order_id = $split_string[1];

    $order = wc_get_order($order_id);

    if (!$order) {
        $logger->error("Order not found for ID: {$order_id}", ['source' => ' wigwag']);
        wc_add_notice('Order not found', 'error');

        return wigwag_generate_webhook_response(home_url());
    }

    $wigwag_client = new WigWag_Client($gateway->get_option('client_id'), $gateway->get_option('client_secret'));

    try {
        $payment_status = $wigwag_client->get_payment_status($payment_id);
    } catch (Exception $exception) {
        $logger->error($exception, ['source' => ' wigwag']);
        wc_add_notice('Failed to check status of your payment. Please contact the store.', 'error');

        return wigwag_generate_webhook_response($order->get_view_order_url());
    }

    if ($payment_status !== 'PAID') {
        $logger->warning("Unpaid status ({$payment_status}) for payment {$payment_id}", ['source' => ' wigwag']);
        wc_add_notice('Payment not completed', 'error');

        return wigwag_generate_webhook_response($order->get_view_order_url());
    }

    $order->payment_complete($payment_id);
    $order->save();

    return wigwag_generate_webhook_response($order->get_checkout_order_received_url());
}

function wigwag_generate_webhook_response(string $url): WP_REST_Response {
    return new WP_REST_Response(
        null,
        302,
        [
            'Location' => $url,
        ]
    );
}

add_action('woocommerce_blocks_loaded', 'wigwag_load_gateway_block_support');
function wigwag_load_gateway_block_support(): void {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once WIGWAG_PLUGIN_PATH.'/includes/wigwag-block.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        static function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $container = Automattic\WooCommerce\Blocks\Package::container();
            // registers as shared instance.
            $container->register(
                WigWag_Blocks_Support::class,
                static function () {
                    return new WigWag_Blocks_Support();
                }
            );
            $payment_method_registry->register(
                $container->get(WigWag_Blocks_Support::class)
            );
        },
    );
}

add_action('before_woocommerce_init', static function () {
    if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        return;
    }

    Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
});
