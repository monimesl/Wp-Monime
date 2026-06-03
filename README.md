# Monime Gateway

Monime Gateway is a WordPress payment gateway plugin for accepting payments through Monime. It includes adapters for GiveWP and WooCommerce, and it can also be extended by custom plugins through the Monime adapter interfaces.

## Requirements

- WordPress installed and running.
- A Monime account with:
  - Monime API token.
  - Monime space ID.
  - Monime webhook secret.
- GiveWP, if you want to use Monime for donations.
- WooCommerce, if you want to use Monime for store checkout.

## Installation

1. Download the plugin ZIP file or clone the repository.

   ```bash
   git clone <repository-url> MonimeGateway
   ```

2. In WordPress admin, go to `Plugins`.

3. Select MonimeGateway/build/MonimeGateway.zip

4. Activate `Monime Gateway`.

5. If you are using GiveWP, install and activate GiveWP.

6. If you are using WooCommerce, install and activate WooCommerce.

## Main Monime Settings

After activating the plugin, open the Monime settings page in WordPress admin.

Fill in the following values:

- `Monime Token`: your Monime API token.
- `Space ID`: your Monime space ID.
- `Webhook Secret`: the secret used to verify incoming Monime webhooks.

Save the settings after entering your credentials.

## GiveWP Setup

1. Install and activate GiveWP.

2. Go to the GiveWP payment gateway settings.

3. Open the Monime gateway settings section.

4. Enable Monime as a donation payment gateway.

5. Configure the Monime gateway options for GiveWP:

   - Gateway title.
   - Gateway description.
   - Financial account ID, if you want donations to settle into a specific Monime financial account.
   - Enable or disable card payments.
   - Enable or disable mobile money.
   - Enable or disable bank transfers.
   - Enable or disable digital wallets.
   - Add allowed or disabled provider IDs where needed.

6. Save the GiveWP settings.

Donors will be redirected to Monime checkout when they choose Monime during donation checkout.

## WooCommerce Setup

1. Install and activate WooCommerce.

2. Go to `WooCommerce > Settings > Payments`.

3. Find `Monime Checkout`.

4. Enable Monime.

5. Open the Monime payment method settings.

6. Configure the WooCommerce Monime options:

   - Gateway title.
   - Gateway description.
   - Financial account ID, if you want payments to settle into a specific Monime financial account.
   - Enable or disable card payments.
   - Enable or disable mobile money.
   - Enable or disable bank transfers.
   - Enable or disable digital wallets.
   - Add allowed or disabled provider IDs where needed.

7. Save the WooCommerce payment settings.

Customers will be redirected to Monime checkout when they choose Monime during checkout.

## Webhooks

Monime sends webhook events back to the plugin so WordPress can update donations and orders.

Use this webhook endpoint in your Monime dashboard:

```text
https://your-site.com/wp-json/monime/v1/webhook
```

The plugin verifies incoming webhook signatures using the webhook secret saved in the main Monime settings page.

## Payment Options

GiveWP and WooCommerce each have their own Monime settings. This lets you configure payment behavior separately for donations and store checkout.

Available options may include:

- Disable card payments.
- Disable mobile money.
- Disable bank transfers.
- Disable digital wallets.
- Enable only specific providers.
- Disable specific providers.
- Set a financial account ID.
- Set the gateway name shown to users.
- Set the gateway description shown to users.

Provider IDs should be entered as comma-separated values where the settings field asks for provider IDs.

## Custom Plugin Integration

Custom plugins can integrate with Monime by creating their own adapter.

Your adapter should implement the Monime payment adapter interface:

```php
use Monime\contracts\PaymentAdapterInterface;
use Monime\core\CreateDonationRequest;

class CustomMonimeAdapter implements PaymentAdapterInterface
{
	public function getAdapterId(): string
	{
		return 'custom_adapter';
	}

	public function buildPaymentPayload(array $data): CreateDonationRequest
	{
		return new CreateDonationRequest(
			items: $data['lineItems'],
			idempotency_key: $data['idempotency_key'],
			reference: $data['reference'],
			name: $data['name'],
			description: $data['description'],
			cancelurl: $data['cancel_url'],
			success_url: $data['success_url'],
			financialAccountId: $data['financialAccountId'] ?? '',
			currency: $data['currency'] ?? 'SLE',
			paymentOptions: $data['paymentOptions'] ?? [],
			metadata: $data['metadata'] ?? [],
			callbackState: $data['callbackState'] ?? ''
		);
	}
}
```

If your custom plugin needs to process Monime webhooks, also implement the webhook adapter interface. A complete adapter that implements both interfaces should include all required methods:

```php
use Monime\contracts\PaymentAdapterInterface;
use Monime\core\WebhookAdapterInterface;
use Monime\core\CreateDonationRequest;

class CustomMonimeAdapter implements PaymentAdapterInterface, WebhookAdapterInterface
{
	public function getAdapterId(): string
	{
		return 'custom_adapter';
	}

	public function buildPaymentPayload(array $data): CreateDonationRequest
	{
		return new CreateDonationRequest(
			items: $data['lineItems'],
			idempotency_key: $data['idempotency_key'],
			reference: $data['reference'],
			name: $data['name'],
			description: $data['description'],
			cancelurl: $data['cancel_url'],
			success_url: $data['success_url'],
			financialAccountId: $data['financialAccountId'] ?? '',
			currency: $data['currency'] ?? 'SLE',
			paymentOptions: $data['paymentOptions'] ?? [],
			metadata: $data['metadata'] ?? [],
			callbackState: $data['callbackState'] ?? ''
		);
	}

	public function handleWebhook(array $payload): void
	{
		// Update your custom order, donation, invoice, or payment record here.
	}
}
```

After creating the adapter, register it with the Monime adapter registry:

```php
use Monime\registry\AdapterRegistry;

add_action('monime_register_adapters', function () {
	AdapterRegistry::registerAdapter(new CustomMonimeAdapter());
});
```

Then make sure your adapter file is loaded and booted from the main plugin file or from your custom plugin before Monime payments are created.

## Notes

- The main Monime settings store shared credentials.
- GiveWP and WooCommerce settings control how Monime appears and behaves inside each platform.
- Webhooks must be configured correctly for reliable payment status updates.
- Keep your Monime token and webhook secret private.
- Enable the webhook to ensure that certain events are processed
