<?php

namespace Monime\contracts;

/**
 * Contract for adapters that can process verified Monime webhook payloads.
 *
 * Webhook::handle verifies the signature first, then dispatches the decoded
 * payload to the adapter named in the checkout metadata.
 */
interface MonimeWebhookAdapterInterface
{
    /**
     * Handle a verified Monime webhook payload for the adapter's platform.
     */
    public function handleWebhook(array $payload): void;
}
