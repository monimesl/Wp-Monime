<?php

namespace Monime\registry;

use Monime\contracts\MonimePaymentAdapterInterface;

/**
 * In-memory registry for Monime payment adapters.
 *
 * PaymentService and the webhook endpoint use this registry to find the
 * correct integration by adapter ID.
 */
class AdapterRegistry
{
    /** @var array<string, MonimePaymentAdapterInterface> */
    private static $adapters = [];

    /**
     * Retrieve a registered adapter by its ID.
     */
    public static function get(string $adapterId): ?MonimePaymentAdapterInterface
    {
        $adapter = self::$adapters[$adapterId] ?? null;

        \Monime\core\monime_log('info', $adapter ? 'Adapter registry hit' : 'Adapter registry miss', [
            'adapter_id' => $adapterId,
        ]);

        return $adapter;
    }

    /**
     * Register an adapter using its own getAdapterId() value as the key.
     */
    public static function registerAdapter(MonimePaymentAdapterInterface $paymentAdapter): void
    {
        $adapterId = $paymentAdapter->getAdapterId();
        $overwritten = isset(self::$adapters[$adapterId]);

        self::$adapters[$adapterId] = $paymentAdapter;

        \Monime\core\monime_log('info', $overwritten ? 'Adapter overwritten' : 'Adapter registered', [
            'adapter_id' => $adapterId,
            'registered_count' => count(self::$adapters),
        ]);
    }

    /**
     * Return all registered adapters.
     */
    public static function getAll(): array
    {
        return self::$adapters;
    }
}
