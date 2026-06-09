<?php

namespace Monime\core;

/**
 * Data transfer object for a Monime checkout-session creation request.
 *
 * Adapters normalize WooCommerce/GiveWP-specific payment data into this shape
 * before the Monime client serializes it for the API.
 */
final class CreateMonimePayload
{
    /** Checkout line items in the Monime API format. */
    public array $items;
    /** Idempotency key sent to Monime to avoid duplicate session creation. */
    public string $idempotency_key;

    /** Merchant-side reference used to connect Monime events back to local records. */
    public string $reference;
    /** Checkout display name. */
    public string $name;
    /** Checkout description shown to the payer. */
    public string $description;
    /** URL Monime redirects to when the payer cancels. */
    public string $cancelurl;
    /** URL Monime redirects to after successful checkout completion. */
    public string $success_url;
    /** Optional Monime financial account destination. */
    public string $financialAccountId = '';
    /** Currency code for the request; defaults to SLE. */
    public string $currency = 'SLE';
    /** Optional per-session payment channel/provider controls. */
    public array $paymentOptions = [];
    /** Extra adapter metadata passed through Monime and returned in webhooks. */
    public array $metadata = [];
    /** Optional state value that can be used to correlate callbacks. */
    public string $callbackState = '';

    public function __construct(
        array $items,
        string $idempotency_key,
        string $reference,
        string $name,
        string $description,
        string $cancelurl,
        string $success_url,
        string $financialAccountId = '',
        string $currency = 'SLE',
        array $paymentOptions = [],
        array $metadata = [],
        string $callbackState = '',
    ) {
        $this->items = $items;
        $this->idempotency_key = $idempotency_key;
        $this->reference = $reference;
        $this->name = $name;
        $this->description = $description;
        $this->cancelurl = $cancelurl;
        $this->success_url = $success_url;
        $this->financialAccountId = $financialAccountId;
        $this->currency = $currency;
        $this->paymentOptions = $paymentOptions;
        $this->metadata = $metadata;
        $this->callbackState = $callbackState;
    }
}
