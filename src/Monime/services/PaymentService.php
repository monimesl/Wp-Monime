<?php

namespace Monime\services;

use Monime\core\MonimeClient;
use Monime\registry\AdapterRegistry;
use Monime\core\Env;

/**
 * Coordinates payment creation between platform adapters and MonimeClient.
 */
class PaymentService
{
	/**
	 * Build a Monime checkout request through the selected adapter and send it.
	 */
	public static function create(string $adaptorid, array $payload): array
	{
		// Resolve the adapter that knows how to translate this platform payload.
		$adaptor = AdapterRegistry::get($adaptorid);
		if (!$adaptor) {
			error_log('adapter not found');
			return [
				'message' => 'Adapter not found'
			];
		};
		$env = Env::get();

		// Provide default display text when an adapter did not supply it.

		// Normalize adapter-specific data into the shared Monime request DTO.
		$data = $adaptor->buildPaymentPayload($payload);
		$client = new MonimeClient(
			$env['monime_token'],
			$env['monime_space_id']
		);
		error_log(print_r($data, true));
		// Pass the adapter ID so Monime webhooks can be routed back correctly.
		$response = $client->create_checkout_session($data, $adaptorid);
		error_log(print_r($response, true));
		return $response;
	}
}
