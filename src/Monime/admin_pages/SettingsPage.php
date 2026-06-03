<?php

namespace Monime\admin_pages;

if (!defined('ABSPATH')) exit;

use Monime\core\Env;

/**
 * REGISTER SETTINGS - Extra Safe
 *
 * Registers plugin-level Monime options with WordPress and sanitizes every
 * saved value before it reaches the database.
 */
add_action('admin_init', function () {
	$group = 'monime_settings_group';

	// Each option maps to the sanitizer that fits the expected value type.
	$fields = [
		'monime_token'                => 'sanitize_text_field',
		'monime_space_id'             => 'sanitize_text_field',
		'webhook_secret'              => 'sanitize_text_field',
		'monime_financial_account_id' => 'sanitize_text_field',
		'monime_logo'                 => 'esc_url_raw',
		'success_url'                 => 'esc_url_raw',
		'cancel_url'                  => 'esc_url_raw',
	];

	foreach ($fields as $key => $sanitize) {

		register_setting($group, $key, [
			'type' => 'string',
			'default' => '',

			'sanitize_callback' => function ($value) use ($sanitize, $key) {
				$current_value = (string) get_option($key, '');

				$value = is_scalar($value)
					? (string) $value
					: '';

				$value = call_user_func($sanitize, $value);

				$value = trim($value);

				if ($value === '') {
					delete_option($key);
					return '';
				}

				return $value;
			},
		]);
	}
});
/**
 * SETTINGS PAGE
 *
 * Renders the Monime plugin settings form used to store shared credentials.
 */
function render_settings_page()
{
	$env = Env::get();

	// Extra safety - force string values
	$space_id       = (string) ($env['monime_space_id'] ?? '');
	$monime_token = (string) ($env['monime_token']);
	$webhook_secret = (string) ($env['webhook_secret']);
?>
	<!-- WordPress Settings API form for saving Monime credentials. -->
	<div class="wrap monime-wrap">

		<form id="monime-settings-form" method="post" action="options.php">
			<?php settings_fields('monime_settings_group'); ?>

			<!-- Top Bar -->
			<div class="monime-top-bar">
				<div class="monime-title">
					<img src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../../../assets/images/monime_icon.png'); ?>"
						alt="Monime"
						class="monime-icon">
					<h2>Monime</h2>
				</div>
				<button type="submit" class="button button-primary button-large">
					Save Settings
				</button>
			</div>

			<!-- Main Card -->
			<div class="monime-card">
				<div class="monime-body">

					<!-- API Configuration -->
					<div class="section">
						<h2>API Configuration</h2>
						<div class="form-field">
							<label>Monime Token</label><input
								type="text"
								name="monime_token"
								value="<?php echo esc_attr($monime_token); ?>"
								spellcheck="false"
								autocomplete="off"
								autocorrect="off"
								autocapitalize="off"
								data-lpignore="true"
								placeholder="mn_••••••••••••••••">
						</div>
						<div class="form-field">
							<label>Space ID</label>
							<input
								type="text"
								name="monime_space_id"
								value="<?php echo esc_attr($space_id); ?>"
								spellcheck="false"
								autocomplete="off"
								autocorrect="off"
								autocapitalize="off"
								data-lpignore="true"
								placeholder="sp_••••••••••••••••">
						</div>

					</div>

					<!-- Webhook -->
					<div class="section">
						<h2>Webhook Security</h2>
						<?php
						$webhook_url = rest_url('monime/v1/webhook');
						?>

						<div class="section">

							<div class="form-field">

								<label for="monime_webhook_url">Webhook URL</label>

								<p class="description">
									Copy this URL and paste it into your Monime dashboard.
								</p>

								<div style="display:flex; gap:8px; align-items:center;">

									<input type="text"
										id="monime_webhook_url"
										value="<?php echo esc_url($webhook_url); ?>"
										readonly
										style="width:100%;">

									<button type="button" onclick="copyWebhookUrl()">
										Copy
									</button>

								</div>

							</div>

						</div>
						<div class="form-field">
							<label>Webhook Secret</label>
							<p class="description">Leave blank to clear the saved secret.</p>
							<input
								type="text"
								name="webhook_secret"
								value="<?php echo esc_attr($webhook_secret); ?>"
								spellcheck="false"
								autocomplete="off"
								autocorrect="off"
								autocapitalize="off"
								data-lpignore="true">

						</div>
					</div>


				</div>
			</div>
		</form>
	</div>
	<script>
		function copyWebhookUrl() {
			const input = document.getElementById('monime_webhook_url');
			input.select();
			input.setSelectionRange(0, 99999);

			navigator.clipboard.writeText(input.value)
				.then(() => {
					alert('Webhook URL copied');
				})
				.catch(() => {
					alert('Failed to copy');
				});
		}
	</script>
	<?php monime_settings_assets(); ?>
<?php
}

/**
 * STYLES
 *
 * Inline admin styles scoped to the Monime settings page markup above.
 */
function monime_settings_assets()
{
?>
	<style>
		.monime-wrap {
			max-width: 1000px;
			margin: 20px auto;
		}

		.monime-top-bar {
			display: flex;
			align-items: center;
			justify-content: space-between;
			background: #ffffff;
			padding: 20px 24px;
			margin-bottom: 28px;
			border: 1px solid #e5e7eb;
			border-radius: 12px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
		}

		.monime-title {
			display: flex;
			align-items: center;
			gap: 14px;
		}

		.monime-icon {
			width: 42px;
			height: 42px;
			border-radius: 8px;
			object-fit: contain;
		}

		.monime-title h2 {
			font-size: 22px;
			margin: 0;
			font-weight: 600;
			color: #1f2937;
		}

		.monime-card {
			background: #ffffff;
			border: 1px solid #e5e7eb;
			border-radius: 12px;
			box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
			overflow: hidden;
		}

		.monime-body {
			padding: 36px;
		}

		.section {
			margin-bottom: 48px;
		}

		.section h2 {
			font-size: 17px;
			font-weight: 600;
			color: #111827;
			margin: 0 0 20px 0;
			padding-bottom: 12px;
			border-bottom: 1px solid #f3f4f6;
		}

		.form-field {
			margin-bottom: 28px;
		}

		.form-field label {
			display: block;
			font-weight: 600;
			color: #374151;
			margin-bottom: 8px;
			font-size: 14px;
		}

		.form-field input {
			width: 100%;
			padding: 11px 14px;
			border: 1px solid #d1d5db;
			border-radius: 8px;
			font-size: 15px;
		}

		.form-field input:focus {
			border-color: #10b981;
			outline: none;
			box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
		}

		.description {
			margin-top: 6px;
			font-size: 13.5px;
			color: #6b7280;
		}
	</style>
<?php
}
