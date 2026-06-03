<?php

namespace Monime\contracts;

use Monime\core\CreateDonationRequest;

/**
 * Contract for platform adapters that can create Monime checkout sessions.
 *
 * Each integration converts its own order/donation data into the shared
 * CreateDonationRequest DTO consumed by MonimeClient.
 */
interface PaymentAdapterInterface
{
	/**
	 * Unique adapter ID stored in checkout metadata and used for webhook routing.
	 */
	public function getAdapterId(): string;

	/**
	 * Convert platform-specific payload data into a Monime checkout request.
	 */
	public function buildPaymentPayload(array $data): CreateDonationRequest;
}
