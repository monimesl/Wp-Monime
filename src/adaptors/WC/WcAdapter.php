<?php

use adaptors\Wc;
use Monime\core\Env;
use Monime\registry\AdapterRegistry;

/**
 * Write WooCommerce-specific Monime log messages when WooCommerce logging exists.
 */




/**
 * Boots the WooCommerce integration and registers gateway-related hooks.
 */
class WcAdapter
{
	/**
	 * Attach WooCommerce hooks for gateway registration, Blocks support, redirects,
	 * admin notices, and thank-you page content.
	 */
	public static function boot(): void
	{
		\Monime\core\monime_log('info', 'WooCommerce adapter boot started');

		add_action('monime_register_adapters', function () {

			\Monime\core\monime_log('info', 'Registering WooCommerce Monime adapter');
			AdapterRegistry::registerAdapter(
				new WcMonimeGateway()
			);
		});


		add_filter('woocommerce_payment_gateways', 'add_monime_payment_gateway');

		/**
		 * Add the Monime gateway class to WooCommerce's available gateways.
		 */
		function add_monime_payment_gateway($gateways)
		{
			$gateways[] = 'WcMonimeGateway';
			return $gateways;
		}

		// Register Blocks support
		add_action('woocommerce_blocks_loaded', 'monime_checkout_blocks_support');

		/**
		 * Register Monime support for WooCommerce Blocks checkout.
		 */
		function monime_checkout_blocks_support()
		{
			if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
				return;
			}

			// Ensure WooCommerce is loaded
			if (!function_exists('WC')) {
				return;
			}

			require_once __DIR__ . '/WC_Monime_Blocks_Support.php';

			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$blocks_support = new WC_Monime_Blocks_Support();
					$blocks_support->initialize();
					$payment_method_registry->register($blocks_support);
				},
				10
			);
		}
		// Handle callback redirects (backup to webhooks)
		add_action('template_redirect', 'monime_handle_callback_redirect');

		/**
		 * Empty the cart once after a Monime order is marked paid.
		 */
		function monime_clear_cart_for_order($order)
		{
			if (!$order || !is_a($order, 'WC_Order')) {
				return;
			}

			if ($order->get_meta('_monime_cart_cleared') === 'yes') {
				return;
			}

			if (function_exists('WC') && WC()->cart) {
				WC()->cart->empty_cart();
			}

			$order->update_meta_data('_monime_cart_cleared', 'yes');
			$order->save();
		}


		/**
		 * Handle success/cancel redirect callbacks from hosted Monime checkout.
		 */
		function monime_handle_callback_redirect()
		{
			if (!isset($_GET['monime_callback'])) {
				return;
			}

			\Monime\core\monime_log('info', 'WooCommerce callback redirect received', [
				'callback' => sanitize_text_field((string) $_GET['monime_callback']),
			]);

			if (!isset($_GET['order_id']) || !isset($_GET['key'])) {
				return;
			}

			$order_id = intval($_GET['order_id']);
			$order_key = sanitize_text_field($_GET['key']);
			$order = wc_get_order($order_id);

			if (!$order || $order->get_order_key() !== $order_key) {
				wp_redirect(wc_get_page_permalink('shop'));
				exit;
			}

			if ($_GET['monime_callback'] === 'success') {
				\Monime\core\monime_log('info', 'WooCommerce success callback processing', [
					'order_id' => $order_id,
				]);
				// Payment successful - mark as complete if not already
				if (!$order->is_paid() && $order->get_meta('_monime_payment_processed') !== 'yes') {
					$order->payment_complete();
					$order->add_order_note('Payment completed via redirect callback.');
					$order->update_meta_data('_monime_payment_processed', 'yes');
					$order->save();
					monime_clear_cart_for_order($order);
				}

				wp_redirect($order->get_checkout_order_received_url());
				exit;
			} elseif ($_GET['monime_callback'] === 'cancel') {
				\Monime\core\monime_log('info', 'WooCommerce cancel callback processing', [
					'order_id' => $order_id,
				]);
				// Payment cancelled
				if (!$order->is_paid()) {
					$order->update_status('cancelled', 'Payment cancelled by customer.');
					$order->save();
				}

				wc_add_notice('Payment was cancelled. You can try again or choose a different payment method.', 'notice');
				wp_redirect(wc_get_checkout_url());
				exit;
			}
		}

		// Add admin notice if API credentials are missing
		add_action('admin_notices', 'monime_admin_notices');

		/**
		 * Show a warning in wp-admin when required Monime credentials are absent.
		 */
		function monime_admin_notices()
		{
			if (!current_user_can('manage_options')) {
				return;
			}
			$env = Env::get();
			$api_token = $env['monime_token'];
			$space_id = $env['monime_space_id'];

			if (empty($api_token) || empty($space_id)) {
				\Monime\core\monime_log('error', 'WooCommerce credentials missing for admin notice');
?>
				<div class="notice notice-warning">
					<p>
						<strong>MonimeGateway:</strong> Please configure your API credentials in the
						<a href="<?php echo admin_url('admin.php?page=monime-settings'); ?>">settings page</a>.
					</p>
				</div>
<?php
			}
		}

		// Add custom thank you page content
		add_action('woocommerce_thankyou', 'monime_thank_you_page', 10, 1);

		/**
		 * Display Monime transaction details on the WooCommerce thank-you page.
		 */
		function monime_thank_you_page($order_id)
		{
			$order = wc_get_order($order_id);

			if (!$order || $order->get_payment_method() !== 'monime') {
				return;
			}

			echo '<div class="monime-thank-you" style="margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;">';
			echo '<h2 style="margin-top: 0;">Thank You for Your Payment!</h2>';
			echo '<p>Your payment has been processed successfully through Monime.</p>';

			$monime_order_number = $order->get_meta('_monime_order_number');
			if ($monime_order_number) {
				echo '<p><strong>Monime Transaction ID:</strong> ' . esc_html($monime_order_number) . '</p>';
			}

			echo '</div>';
		}
	}
}
