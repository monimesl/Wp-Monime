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
        \Monime\core\monime_log('info', 'PaymentService create started', [
            'adapter_id' => $adaptorid,
        ]);

        // Resolve the adapter that knows how to translate this platform payload.
        $adaptor = AdapterRegistry::get($adaptorid);
        if (!$adaptor) {
            \Monime\core\monime_log('error', 'PaymentService adapter not found', [
                'adapter_id' => $adaptorid,
            ]);
            return [
                'message' => 'Adapter not found'
            ];
        };
        $env = Env::get();

		// Provide default display text when an adapter did not supply it.

        // Normalize adapter-specific data into the shared Monime request DTO.
        $data = $adaptor->buildPaymentPayload($payload);
        \Monime\core\monime_log('info', 'Payment payload built', [
            'adapter_id' => $adaptorid,
            'payload_class' => get_class($data),
        ]);
        $client = new MonimeClient(
            $env['monime_token'],
            $env['monime_space_id']
        );
        // Pass the adapter ID so Monime webhooks can be routed back correctly.
        $response = $client->create_checkout_session($data, $adaptorid);
        \Monime\core\monime_log('info', 'PaymentService create finished', [
            'adapter_id' => $adaptorid,
            'has_redirect_url' => !empty($response['redirectUrl']),
        ]);
        return $response;
    }
}
