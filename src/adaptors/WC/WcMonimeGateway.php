<?php

use adaptors\Wc;
use Monime\contracts\MonimePaymentAdapterInterface;
use Monime\contracts\MonimeWebhookAdapterInterface;
use Monime\core\CreateMonimePayload;
use Monime\core\Env;
use Monime\services\PaymentService;

/**
 * WooCommerce payment gateway implementation for Monime hosted checkout.
 *
 * This class both registers the checkout gateway with WooCommerce and acts as
 * the Monime adapter used by PaymentService and the webhook dispatcher.
 */
class WcMonimeGateway extends WC_Payment_Gateway implements MonimePaymentAdapterInterface, MonimeWebhookAdapterInterface
{
    /** Cached provider icon data so icon directories are not scanned repeatedly. */
    private $provider_icon_cache = null;

    /** Monime API token loaded from plugin settings. */
    public string $api_token = '';

    /** Monime space ID loaded from plugin settings. */
    public string $space_id = '';

    //public string $title = '';
    //public string $description = '';
    //public string $enabled = '';
    /**
     * Configure WooCommerce gateway properties, settings, and runtime hooks.
     */
    public function __construct()
    {
        $this->id = 'monime';
        $this->icon = plugins_url('./../../../assets/images/monime_icon.png', __FILE__);
        $this->has_fields = true;
        $this->method_title = 'Monime';
        $this->method_description = 'Hosted checkout via Monime (Cards, Mobile Money, Bank Transfer & Digital Wallet).';
        $this->supports = array(
            'products',
            'refunds',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $env = Env::get();
        $this->api_token = $env['monime_token'];
        $this->space_id = $env['monime_space_id'];

        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Add order meta box for debugging
        add_action('add_meta_boxes', array($this, 'add_monime_meta_box'));

        // Support for Blocks checkout

        add_action(
            'woocommerce_rest_checkout_process_payment_with_context',
            array($this, 'process_blocks_payment'),
            10,
            2,
        );
    }

    // Monime function Implementation
    /**
     * Convert a decimal amount to minor units expected by Monime.
     */
    public function monime_convert_to_minor_unit($amount)
    {
        // Convert to minor unit (cents) - multiply by 100
        return intval(floatval($amount) * 100);
    }

    // Logger function
    /**
     * Write a message to the WooCommerce logger under the Monime source.
     */
    function monime_log($message, $level = 'info')
    {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'monime-woocommerce'));
        }
    }

    // Clear cart helper to ensure we only empty once per order
    /**
     * Adapter ID used for Monime registry lookup and webhook routing.
     */
    public function getAdapterId(): string
    {
        return 'WC';
    }

    /**
     * Convert WooCommerce checkout data into the shared Monime request DTO.
     */
    public function buildPaymentPayload(array $data): CreateMonimePayload
    {
        return new CreateMonimePayload(is_array($data['lineItems'] ?? null) ? $data['lineItems'] : [],

        (string) $data['idempotency_key'], (string) $data['reference'], (string) $data['name'], (string) $data['description'], (string) $data['cancel_url'], (string) $data['success_url'],

        (string) ($data['financialAccountId'] ?? ''), (string) ($data['currency'] ?? 'SLE'),

        is_array($data['paymentOptions'] ?? null) ? $data['paymentOptions'] : [],

        is_array($data['metadata'] ?? null) ? $data['metadata'] : [],

        (string) ($data['callbackState'] ?? ''));
    } // Handle checkout completed webhook

    // Handle checkout completed webhook
    /**
     * Process a verified Monime checkout_session.completed webhook.
     */
    public function monime_handle_checkout_completed(array $payload)
    {
        if (!isset($payload['data']['id'])) {
            return new WP_REST_Response(array('error' => 'Missing session ID'), 400);
        }

        $session_id = $payload['data']['id'];
        $reference = $payload['data']['reference'] ?? '';
        $order_id = $payload['data']['metadata']['order_id'] ?? null;

        $order = null;

        // First try using stored WooCommerce order ID
        if ($order_id) {
            $order = wc_get_order((int) $order_id);
        }

        // Fallback: lookup using session ID
        if (!$order) {
            $orders = wc_get_orders(array(
                'limit' => 1,
                'meta_key' => '_monime_session_id',
                'meta_value' => $session_id,
            ));

            if (!empty($orders)) {
                $order = $orders[0];
            }
        }

        // Fallback: use reference
        if (!$order && !empty($reference)) {
            $order = wc_get_order((int) $reference);
        }

        if (!$order) {
            $this->monime_log('Order not found for session: ' . $session_id, 'error');

            return new WP_REST_Response(array('error' => 'Order not found'), 404);
        }

        $this->monime_log('Processing completed webhook for order #' . $order->get_id());

        // Prevent duplicate processing
        if ($order->is_paid() || $order->get_meta('_monime_payment_processed') === 'yes') {
            $this->monime_log('Order #' . $order->get_id() . ' already processed');

            return new WP_REST_Response(array('message' => 'Already processed'), 200);
        }

        // Store Monime order number if available
        if (!empty($payload['data']['orderNumber'])) {
            $order->update_meta_data('_monime_order_number', $payload['data']['orderNumber']);
        }

        $order->payment_complete();

        $order->add_order_note('Payment completed via Monime webhook. Session ID: ' . $session_id);

        $order->update_meta_data('_monime_payment_processed', 'yes');

        $order->update_meta_data('_monime_session_status', $payload['data']['status'] ?? 'completed');

        $order->save();

        $this->monime_log('Payment completed for order #' . $order->get_id());

        return new WP_REST_Response(array('message' => 'Payment processed'), 200);
    }

    // Handle checkout expired webhook
    /**
     * Mark an unpaid WooCommerce order as failed when a Monime session expires.
     */
    public function monime_handle_checkout_expired(array $payload)
    {
        if (!isset($payload['data']['id'])) {
            return new WP_REST_Response(array('error' => 'Missing session ID'), 400);
        }

        $session_id = $payload['data']['id'];

        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_monime_session_id',
            'meta_value' => $session_id,
        ));

        if (empty($orders)) {
            return new WP_REST_Response(array('message' => 'Order not found'), 404);
        }

        $order = $orders[0];

        if (!$order->is_paid()) {
            $order->update_status('failed', 'Monime checkout session expired.');

            $order->update_meta_data('_monime_session_status', 'expired');

            $order->save();

            $this->monime_log('Order #' . $order->get_id() . ' marked as failed');
        }

        return new WP_REST_Response(array('message' => 'Expiration processed'), 200);
    }

    // Handle checkout cancelled webhook
    /**
     * Mark an unpaid WooCommerce order as cancelled when Monime reports cancel.
     */
    public function monime_handle_checkout_cancelled(array $payload)
    {
        if (!isset($payload['data']['id'])) {
            return new WP_REST_Response(array('error' => 'Missing session ID'), 400);
        }

        $session_id = $payload['data']['id'];

        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_monime_session_id',
            'meta_value' => $session_id,
        ));

        if (empty($orders)) {
            return new WP_REST_Response(array('message' => 'Order not found'), 404);
        }

        $order = $orders[0];

        if (!$order->is_paid()) {
            $order->update_status('cancelled', 'Payment cancelled by customer via Monime.');

            $order->update_meta_data('_monime_session_status', 'cancelled');

            $order->save();

            $this->monime_log('Order #' . $order->get_id() . ' cancelled');
        }

        return new WP_REST_Response(array('message' => 'Cancellation processed'), 200);
    }

    // Main webhook handler
    /**
     * Dispatch verified Monime webhook events to WooCommerce-specific handlers.
     */
    public function handleWebhook(array $payload): void
    {
        $event_type = $payload['event']['name'] ?? '';
        if (empty($event_type)) {
            $this->monime_log('Missing webhook event name', 'error');

            return;
        }

        switch ($event_type) {
            case 'checkout_session.completed':
                $this->monime_handle_checkout_completed($payload);
                break;

            case 'checkout_session.expired':
                $this->monime_handle_checkout_expired($payload);
                break;

            case 'checkout_session.cancelled':
                $this->monime_handle_checkout_cancelled($payload);
                break;

            default:
                $this->monime_log('Unhandled webhook event: ' . $event_type);
                break;
        }
    } ///

    // Initialize form fields for settings

    /**
     * Determine whether the gateway can appear at checkout.
     */
    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (empty($this->api_token) || empty($this->space_id)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Define WooCommerce admin settings for the Monime payment gateway.
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Monime Checkout',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title that customers see during checkout.',
                'default' => 'Monime',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description that customers see during checkout.',
                'default' => 'Hosted Monime checkout with Cards, Mobile Money, Bank Transfer, or Digital Wallet.',
            ),
            'payment_options_heading' => array(
                'title' => 'Payment Options Configuration',
                'type' => 'title',
                'description' => 'Configure which payment methods and providers are available to customers.',
            ),
            'card_disable' => array(
                'title' => 'Disable Card Payments',
                'type' => 'checkbox',
                'label' => 'Disable card payment option',
                'default' => 'no',
                'description' => 'Check to disable card payments entirely.',
            ),
            'financial_account' => array(
                'title' => 'Financial Account ID',
                'type' => 'text',
                'description' => 'Your Monime Financial Account ID. This determines which account receives the payments. <strong>Leave blank</strong> to use your default account.',
                'default' => '',
                'placeholder' => 'e.g. fac_xxxxxxxxxxxx',
                'desc_tip' => true,
            ),
            'momo_disable' => array(
                'title' => 'Disable Mobile Money',
                'type' => 'checkbox',
                'label' => 'Disable mobile money payment option',
                'default' => 'no',
                'description' => 'Check to disable mobile money payments entirely.',
            ),
            'momo_enable_providers' => array(
                'title' => 'Mobile Money - Enabled Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of Mobile Money provider IDs (e.g., m17) that should be explicitly enabled for this session. Takes precedence over disabled providers.',
                'default' => '',
                'desc_tip' => true,
            ),
            'momo_disable_providers' => array(
                'title' => 'Mobile Money - Disabled Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of Mobile Money provider IDs to exclude for this session.',
                'default' => '',
                'desc_tip' => true,
            ),
            'bank_disable' => array(
                'title' => 'Disable Bank Transfers',
                'type' => 'checkbox',
                'label' => 'Disable bank transfer payment option',
                'default' => 'no',
                'description' => 'Check to disable bank transfer payments entirely.',
            ),
            'bank_enable_providers' => array(
                'title' => 'Bank - Enable Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of bank provider IDs to enable. Leave empty to enable all.',
                'default' => '',
                'desc_tip' => true,
            ),
            'bank_disable_providers' => array(
                'title' => 'Bank - Disable Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of bank provider IDs to disable.',
                'default' => '',
                'desc_tip' => true,
            ),
            'wallet_disable' => array(
                'title' => 'Disable Digital Wallets',
                'type' => 'checkbox',
                'label' => 'Disable digital wallet payment option',
                'default' => 'no',
                'description' => 'Check to disable digital wallet payments entirely.',
            ),
            'wallet_enable_providers' => array(
                'title' => 'Wallet - Enable Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of wallet provider IDs to enable. Leave empty to enable all.',
                'default' => '',
                'desc_tip' => true,
            ),
            'wallet_disable_providers' => array(
                'title' => 'Wallet - Disable Providers',
                'type' => 'text',
                'description' => 'Comma-separated list of wallet provider IDs to disable.',
                'default' => '',
                'desc_tip' => true,
            ),
        );
    }

    // Add meta box to order page
    /**
     * Add Monime payment metadata panels to classic and HPOS order screens.
     */
    public function add_monime_meta_box()
    {
        add_meta_box(
            'monime_payment_details',
            'Monime Payment Details',
            array($this, 'monime_meta_box_content'),
            'shop_order',
            'side',
            'default',
        );

        // Support for HPOS
        add_meta_box(
            'monime_payment_details',
            'Monime Payment Details',
            array($this, 'monime_meta_box_content'),
            'woocommerce_page_wc-orders',
            'side',
            'default',
        );
    }

    // Custom payment fields / description
    /**
     * Render Monime description and provider badges on classic checkout.
     */
    public function payment_fields()
    {
        if (!empty($this->description)) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        $badge_data = $this->get_provider_badge_data_internal();
        $badges = isset($badge_data['display']) ? $badge_data['display'] : array();
        $overflow = isset($badge_data['overflow']) ? $badge_data['overflow'] : array();

        ?>
		<style>
			.monime-provider-grid {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin-bottom: 12px;
				align-items: center;
			}

			.monime-provider-grid>span {
				border: 1px solid #e2e8f0;
				border-radius: 8px;
				padding: 6px;
				background: #fff;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.monime-provider-grid>span img {
				width: 36px;
				height: 36px;
				display: block;
			}

			.monime-provider-overflow {
				position: relative;
				display: inline-block;
			}

			.monime-provider-overflow-trigger {
				border: 1px solid #e2e8f0;
				border-radius: 8px;
				padding: 6px 10px;
				background: #fff;
				font-weight: 600;
				font-size: 13px;
				cursor: pointer;
				display: inline-block;
				min-width: 40px;
				text-align: center;
			}

			.monime-provider-overflow-popover {
				position: absolute;
				bottom: 100%;
				left: 50%;
				transform: translateX(-50%);
				margin-bottom: 8px;
				background: #111827;
				color: #fff;
				padding: 12px;
				border-radius: 8px;
				display: grid;
				grid-template-columns: repeat(4, 36px);
				gap: 8px;
				box-shadow: 0 8px 20px rgba(15, 23, 42, 0.35);
				opacity: 0;
				visibility: hidden;
				pointer-events: none;
				transition: opacity 0.2s ease, visibility 0.2s ease;
				z-index: 1000;
				white-space: nowrap;
			}

			.monime-provider-overflow-popover img {
				width: 36px;
				height: 36px;
				border-radius: 6px;
				background: #fff;
				padding: 4px;
				display: block;
			}

			.monime-provider-overflow:hover .monime-provider-overflow-popover {
				opacity: 1;
				visibility: visible;
				pointer-events: auto;
			}

			.monime-security-note {
				font-size: 13px;
				color: #475467;
				margin-top: 8px;
			}
		</style>
		<div class="monime-provider-grid">
			<?php foreach ($badges as $badge): ?>
				<span>
					<img src="<?php echo esc_url($badge['icon']); ?>" alt="<?php echo esc_attr($badge['label']); ?>" />
				</span>
			<?php endforeach; ?>
			<?php foreach ($overflow as $group): ?>
				<span class="monime-provider-overflow">
					<span class="monime-provider-overflow-trigger">+<?php echo intval($group['count']); ?></span>
					<div class="monime-provider-overflow-popover">
						<?php foreach ($group['items'] as $item): ?>
							<img src="<?php echo esc_url($item['icon']); ?>" alt="<?php echo esc_attr($item['label']); ?>" />
						<?php endforeach; ?>
					</div>
				</span>
			<?php endforeach; ?>
		</div>
		<p class="monime-security-note">You’ll be redirected to Monime to complete payment via HTTPS. Once you’re done, we’ll bring you back automatically.</p>
<?php
    }

    // Meta box content
    /**
     * Render Monime session details in the order admin meta box.
     */
    public function monime_meta_box_content($post_or_order)
    {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;

        if (!$order || $order->get_payment_method() !== 'monime') {
            echo '<p>Not a Monime payment</p>';
            return;
        }

        $session_id = $order->get_meta('_monime_session_id');
        $order_number = $order->get_meta('_monime_order_number');

        echo '<p><strong>Session ID:</strong><br>' . esc_html($session_id ?: 'N/A') . '</p>';
        echo '<p><strong>Monime Order #:</strong><br>' . esc_html($order_number ?: 'N/A') . '</p>';
    }

    // Build payment options array from settings
    /**
     * Build Monime paymentOptions from WooCommerce gateway settings.
     */
    private function build_payment_options()
    {
        $payment_options = array();

        // Card - only has disable option
        $card_disable = $this->get_option('card_disable', 'no') === 'yes';
        $payment_options['card'] = array('disable' => $card_disable);

        // Mobile Money
        $momo_disable = $this->get_option('momo_disable', 'no') === 'yes';
        $momo_config = array('disable' => $momo_disable);

        $momo_enable_providers = trim($this->get_option('momo_enable_providers', ''));
        if (!empty($momo_enable_providers)) {
            $providers = array_map('trim', explode(',', $momo_enable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $momo_config['enabledProviders'] = array_values($providers);
            }
        }

        $momo_disable_providers = trim($this->get_option('momo_disable_providers', ''));
        if (!empty($momo_disable_providers)) {
            $providers = array_map('trim', explode(',', $momo_disable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $momo_config['disabledProviders'] = array_values($providers);
            }
        }

        $payment_options['momo'] = $momo_config;

        // Bank
        $bank_disable = $this->get_option('bank_disable', 'no') === 'yes';
        $bank_config = array('disable' => $bank_disable);

        $bank_enable_providers = trim($this->get_option('bank_enable_providers', ''));
        if (!empty($bank_enable_providers)) {
            $providers = array_map('trim', explode(',', $bank_enable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $bank_config['enabledProviders'] = array_values($providers);
            }
        }

        $bank_disable_providers = trim($this->get_option('bank_disable_providers', ''));
        if (!empty($bank_disable_providers)) {
            $providers = array_map('trim', explode(',', $bank_disable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $bank_config['disabledProviders'] = array_values($providers);
            }
        }

        $payment_options['bank'] = $bank_config;

        // Wallet
        $wallet_disable = $this->get_option('wallet_disable', 'no') === 'yes';
        $wallet_config = array('disable' => $wallet_disable);

        $wallet_enable_providers = trim($this->get_option('wallet_enable_providers', ''));
        if (!empty($wallet_enable_providers)) {
            $providers = array_map('trim', explode(',', $wallet_enable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $wallet_config['enabledProviders'] = array_values($providers);
            }
        }

        $wallet_disable_providers = trim($this->get_option('wallet_disable_providers', ''));
        if (!empty($wallet_disable_providers)) {
            $providers = array_map('trim', explode(',', $wallet_disable_providers));
            $providers = array_filter($providers);
            if (!empty($providers)) {
                $wallet_config['disabledProviders'] = array_values($providers);
            }
        }

        $payment_options['wallet'] = $wallet_config;

        return $payment_options;
    }

    /**
     * Parse comma-separated provider IDs into lowercase provider keys.
     */
    private function parse_provider_list($value)
    {
        if (empty($value)) {
            return array();
        }
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts);
        return array_map('strtolower', $parts);
    }

    /**
     * Discover provider SVG icons grouped by Monime payment channel.
     */
    private function get_provider_icon_map()
    {
        if (null !== $this->provider_icon_cache) {
            return $this->provider_icon_cache;
        }

        $channels = array(
            'card' => 'cards',
            'momo' => 'momos',
            'bank' => 'banks',
            'wallet' => 'wallets',
        );

        $plugin_root = dirname(__DIR__, 3);

        $base_path = trailingslashit($plugin_root . '/assets/icons');

        $base_url = trailingslashit(plugins_url('assets/icons', $plugin_root . '/monime_gateway.php'));

        $map = array();

        foreach ($channels as $channel => $subdir) {
            // Each channel has its own icon folder under assets/icons.
            $full_path = trailingslashit($base_path . $subdir);

            if (!is_dir($full_path)) {
                continue;
            }

            $files = glob($full_path . '*.svg');

            foreach ($files as $file) {
                $id = strtolower(pathinfo($file, PATHINFO_FILENAME));

                $map[$channel][$id] = array(
                    'id' => $id,
                    'label' => strtoupper($id),
                    'icon' => trailingslashit($base_url . $subdir) . basename($file),
                );
            }
        }

        $this->provider_icon_cache = $map;

        return $this->provider_icon_cache;
    }

    /**
     * Return visible provider badges for one channel after settings filters.
     */
    private function get_provider_badges_for_channel($channel)
    {
        $icons = $this->get_provider_icon_map();
        $channel_icons = isset($icons[$channel]) ? $icons[$channel] : array();

        if (empty($channel_icons)) {
            return array();
        }

        if ($channel === 'card') {
            $disabled = $this->get_option('card_disable', 'no') === 'yes';
            return $disabled ? array() : array_values($channel_icons);
        }

        $disable_flag = $this->get_option($channel . '_disable', 'no') === 'yes';
        if ($disable_flag) {
            return array();
        }

        $enabled = $this->parse_provider_list($this->get_option($channel . '_enable_providers', ''));
        $disabled = $this->parse_provider_list($this->get_option($channel . '_disable_providers', ''));

        $selected = array();

        // Follow Monime principle: enabledProviders takes precedence over disabledProviders
        if (!empty($enabled)) {
            // If enabledProviders is set, only show those providers (even if they're in disabledProviders)
            foreach ($enabled as $id) {
                if (isset($channel_icons[$id])) {
                    $selected[$id] = $channel_icons[$id];
                }
            }
        } else {
            // If enabledProviders is not set, show all providers except those in disabledProviders
            $selected = $channel_icons;
            if (!empty($disabled)) {
                foreach ($disabled as $id) {
                    unset($selected[$id]);
                }
            }
        }

        return array_values($selected);
    }

    /**
     * Build display/overflow badge groups used by checkout and Blocks UI.
     */
    private function get_provider_badge_data_internal()
    {
        $channels = array('card', 'momo', 'bank', 'wallet');
        $limits = array(
            'card' => -1,
            'momo' => -1,
            'bank' => 2,
            'wallet' => 2,
        );
        // Priority IDs for banks - these should be shown first
        $bank_priority = array('slb003', 'slb004');

        $by_channel = array();
        $display = array();
        $all_overflow_items = array();

        foreach ($channels as $channel) {
            $badges = $this->get_provider_badges_for_channel($channel);
            $by_channel[$channel] = $badges;
            $limit = isset($limits[$channel]) ? $limits[$channel] : -1;

            $badges_with_channel = array_map(function ($badge) use ($channel) {
                $badge['channel'] = $channel;
                return $badge;
            }, $badges);

            // Special handling for bank channel - prioritize specific banks
            if ($channel === 'bank' && !empty($bank_priority)) {
                $priority_badges = array();
                $other_badges = array();

                foreach ($badges_with_channel as $badge) {
                    if (in_array($badge['id'], $bank_priority)) {
                        $priority_badges[] = $badge;
                    } else {
                        $other_badges[] = $badge;
                    }
                }

                // Sort priority badges by the order in $bank_priority
                usort($priority_badges, function ($a, $b) use ($bank_priority) {
                    $pos_a = array_search($a['id'], $bank_priority);
                    $pos_b = array_search($b['id'], $bank_priority);
                    return $pos_a - $pos_b;
                });

                // Merge: priority first, then others
                $badges_with_channel = array_merge($priority_badges, $other_badges);
            }

            if ($limit < 0 || count($badges_with_channel) <= $limit) {
                $display = array_merge($display, $badges_with_channel);
                continue;
            }

            $display = array_merge($display, array_slice($badges_with_channel, 0, $limit));
            $overflow_items = array_slice($badges_with_channel, $limit);
            if (!empty($overflow_items)) {
                $all_overflow_items = array_merge($all_overflow_items, $overflow_items);
            }
        }

        // Combine all overflow items into a single group
        $overflow = array();
        if (!empty($all_overflow_items)) {
            $overflow[] = array(
                'channel' => 'combined',
                'count' => count($all_overflow_items),
                'items' => array_values($all_overflow_items),
            );
        }

        $flat = $display;
        foreach ($overflow as $group) {
            $flat = array_merge($flat, $group['items']);
        }

        return array(
            'by_channel' => $by_channel,
            'display' => $display,
            'overflow' => $overflow,
            'flat' => $flat,
        );
    }

    /**
     * Public wrapper used by the Blocks integration.
     */
    public function get_provider_badge_data()
    {
        return $this->get_provider_badge_data_internal();
    }

    // Process the payment and return the result
    /**
     * Create a Monime hosted checkout session for classic WooCommerce checkout.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->monime_log('Invalid order ID: ' . $order_id, 'error');
            wc_add_notice('Invalid order. Please try again.', 'error');
            return array('result' => 'failure');
        }

        // Validate API credentials
        if (empty($this->api_token) || empty($this->space_id)) {
            $this->monime_log('Missing API credentials', 'error');
            wc_add_notice('Payment gateway not configured. Please contact support.', 'error');
            return array('result' => 'failure');
        }

        try {
            // Generate unique identifiers
            $callback_state = wp_generate_password(32, false);
            $idempotency_key = wp_generate_password(32, false);

            // Store callback state using HPOS-compatible method
            $order->update_meta_data('_monime_callback_state', $callback_state);
            $order->update_meta_data('_monime_idempotency_key', $idempotency_key);
            $order->save();

            // Build line items
            $line_items = $this->build_line_items($order);

            // Prepare API request
            $checkout_url = get_permalink(wc_get_page_id('checkout'));
            $total = (int) round($order->get_total() * 100);
            $data = array(
                'idempotency_key' => $idempotency_key,
                'name' => 'Order #' . $order->get_order_number(),
                'description' => sprintf(
                    'Payment for order #%s from %s',
                    $order->get_order_number(),
                    get_bloginfo('name'),
                ),
                'reference' => $order->get_order_number(),
                'callbackState' => $callback_state,
                'lineItems' => $line_items,
                'success_url' => add_query_arg(array(
                    'monime_callback' => 'success',
                    'order_id' => $order_id,
                    'key' => $order->get_order_key(),
                ), $checkout_url),
                'cancel_url' => add_query_arg(array(
                    'monime_callback' => 'cancel',
                    'order_id' => $order_id,
                    'key' => $order->get_order_key(),
                ), $checkout_url),
                'paymentOptions' => $this->build_payment_options(),
                'metadata' => array(
                    'order_id' => strval($order_id),
                    'customer_email' => $order->get_billing_email(),
                    'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'site_url' => get_site_url(),
                ),
            );
            if ($this->get_option('financial_account')) {
                $data['financialAccountId'] = $this->get_option('financial_account');
            }
            $response = PaymentService::create($this->getAdapterId(), $data);
            $order->update_meta_data('_monime_payment_options_snapshot', wp_json_encode($data['paymentOptions']));

            $this->monime_log(sprintf(
                'Monime WooCommerce: creating checkout session for order #%s with payment options %s',
                $order->get_order_number(),
                wp_json_encode($data['paymentOptions']),
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $session = isset($response['result']) && is_array($response['result']) ? $response['result'] : $response;

            if (empty($session['redirectUrl'])) {
                throw new Exception('No redirect URL in API response');
            }

            // Store session details
            if (!empty($session['id'])) {
                $order->update_meta_data('_monime_session_id', $session['id']);
            }
            if (!empty($session['status'])) {
                $order->update_meta_data('_monime_session_status', $session['status']);
            }
            $order->update_status('pending', 'Awaiting Monime payment');
            $order->save();
            $order->add_order_note(sprintf(
                'Monime hosted session created (ID: %s). Payment options snapshot stored.',
                $session['id'] ?? 'unknown',
            ));

            $this->monime_log(sprintf(
                'Checkout session created for order #%s - Session ID: %s',
                $order->get_order_number(),
                $session['id'] ?? 'unknown',
            ));

            // Return success with redirect URL
            return array(
                'result' => 'success',
                'redirect' => $session['redirectUrl'],
            );
        } catch (Exception $e) {
            $this->monime_log('Payment processing error: ' . $e->getMessage(), 'error');
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return array('result' => 'failure');
        }
    }

    // Build line items from order
    /**
     * Convert WooCommerce order items, shipping, fees, and taxes into Monime line items.
     */
    private function build_line_items($order)
    {
        $line_items = array();

        // Add products
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $item_total = $item->get_total();
            $quantity = $item->get_quantity();

            $unit_price = $quantity > 0 ? $item_total / $quantity : 0;

            $line_item = array(
                'type' => 'custom',
                'name' => $item->get_name(),
                'price' => array(
                    'currency' => 'SLE',
                    'value' => $this->monime_convert_to_minor_unit($unit_price),
                ),
                'quantity' => $quantity,
            );

            // Add description if available
            if ($product) {
                $description = $product->get_short_description();
                if (empty($description)) {
                    $description = $product->get_description();
                }
                if (!empty($description)) {
                    $line_item['description'] = substr(wp_strip_all_tags($description), 0, 100);
                }
            }

            $line_items[] = $line_item;
        }

        // Add shipping
        if ($order->get_shipping_total() > 0) {
            $line_items[] = array(
                'type' => 'custom',
                'name' => 'Shipping',
                'price' => array(
                    'currency' => 'SLE',
                    'value' => $this->monime_convert_to_minor_unit($order->get_shipping_total()),
                ),
                'quantity' => 1,
                'description' => $order->get_shipping_method(),
            );
        }

        // Add fees
        foreach ($order->get_fees() as $fee) {
            $line_items[] = array(
                'type' => 'custom',
                'name' => $fee->get_name(),
                'price' => array(
                    'currency' => 'SLE',
                    'value' => $this->monime_convert_to_minor_unit($fee->get_total()),
                ),
                'quantity' => 1,
            );
        }

        // Add taxes if needed (as single line item)
        if ($order->get_total_tax() > 0) {
            $line_items[] = array(
                'type' => 'custom',
                'name' => 'Tax',
                'price' => array(
                    'currency' => 'SLE',
                    'value' => $this->monime_convert_to_minor_unit($order->get_total_tax()),
                ),
                'quantity' => 1,
            );
        }

        return $line_items;
    }

    /**
     * Create a Monime hosted checkout session for WooCommerce Blocks checkout.
     */
    public function process_blocks_payment($context, &$payment_result)
    {
        // PaymentContext exposes properties via magic getter.
        $payment_method = isset($context->payment_method) ? $context->payment_method : '';

        // Only process if this is our payment method
        if ($payment_method !== $this->id) {
            return;
        }

        // Mark that we're handling this payment to prevent legacy handler from running
        // The legacy handler checks if status is already set, so we'll set it to 'pending' initially
        // if it's not already set, then update it based on the result
        $current_status = $payment_result->status;
        if (empty($current_status)) {
            $payment_result->set_status('pending');
            // Initialize payment_details as empty array to prevent merge errors
            $payment_result->set_payment_details(array());
        }

        $order = $context->get_order();

        if (!$order) {
            $payment_result->set_status('error');
            $payment_result->set_payment_details(array('errorMessage' => 'Invalid order.'));
            return;
        }

        // Validate API credentials
        if (empty($this->api_token) || empty($this->space_id)) {
            $this->monime_log('Missing API credentials', 'error');
            $payment_result->set_status('error');
            $payment_result->set_payment_details(array(
                'errorMessage' => 'Payment gateway not configured. Please contact support.',
            ));
            return;
        }

        try {
            // Generate unique identifiers
            $callback_state = wp_generate_password(32, false);
            $idempotency_key = wp_generate_password(32, false);

            // Store callback state using HPOS-compatible method
            $order->update_meta_data('_monime_callback_state', $callback_state);
            $order->update_meta_data('_monime_idempotency_key', $idempotency_key);
            $order->save();

            // Build line items
            $line_items = $this->build_line_items($order);

            // Prepare API request
            $checkout_url = get_permalink(wc_get_page_id('checkout'));

            $data = array(
                'idempotency_key' => $idempotency_key,
                'name' => 'Order #' . $order->get_order_number(),
                'description' => sprintf(
                    'Payment for order #%s from %s',
                    $order->get_order_number(),
                    get_bloginfo('name'),
                ),
                'reference' => $order->get_order_number(),
                'callbackState' => $callback_state,
                'lineItems' => $line_items,
                'success_url' => add_query_arg(array(
                    'monime_callback' => 'success',
                    'order_id' => $order->get_id(),
                    'key' => $order->get_order_key(),
                ), $checkout_url),
                'cancel_url' => add_query_arg(array(
                    'monime_callback' => 'cancel',
                    'order_id' => $order->get_id(),
                    'key' => $order->get_order_key(),
                ), $checkout_url),
                'paymentOptions' => $this->build_payment_options(),
                'metadata' => array(
                    'order_id' => strval($order->get_id()),
                    'customer_email' => $order->get_billing_email(),
                    'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'site_url' => get_site_url(),
                ),
            );

            $order->update_meta_data('_monime_payment_options_snapshot', wp_json_encode($data['paymentOptions']));

            $this->monime_log(sprintf(
                '[Blocks] Monime WooCommerce: creating checkout session for order #%s with options %s',
                $order->get_order_number(),
                wp_json_encode($data['paymentOptions']),
            ));

            $response = PaymentService::create($this->getAdapterId(), $data); //$response = $this->make_api_request('/v1/checkout-sessions', $data, $idempotency_key);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $session = isset($response['result']) && is_array($response['result']) ? $response['result'] : $response;

            if (empty($session['redirectUrl'])) {
                throw new Exception('No redirect URL in API response');
            }

            // Store session details
            if (!empty($session['id'])) {
                $order->update_meta_data('_monime_session_id', $session['id']);
            }
            if (!empty($session['status'])) {
                $order->update_meta_data('_monime_session_status', $session['status']);
            }
            $order->update_status('pending', 'Awaiting Monime payment');
            $order->save();
            $order->add_order_note(sprintf(
                'Monime hosted session created (ID: %s). Payment options snapshot stored.',
                $session['id'] ?? 'unknown',
            ));

            $this->monime_log(sprintf(
                'Checkout session created for order #%s - Session ID: %s',
                $order->get_order_number(),
                $session['id'] ?? 'unknown',
            ));

            // Set payment result for Blocks checkout
            $payment_result->set_status('success');
            $payment_result->set_redirect_url($session['redirectUrl']);
        } catch (Exception $e) {
            $this->monime_log('Payment processing error: ' . $e->getMessage(), 'error');
            $payment_result->set_status('error');
            $payment_result->set_payment_details(array('errorMessage' => $e->getMessage()));
        }
    }

    // Process refund
    /**
     * WooCommerce refund hook placeholder while Monime refunds are unsupported.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Invalid order ID');
        }

        // Monime API doesn't support refunds yet - placeholder for future
        return new WP_Error(
            'refund_not_supported',
            'Refunds are not yet supported. Please process manually through Monime dashboard.',
        );
    }
}
