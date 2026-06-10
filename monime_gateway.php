<?php

/**
 * Plugin Name:       Monime Gateway
 * Description:       Monime Payment Gateway Plugin
 * Version:           1.0.1
 * Author:            Monime
 * Author URI:        https://monime.io/
 * Text Domain:       monime-gateway
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 8.2
 * WC tested up to:   10.8.0
 */

declare(strict_types=1);

use Adaptors\Givewp\GivewpAdapter;
use Monime\core\Webhook;
use Monime\services\AdapterService;

use function Monime\core\monime_log;

if (!defined('ABSPATH')) {
    exit(); // Exit if accessed directly.
}

// Plugin constants
define('MONIME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MONIME_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MONIME_VERSION', '1.0.1');

// Load core files once (early)
$core_files = [
    'src/Monime/core/logger.php',
    'src/Monime/core/Env.php',
    'src/Monime/core/get_urls.php',
    'src/Monime/core/dto.php',
    'src/Monime/core/MonimeClient.php',
    'src/Monime/core/webhook.php',
    'src/Monime/contracts/MonimeWebhookAdapterInterface.php',
    'src/Monime/contracts/MonimePaymentAdapterInterface.php',
    'src/Monime/registry/AdapterRegistry.php',
    'src/Monime/services/AdapterService.php',
    'src/Monime/services/PaymentService.php',
    'src/Monime/admin_pages/SettingsPage.php',
];

foreach ($core_files as $file) {
    $path = MONIME_PLUGIN_DIR . $file;
    if (file_exists($path)) {
        require_once $path;
    } else {
    }
}

;

/*
 |--------------------------------------------------------------------------
 | Admin Menu
 |--------------------------------------------------------------------------
 */
add_action('admin_menu', function () {
    $svg_path = MONIME_PLUGIN_DIR . 'assets/images/monime_icon.svg';
    $icon = '';
    if (file_exists($svg_path)) {
        $svg = file_get_contents($svg_path);
        $icon = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    add_menu_page(
        'Monime',
        'Monime',
        'manage_options',
        'monime-settings',
        'Monime\\admin_pages\\render_settings_page',
        $icon,
        56,
    );
});
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'monime_gateway_plugin_action_links');

function monime_gateway_plugin_action_links($links)
{
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('admin.php?page=monime-settings'),
        __('Settings', 'monime-gateway'),
    );

    array_unshift($links, $settings_link);

    return $links;
}

/*
 |--------------------------------------------------------------------------
 | Platform Integrations
 |--------------------------------------------------------------------------
 */
add_action('plugins_loaded', function () {
    \Monime\core\monime_log('info', 'plugins_loaded bootstrap started');

    // GiveWP Integration
    if (class_exists(\Give\Framework\PaymentGateways\PaymentGateway::class)) {
        \Monime\core\monime_log('info', 'GiveWP detected, loading integration');
        $give_files = [
            'src/adaptors/givewp/GiveMonimeGateway.php',
            'src/adaptors/givewp/GivewpAdapter.php',
        ];

        foreach ($give_files as $file) {
            $path = MONIME_PLUGIN_DIR . $file;

            if (file_exists($path)) {
                require_once $path;
            } else {
            }
        }

        GivewpAdapter::boot();
    } else {
        \Monime\core\monime_log('info', 'GiveWP not detected, skipping integration');
    }

    // WooCommerce Integration
    if (class_exists('WooCommerce') || function_exists('WC')) {
        \Monime\core\monime_log('info', 'WooCommerce detected, loading integration');
        $wc_files = [
            'src/adaptors/WC/WcMonimeGateway.php',
            'src/adaptors/WC/WC_Monime_Blocks_Support.php',
            'src/adaptors/WC/WcAdapter.php',
        ];
        foreach ($wc_files as $file) {
            $path = MONIME_PLUGIN_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists(WcAdapter::class)) {
            // Declare WooCommerce feature compatibility
            add_action('before_woocommerce_init', function () {
                if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                    // HPOS support
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                        'custom_order_tables',
                        __FILE__,
                        true,
                    );

                    // Cart & Checkout Blocks support
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                        'cart_checkout_blocks',
                        __FILE__,
                        true,
                    );
                }
            });
            WcAdapter::boot();
        }
    } else {
        monime_log('info', 'WooCommerce not detected, skipping integration');
    }

    // Shared Services
    if (class_exists(AdapterService::class)) {
        monime_log('info', 'Booting shared adapter services');
        AdapterService::boot();
    }

    if (class_exists(Webhook::class)) {
        monime_log('info', 'Initializing webhook endpoint');
        Webhook::init();
    }
});
