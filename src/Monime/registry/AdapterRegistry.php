<?php

namespace Monime\registry;

use Monime\contracts\PaymentAdapterInterface;

/**
 * In-memory registry for Monime payment adapters.
 *
 * PaymentService and the webhook endpoint use this registry to find the
 * correct integration by adapter ID.
 */
class AdapterRegistry
{

	/** @var array<string, PaymentAdapterInterface> */
	private static $adapters = [];

	/**
	 * Retrieve a registered adapter by its ID.
	 */
	public static function get(string $adapterId): ?PaymentAdapterInterface
	{
		return self::$adapters[$adapterId] ?? null;
	}

	/**
	 * Register an adapter using its own getAdapterId() value as the key.
	 */
	public static function registerAdapter(PaymentAdapterInterface $paymentAdapter): void
	{
		self::$adapters[$paymentAdapter->getAdapterId()] = $paymentAdapter;
	}

	/**
	 * Return all registered adapters.
	 */
	public static function getAll(): array
	{
		return self::$adapters;
	}
}
