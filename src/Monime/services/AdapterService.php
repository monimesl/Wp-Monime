<?php

namespace Monime\services;

/**
 * Boots integrations that register themselves with the Monime plugin.
 */
class AdapterService
{

	/**
	 * Fire the shared registration hook consumed by WooCommerce and GiveWP.
	 */
    public static function boot(): void
    {
        \Monime\core\monime_log('info', 'AdapterService boot started');
        do_action('monime_register_adapters');
        \Monime\core\monime_log('info', 'AdapterService boot finished', [
            'adapter_count' => count(\Monime\registry\AdapterRegistry::getAll()),
        ]);
    }
}
