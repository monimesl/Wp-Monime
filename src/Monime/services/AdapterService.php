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
		do_action('monime_register_adapters');
	}
}
