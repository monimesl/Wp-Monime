<?php

namespace Monime\core;

use function Monime\core\get_urls;
use Monime\core\CreateDonationRequest;

require_once plugin_dir_path(__FILE__) . './get_urls.php';


/**
 * Thin WordPress HTTP client for Monime checkout-session requests.
 *
 * Adapters normalize platform-specific order/donation data into
 * CreateDonationRequest, then this class handles headers, JSON encoding,
 * response validation, and response parsing.
 */
class MonimeClient
{
	/** Monime  token configured in WordPress settings. */
	private $bearer_token;

	/** Monime space ID sent with each API request. */
	private $monime_space_id;

	/** API endpoint map returned by get_urls(). */
	private $url;

	/**
	 * Store Monime credentials for later API calls.
	 */
	public function __construct(string $monime_token, string $monime_space_id)
	{
		$this->url = get_urls();
		$this->bearer_token = $monime_token;
		$this->monime_space_id = $monime_space_id;
	}

	/**
	 * Create a hosted Monime checkout session and return the decoded API response.
	 *
	 * The handler value is stored in checkout metadata so verified webhooks can
	 * be routed back to the adapter that created the session.
	 */
	public function create_checkout_session(CreateDonationRequest $donationrequest, string $_handler)
	{
		if (empty($this->url['checkout_session_url'])) {
			throw new \RuntimeException('Checkout URL not configured');
		}

		//$amountValue = $donationrequest->amount;
		// Keep these values explicit because they are reused in headers/body.
		$reference = $donationrequest->reference;
		$idempotency_key = $donationrequest->idempotency_key;

		// Monime requires bearer auth, an idempotency key, and the target space.
		$headers = [
			'Authorization'   => 'Bearer ' . $this->bearer_token,
			'Content-Type'    => 'application/json',
			'Idempotency-Key' =>  $idempotency_key,
			'Monime-Space-Id' => $this->monime_space_id,
		];

		// Base checkout-session payload shared by WooCommerce and GiveWP.
		$data = [
			'name' => !empty($donationrequest->name)
				? $donationrequest->name
				: 'Donation',

			'description' => $donationrequest->description,

			'metadata' => array_merge(
				[
					'handler' => $_handler,
				],
				$donationrequest->metadata
			),

			'lineItems' => $donationrequest->items,

			'reference' => $reference,


			'cancelUrl' => $donationrequest->cancelurl,


			'successUrl' => $donationrequest->success_url,

			'callbackState' => $donationrequest->callbackState,
		];

		// Optional request fields are included only when an adapter provides them.
		if (!empty($donationrequest->financialAccountId)) {
			$data['financialAccountId'] = $donationrequest->financialAccountId;
		}


		if (!empty($donationrequest->callbackState)) {
			$data['callbackState'] = $donationrequest->callbackState;
		}

		if (!empty($donationrequest->paymentOptions)) {
			$data['paymentOptions'] = $donationrequest->paymentOptions;
		}

		// Use the WordPress HTTP API so requests respect the host site's config.
		$response = wp_remote_post($this->url['checkout_session_url'], [
			'headers'   => $headers,
			'body'      => wp_json_encode($data),
			'timeout'   => 120,
			'sslverify' => true,
		]);

		// Convert transport failures into exceptions so callers handle one path.
		if (is_wp_error($response)) {
			throw new \RuntimeException('Monime network error: ' . $response->get_error_message());
		}
		$status_code = wp_remote_retrieve_response_code($response);
		$body        = wp_remote_retrieve_body($response);

		// Surface Monime API error messages when the HTTP status is not success.
		if ($status_code !== 200 && $status_code !== 201) {
			$error = json_decode($body, true) ?? [];
			$msg = $error['message'] ?? $error['error'] ?? $error['details'] ?? "HTTP {$status_code}";
			if (is_array($msg)) $msg = json_encode($msg);
			throw new \RuntimeException($msg, $status_code);
		}

		// Validate JSON before callers attempt to read the Monime result payload.
		$parsed = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
		}

		$result = $parsed['result'] ?? $parsed;
		$redirectUrl = $result['redirectUrl'] ?? null;
		// A checkout session is unusable without the hosted checkout URL.
		if (empty($redirectUrl)) {
			throw new \RuntimeException('Invalid Redirect Url. Response: ' . json_encode($parsed));
		}

		return $result;
	}
}
