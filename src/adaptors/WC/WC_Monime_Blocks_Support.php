<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class WC_Monime_Blocks_Support extends AbstractPaymentMethodType
{
	protected $name = 'monime';
	private $gateway;

	public function initialize()
	{
		\Monime\core\monime_log('info', 'WooCommerce Blocks support initialized');
		$this->settings = get_option('woocommerce_monime_settings', []);

		if (class_exists('WcMonimeGateway')) {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			$this->gateway = $gateways['monime'] ?? null;
		}
	}

	public function is_active()
	{
		if (empty($this->settings)) $this->initialize();

		$enabled = !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
		\Monime\core\monime_log('info', 'WooCommerce Blocks active state evaluated', [
			'enabled' => $enabled,
		]);
		return $enabled && ($this->gateway ? $this->gateway->is_available() : true);
	}

	public function get_payment_method_script_handles()
	{
		wp_register_script(
			'wc-monime-blocks',
			plugins_url('assets/js/monime_wc.js', dirname(__DIR__, 2)), // Adjust path if needed
			['wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wc-settings'],
			'2.1.0',
			true
		);

		return ['wc-monime-blocks'];
	}

	public function get_payment_method_data()
	{
		$badge_data = $this->gateway ? $this->gateway->get_provider_badge_data() : [];

		// Build HTML for provider badges (reuse your existing logic)
		$html = $this->build_provider_badges_html($badge_data);

		return [
			'title'       => $this->get_setting('title', 'Monime Checkout'),
			'description' => $this->get_setting('description', 'Pay with Cards, Mobile Money, Bank Transfer & Wallets'),
			'icon'        => $this->gateway ? $this->gateway->icon : '',
			'html'        => $html,
			'supports'    => $this->gateway ? $this->gateway->supports : [],
		];
	}

	/**
	 * Build provider badges HTML for Blocks
	 */
	private function build_provider_badges_html($badge_data)
	{
		if (empty($badge_data['display']) && empty($badge_data['overflow'])) {
			return '';
		}

		$html = '<div class="monime-provider-grid" style="display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; align-items:center;">';

		// Display badges
		foreach ($badge_data['display'] as $badge) {
			$html .= sprintf(
				'<span style="border:1px solid #e2e8f0; border-radius:8px; padding:6px; background:#fff;">
                <img src="%s" alt="%s" style="width:36px;height:36px;display:block;" />
            </span>',
				esc_url($badge['icon']),
				esc_attr($badge['label'])
			);
		}

		// Overflow with hover popover
		if (!empty($badge_data['overflow'])) {
			foreach ($badge_data['overflow'] as $group) {
				$popover_items = '';
				foreach ($group['items'] as $item) {
					$popover_items .= sprintf(
						'<img src="%s" alt="%s" style="width:36px;height:36px;border-radius:6px;background:#fff;padding:4px;" />',
						esc_url($item['icon']),
						esc_attr($item['label'])
					);
				}

				$html .= '<span class="monime-overflow-container" style="position:relative; display:inline-block;">';
				$html .= sprintf(
					'<span class="monime-overflow-trigger" style="border:1px solid #e2e8f0; border-radius:8px; padding:6px 10px; background:#fff; font-weight:600; font-size:13px; cursor:pointer;">+%d</span>',
					count($group['items'])
				);
				$html .= sprintf(
					'<div class="monime-overflow-popover" style="position:absolute; bottom:100%%; left:50%%; transform:translateX(-50%%); margin-bottom:8px; background:#111827; color:#fff; padding:12px; border-radius:8px; display:grid; grid-template-columns:repeat(4,36px); gap:8px; box-shadow:0 8px 20px rgba(15,23,42,0.35); opacity:0; visibility:hidden; pointer-events:none; transition:opacity 0.2s ease, visibility 0.2s ease; z-index:1000;">%s</div>',
					$popover_items
				);
				$html .= '</span>';
			}
		}

		$html .= '</div>';

		return $html;
	}
}
