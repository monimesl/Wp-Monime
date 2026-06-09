<?php

declare(strict_types=1);

namespace adaptors\givewp;

use Give\Donations\Models\Donation;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\GatewayRegister;
use Give\Framework\PaymentGateways\PaymentGateway;
use Monime\contracts\MonimePaymentAdapterInterface;
use Monime\contracts\MonimeWebhookAdapterInterface;
use Monime\core\CreateMonimePayload;
use Monime\services\PaymentService;

/**
 * GiveWP payment gateway implementation for Monime hosted checkout.
 *
 * This class is both the GiveWP gateway used during donation checkout and the
 * Monime adapter used by PaymentService and verified webhook dispatch.
 */
class GiveMonimeGateway extends PaymentGateway implements MonimePaymentAdapterInterface, MonimeWebhookAdapterInterface
{
    /** Cached provider icon data to avoid scanning SVG directories repeatedly. */
    private $provider_icon_cache = null;

    /**
     * GiveWP route handlers exposed by this gateway.
     */
    public $routeMethods = [
        'handleCreatePaymentRedirect',
    ];

    // ✅ REQUIRED: static id() - matches interface exactly
    /**
     * GiveWP gateway ID.
     */
    public static function id(): string
    {
        return 'monime';
    }

    /**
     * Monime adapter ID used by the shared registry and webhook routing.
     */
    public function getAdapterId(): string
    {
        return 'givewp';
    }

    /**
     * Convert GiveWP donation data into the shared Monime request DTO.
     */
    public function buildPaymentPayload(array $data): CreateMonimePayload
    {
        $donation_id = isset($data['donation_id']) ? (int) $data['donation_id'] : 0;

        $success_url = $this->generateGatewayRouteUrl('handleCreatePaymentRedirect', [
            'r' => (string) $data['reference'],
            's' => 'c',
        ]);

        $cancel_url = $this->generateGatewayRouteUrl('handleCreatePaymentRedirect', [
            'r' => (string) $data['reference'],
            's' => 'x',
        ]);
        return new CreateMonimePayload(is_array($data['lineItems'] ?? null) ? $data['lineItems'] : [],

        wp_generate_password(32, false),

        (string) $data['reference'],

        (string) $data['name'],

        (string) $data['description'],

        $cancel_url,

        $success_url,

        (string) ($data['financialAccountId'] ?? ''), (string) ($data['currency'] ?? 'SLE'),

        is_array($data['paymentOptions'] ?? null) ? $data['paymentOptions'] : [],

        is_array($data['metadata'] ?? null) ? $data['metadata'] : [],

        wp_generate_password(32, false));
    }

    /**
     * Process a verified Monime webhook payload for GiveWP donations.
     *
     * Signature verification happens in Monime\core\Webhook before this method
     * is called, so this method focuses on donation lookup and status updates.
     */
    public function handleWebhook(array $payload): void
    {
        //error_log('webhook works.............');
        $status = $payload['data']['status'] ?? null;
        $reference = $payload['data']['reference'] ?? null;

        if (!$status || !$reference) {
            return;
        }

        ;

        // 1. Find GiveWP payment using stored reference
        $payments = give_get_payments([
            'meta_key' => '_monime_reference',
            'meta_value' => $reference,
            'number' => 1,
        ]);

        if (empty($payments)) {
            return;
        }

        $payment_id = $payments[0]->ID;

        // 2. Map status
        switch ($status) {
            case 'completed':
                give_update_payment_status($payment_id, 'publish');
                break;

            case 'cancelled':
                give_update_payment_status($payment_id, 'failed');
                break;

            case 'expired':
                give_update_payment_status($payment_id, 'abandoned');
                break;

            default:
                break;
        }

        // 3. Store webhook trace (VERY useful for debugging)
        give_update_payment_meta($payment_id, '_monime_webhook_payload', json_encode($payload));
    }

    /**
     * GiveWP instance-level gateway ID wrapper.
     */
    public function getId(): string
    {
        return static::id();
    }

    /**
     * Human-readable gateway name in GiveWP admin/payment UI.
     */
    public function getName(): string
    {
        return __('Monime', 'monime-gateway');
    }

    /**
     * Tell GiveWP whether this gateway is generally available.
     */
    public function canBeUsed(): bool
    {
        return true;
    }

    /**
     * Checkout label shown to donors.
     */
    public function getPaymentMethodLabel(): string
    {
        $label = give_get_option('monime_title');
        // Fallback to a default string if empty
        return !empty($label) ? $label : __('Monime', 'monime-gateway');
    }

    /**
     * Build the Monime paymentOptions payload from GiveWP gateway settings.
     */
    public static function getPaymentOptionsPayload(): array
    {
        // GiveWP checkbox storage may vary, so treat common false values as off.
        $is_checked = function ($option_name) {
            $value = give_get_option($option_name, 'no');
            // GiveWP stores 'on' for checked checkboxes
            return !empty($value) && $value !== 'no' && $value !== 'off';
        };

        // Provider text fields accept comma-separated provider IDs.
        $split_providers = function ($option_name) {
            $value = give_get_option($option_name, '');
            if (empty($value))
                return [];
            $providers = array_map('strtolower', array_map('trim', explode(',', $value)));
            return array_filter($providers);
        };

        $bank_enabled = $split_providers('monime_bank_enable_providers');
        $bank_disabled = $split_providers('monime_bank_disable_providers');
        $bank_disabled = array_diff($bank_disabled, $bank_enabled);

        $momo_enabled = $split_providers('monime_momo_enable_providers');
        $momo_disabled = $split_providers('monime_momo_disable_providers');
        $momo_disabled = array_diff($momo_disabled, $momo_enabled);

        $wallet_enabled = $split_providers('monime_wallet_enable_providers');
        $wallet_disabled = $split_providers('monime_wallet_disable_providers');
        $wallet_disabled = array_diff($wallet_disabled, $wallet_enabled);

        return [
            'card' => ['disable' => $is_checked('monime_card_disable')],
            'bank' => [
                'disable' => $is_checked('monime_bank_disable'),
                'enabledProviders' => array_values($bank_enabled),
                'disabledProviders' => array_values($bank_disabled),
            ],
            'momo' => [
                'disable' => $is_checked('monime_momo_disable'),
                'enabledProviders' => array_values($momo_enabled),
                'disabledProviders' => array_values($momo_disabled),
            ],
            'wallet' => [
                'disable' => $is_checked('monime_wallet_disable'),
                'enabledProviders' => array_values($wallet_enabled),
                'disabledProviders' => array_values($wallet_disabled),
            ],
        ];
    }

    /**
     * Short gateway description shown by GiveWP.
     */
    public function getPaymentMethodDescription(): string
    {
        return __('pay securely via monime.', 'monime-gateway');
    }

    /**
     * Per-form settings markup shown in GiveWP forms for this gateway.
     */
    public function formSettings(int $formId): array
    {
        $description = give_get_option('monime_description', __(
            'Available payment options for this checkout.',
            'monime-gateway',
        ));

        return [
            'message' => [
                'name' => __('Accepted Payment Providers', 'monime-gateway'),
                'desc' => $description,
                'html' => $this->getProviderIconsPreviewHtml(),
            ],
        ];
    }

    /**
     * Legacy GiveWP form field markup for offsite checkout messaging.
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        if (!defined('MONIME_PLUGIN_URL')) {
            return "<div class='example-offsite-help-text'>
	                    <p>You will be taken to a monime checkout to donate!</p>
	                </div>";
        }

        $icon_url = esc_url(MONIME_PLUGIN_URL . 'assets/images/monime_icon.png');

        return "<div class='example-offsite-help-text' style='display:flex;align-items:center;gap:8px;'>
                    <img src='{$icon_url}' alt='Monime' width='24' height='24' style='display:block;flex:none;' />
                    <p style='margin:0;'>You will be taken to a monime checkout to donate!</p>
                </div>";
    }

    /**
     * Register Monime settings inside the GiveWP gateway settings area.
     */
    public static function registerSettings(): void
    {
        // Register the Monime tab under Gateways
        add_filter('give_get_sections_gateways', function ($sections) {
            $sections['monime'] = __('Monime', 'monime-gateway');
            return $sections;
        });

        // Register the settings fields
        add_filter('give_get_settings_gateways', function ($settings) {
            if (!isset($_GET['section']) || $_GET['section'] !== 'monime') {
                return $settings;
            }

            // Build a replacement settings section only when Monime is selected.
            $new_settings = [];

            // ==================================================================
            // SECTION 1: Monime Gateway Settings
            // ==================================================================
            $new_settings[] = [
                'id' => 'monime_general_section',
                'title' => __('Monime Gateway Settings', 'monime-gateway'),
                'desc' => __('Configure your Monime payment gateway.', 'monime-gateway'),
                'type' => 'title',
            ];

            $new_settings[] = [
                'id' => 'monime_title',
                'name' => __('Gateway Title', 'monime-gateway'),
                'type' => 'text',
                'default' => 'Monime',
            ];

            $new_settings[] = [
                'id' => 'monime_description',
                'name' => __('Gateway Description', 'monime-gateway'),
                'type' => 'textarea',
                'default' => 'Hosted Monime checkout with Cards, Mobile Money, Bank Transfer, or Digital Wallet.',
            ];

            // Close first section
            $new_settings[] = [
                'id' => 'monime_general_section_end',
                'type' => 'sectionend',
            ];

            // ==================================================================
            // SECTION 2: Payment Options Configuration
            // ==================================================================
            $new_settings[] = [
                'id' => 'monime_payment_options_heading',
                'title' => __('Payment Options Configuration', 'monime-gateway'),
                'type' => 'title',
                'description' => __(
                    'Configure which payment methods and providers are available to customers.',
                    'monime-gateway',
                ),
            ];
            $new_settings[] = [
                'id' => 'monime_financial_account',
                'title' => __('Enter Financial Account', 'monime-gateway'),
                'type' => 'text',
                'description' => __('Enter the Account in which the donation funds will settle', 'monime-gateway'),
            ];

            // ------------------------------------------------------------------
            // Card Payments
            // ------------------------------------------------------------------
            $new_settings[] = [
                'id' => 'monime_card_disable',
                'name' => __('disable card payments', 'monime-gateway'),
                'type' => 'checkbox',
                'desc' => __('disable card payment option', 'monime-gateway'),
                'default' => 'no',
                'desc_tip' => __('check to disable card payments entirely.', 'monime-gateway'),
            ];

            // ------------------------------------------------------------------
            // Mobile Money
            // ------------------------------------------------------------------
            $new_settings[] = [
                'id' => 'monime_momo_disable',
                'name' => __('Disable Mobile Money', 'monime-gateway'),
                'type' => 'checkbox',
                'desc' => __('Disable mobile money payment option', 'monime-gateway'),
                'default' => 'no',
                'desc_tip' => __('Check to disable mobile money payments entirely.', 'monime-gateway'),
            ];

            $new_settings[] = [
                'id' => 'monime_momo_enable_providers',
                'name' => __('Mobile Money - Allowed Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __(
                    'Comma-separated list of Mobile Money provider IDs (e.g., m17) to explicitly enable. Takes precedence over disabled providers.',
                    'monime-gateway',
                ),
                'default' => '',
                'desc_tip' => true,
            ];

            $new_settings[] = [
                'id' => 'monime_momo_disable_providers',
                'name' => __('Mobile Money - Disabled Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __('Comma-separated list of Mobile Money provider IDs to exclude.', 'monime-gateway'),
                'default' => '',
                'desc_tip' => true,
            ];

            // ------------------------------------------------------------------
            // Bank Transfers
            // ------------------------------------------------------------------
            $new_settings[] = [
                'id' => 'monime_bank_disable',
                'name' => __('Disable Bank Transfers', 'monime-gateway'),
                'type' => 'checkbox',
                'desc' => __('Disable bank transfer payment option', 'monime-gateway'),
                'default' => 'no',
                'desc_tip' => __('Check to disable bank transfer payments entirely.', 'monime-gateway'),
            ];

            $new_settings[] = [
                'id' => 'monime_bank_enable_providers',
                'name' => __('Bank - Allowed Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __(
                    'Comma-separated list of bank provider IDs to enable. Leave empty to enable all.',
                    'monime-gateway',
                ),
                'default' => '',
                'desc_tip' => true,
            ];

            $new_settings[] = [
                'id' => 'monime_bank_disable_providers',
                'name' => __('Bank - Disabled Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __('Comma-separated list of bank provider IDs to disable.', 'monime-gateway'),
                'default' => '',
                'desc_tip' => true,
            ];

            // ------------------------------------------------------------------
            // Digital Wallets
            // ------------------------------------------------------------------
            $new_settings[] = [
                'id' => 'monime_wallet_disable',
                'name' => __('Disable Digital Wallets', 'monime-gateway'),
                'type' => 'checkbox',
                'desc' => __('Disable digital wallet payment option', 'monime-gateway'),
                'default' => 'no',
                'desc_tip' => __('Check to disable digital wallet payments entirely.', 'monime-gateway'),
            ];

            $new_settings[] = [
                'id' => 'monime_wallet_enable_providers',
                'name' => __('Wallet - Allowed Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __(
                    'Comma-separated list of wallet provider IDs to enable. Leave empty to enable all.',
                    'monime-gateway',
                ),
                'default' => '',
                'desc_tip' => true,
            ];

            $new_settings[] = [
                'id' => 'monime_wallet_disable_providers',
                'name' => __('Wallet - Disabled Providers', 'monime-gateway'),
                'type' => 'text',
                'desc' => __('Comma-separated list of wallet provider IDs to disable.', 'monime-gateway'),
                'default' => '',
                'desc_tip' => true,
            ];

            // Close second section
            $new_settings[] = [
                'id' => 'monime_settings_section_end',
                'type' => 'sectionend',
            ];

            return $new_settings;
        });

        // ----------------------------------------------------------------------
        // Admin CSS + JS (spacing, wide inputs, inline checkboxes, bold headings, hiding logic)
        // ----------------------------------------------------------------------
        add_action('admin_enqueue_scripts', function () {
            if (!isset($_GET['page']) || $_GET['page'] !== 'give-settings') {
                return;
            }
            if (!isset($_GET['section']) || $_GET['section'] !== 'monime') {
                return;
            }

            // Styling is scoped to the GiveWP Monime settings screen.
            wp_add_inline_style('give-admin-styles', '
            /* More spacing between rows */
            .give-settings-page #give-settings-wrap table.form-table tr {
                margin-bottom: 20px;
                display: block;
            }
            /* Wider text inputs and textareas */
            .give-settings-page #give-settings-wrap table.form-table input[type="text"],
            .give-settings-page #give-settings-wrap table.form-table textarea {
                width: 100%;
                max-width: 500px;
            }
            /* Inline checkboxes + description */
            .give-settings-page #give-settings-wrap table.form-table tr:has(input[type="checkbox"]) th,
            .give-settings-page #give-settings-wrap table.form-table tr:has(input[type="checkbox"]) td {
                vertical-align: middle;
            }
            .give-settings-page #give-settings-wrap table.form-table tr:has(input[type="checkbox"]) td p.description {
                display: inline-block;
                margin-left: 10px;
                vertical-align: middle;
            }
            /* Bold & distinct section headings */
            .give-settings-page #give-settings-wrap .give-section-title th,
            .give-settings-page #give-settings-wrap tr.give-section-title th {
                font-weight: 800 !important;
                font-size: 1.3em !important;
                background-color: #f8f9fa;
                border-bottom: 2px solid #ccc;
                border-top: 1px solid #e0e0e0;
                padding: 15px 10px !important;
            }
            .give-settings-page #give-settings-wrap .give-section-title td p,
            .give-settings-page #give-settings-wrap tr.give-section-title td p {
                font-weight: normal;
                font-size: 0.9em;
                color: #555;
            }
            /* Hide helper class */
            .monime-child-hidden { display: none !important; }
        ');

            wp_add_inline_script('jquery', '
            jQuery(function ($) {
                "use strict";

                // Define parent (disable checkbox) -> child fields (both enable and disable provider fields)
                var pairs = [
                    {
                        parent: "monime_momo_disable",
                        children: ["monime_momo_enable_providers", "monime_momo_disable_providers"]
                    },
                    {
                        parent: "monime_bank_disable",
                        children: ["monime_bank_enable_providers", "monime_bank_disable_providers"]
                    },
                    {
                        parent: "monime_wallet_disable",
                        children: ["monime_wallet_enable_providers", "monime_wallet_disable_providers"]
                    }
                ];

                // Find the table row (tr) containing a field by its ID
                function findFieldRow(fieldId) {
                    var $row = $("#give_settings_" + fieldId + "_wrap");
                    if ($row.length) return $row;
                    $row = $("tr[data-give-field-id=\"" + fieldId + "\"]");
                    if ($row.length) return $row;
                    $row = $("input[name*=\"" + fieldId + "\"]").closest("tr");
                    if ($row.length) return $row;
                    $row = $("tr[id*=\"" + fieldId + "\"]");
                    return $row;
                }

                // Toggle visibility & disabled state for children based on parent checkbox (hide if parent IS checked)
                function toggleChildFields(parentId, childIds) {
                    var $parent = $("#" + parentId);
                    if (!$parent.length) return false;
                    var isDisabled = $parent.prop("checked"); // true = disable is checked

                    childIds.forEach(function (childId) {
                        var $row = findFieldRow(childId);
                        if (!$row.length) return;

                        if (isDisabled) {
                            // Disable is ON → hide child fields and disable/unset them
                            $row.addClass("monime-child-hidden").hide();
                            $row.find("input, select, textarea").each(function () {
                                var $input = $(this);
                                $input.prop("disabled", true);
                                if ($input.is(":checkbox, :radio")) {
                                    $input.prop("checked", false);
                                } else {
                                    $input.val(""); // clear text fields
                                }
                            });
                        } else {
                            // Disable is OFF → show child fields and enable them
                            $row.removeClass("monime-child-hidden").show();
                            $row.find("input, select, textarea").each(function () {
                                $(this).prop("disabled", false);
                            });
                        }
                    });
                }

                function applyAllToggles() {
                    pairs.forEach(function (pair) {
                        toggleChildFields(pair.parent, pair.children);
                    });
                }

                // Ensure default states: all disable checkboxes default to unchecked (so providers should be visible)
                function setDefaultDisableCheckboxes() {
                    var disableIds = ["monime_momo_disable", "monime_bank_disable", "monime_wallet_disable"];
                    disableIds.forEach(function (id) {
                        var $cb = $("#" + id);
                        if ($cb.length && $cb.prop("checked") === undefined) {
                            $cb.prop("checked", false);
                            $cb.trigger("change");
                        }
                    });
                }

                // Event listeners
                $(document).on("change", "#monime_momo_disable, #monime_bank_disable, #monime_wallet_disable", applyAllToggles);

                // MutationObserver for dynamic loading
                var targetNode = document.getElementById("give-settings-wrap") || document.getElementById("wpbody-content") || document.body;
                var observer = new MutationObserver(function () {
                    if ($("#monime_momo_disable").length || $("#monime_bank_disable").length || $("#monime_wallet_disable").length) {
                        setDefaultDisableCheckboxes();
                        applyAllToggles();
                    }
                });
                observer.observe(targetNode, { childList: true, subtree: true });

                $(window).on("load", function () {
                    setDefaultDisableCheckboxes();
                    applyAllToggles();
                });
                setTimeout(function () { setDefaultDisableCheckboxes(); applyAllToggles(); }, 500);
                setTimeout(function () { setDefaultDisableCheckboxes(); applyAllToggles(); }, 1500);

                // Initial run
                setDefaultDisableCheckboxes();
                applyAllToggles();
            });
        ');
        });
    }

    /**
     * GiveWP feature support advertised by this gateway.
     */
    public function supports(): array
    {
        return [
            'one-time-donation',
        ];
    }

    /**
     * Enqueue GiveWP frontend assets used by the Monime gateway option.
     */
    public function enqueueScript(int $formId): void
    {
        if (!defined('MONIME_PLUGIN_DIR') || !defined('MONIME_PLUGIN_URL')) {
            return;
        }

        $script_path = MONIME_PLUGIN_DIR . 'assets/js/monime.js';
        if (!file_exists($script_path)) {
            return;
        }

        wp_enqueue_script(
            'monime-gateway-checkout',
            MONIME_PLUGIN_URL . 'assets/js/monime.js',
            ['wp-element', 'givewp-donation-form-registrars'],
            '1.0.0',
            true,
        );

        wp_localize_script('monime-gateway-checkout', 'monimeConfig', [
            'gatewayId' => static::id(),
            'checkoutUrl' => add_query_arg('give-gateway', static::id(), home_url()),
            'iconUrl' => MONIME_PLUGIN_URL . 'assets/images/monime_icon.png',
        ]);

        $monime_icon_url = MONIME_PLUGIN_URL . 'assets/images/monime_icon.png';

        wp_register_style('monime-givewp-gateway-icons', false);
        wp_enqueue_style('monime-givewp-gateway-icons');

        wp_add_inline_style('monime-givewp-gateway-icons', "
          .givewp-fields-gateways__gateway--monime .givewp-fields-gateways__gateway__icon {
              display: inline-block;
              width: 32px;
              height: 32px;
              background-image: url('{$monime_icon_url}');
              background-repeat: no-repeat;
              background-position: center;
              background-size: contain;
              font-size: 0;
              line-height: 0;
              color: transparent;
          }

          .givewp-fields-gateways__gateway--monime .givewp-fields-gateways__gateway__icon::before {
              content: '' !important;
          }

          #give-gateway-option-monime::before {
              content: '';
              display: inline-block;
              width: 24px;
              height: 24px;
              margin-right: 8px;
              vertical-align: middle;
              background-image: url('{$monime_icon_url}');
              background-repeat: no-repeat;
              background-position: center;
              background-size: contain;
          }
          ");
    }

    public function get_campaign_name(Donation $donation)
    {
        $form_id = null;
        /**
         * GiveWP 4.x
         */
        if (!empty($donation->formId)) {
            $form_id = $donation->formId;
        }

        /**
         * GiveWP 2.x / 3.x
         */
        if (!$form_id && !empty($donation->form_id)) {
            $form_id = $donation->form_id;
        }

        $campaign_name = '';

        if ($form_id) {
            $campaign_name = trim(wp_strip_all_tags(give_get_donation_form_title($form_id)));

            if (empty($campaign_name)) {
                $campaign_name = get_the_title($form_id);
            }
        }

        if (empty($campaign_name)) {
            $campaign_name = 'Donation';
        }
        return $campaign_name;
    }

    public function createPayment(Donation $donation, $gatewayData)
    {
        // Use a unique reference to link Monime webhooks back to this donation.
        $reference = 'monime_' . $donation->id . '_' . wp_generate_uuid4();

        $amount = (int) round($donation->amount->formatToDecimal() * 100);
        $currency = $donation->amount->getCurrency()->getCode();
        $campaign_name = $this->get_campaign_name($donation);
        $des = 'Donating for ' . $campaign_name;
        // Build the adapter payload that PaymentService will normalize for Monime.
        $data = [
            'name' => $campaign_name,
            'amount' => $amount,
            'reference' => $reference,
            'donation_id' => $donation->id,
            'description' => $des,
            'currency' => $currency,
            'lineItems' => [
                [
                    'name' => $des, // or use $donation->formTitle etc.
                    'price' => [
                        'currency' => $currency,
                        'value' => (float) $amount, // amount in minor unit? Confirm with Monime docs
                    ],
                    'type' => 'custom',
                ],
            ],
        ];
        if (give_get_option('monime_financial_account')) {
            $data['financialAccountId'] = give_get_option('monime_financial_account');
        }
        $data['paymentOptions'] = self::getPaymentOptionsPayload();
        try {
            $response = PaymentService::create($this->getAdapterId(), $data);
        } catch (\Throwable $e) {
            throw new \Exception('Monime checkout initialization failed.');
        } // Store local identifiers before sending the donor offsite.
        $donation->gatewayTransactionId = $reference;
        $donation->save();
        update_post_meta($donation->id, '_monime_reference', $reference);
        update_post_meta($donation->id, '_monime_redirect_url', $response['redirectUrl']);
        return new RedirectOffsite($response['redirectUrl']);
    }

    /**
     * Handle GiveWP's secure return route after offsite checkout redirection.
     */
    protected function handleCreatePaymentRedirect(array $queryParams)
    {
        /*
         |--------------------------------------------------------------------------
         | Compact Query Params
         |--------------------------------------------------------------------------
         |
         | r = reference
         | s = status
         |
         | s=c => completed
         | s=x => cancelled/failed
         |
         */

        $reference = sanitize_text_field((string) ($queryParams['r'] ?? ''));

        $status = sanitize_text_field(strtolower((string) ($queryParams['s'] ?? '')));

        $successUrl = give_get_success_page_uri();

        $failedUrl = give_get_failed_transaction_uri();

        /*
         |--------------------------------------------------------------------------
         | Missing Reference
         |--------------------------------------------------------------------------
         */

        if (empty($reference)) {
            return new RedirectResponse($failedUrl);
        }

        /*
         |--------------------------------------------------------------------------
         | Locate Donation
         |--------------------------------------------------------------------------
         */

        $payments = give_get_payments([
            'meta_key' => '_monime_reference',
            'meta_value' => $reference,
            'number' => 1,
        ]);

        if (empty($payments)) {
            return new RedirectResponse($failedUrl);
        }

        $payment_id = (int) $payments[0]->ID;

        $current_status = give_get_payment_status($payment_id);

        /*
         |--------------------------------------------------------------------------
         | Cancelled / Failed
         |--------------------------------------------------------------------------
         */

        if ($status === 'x') {
            // Never overwrite completed donations
            if ($current_status !== 'publish') {
                //error_log('x.....not publish ==fail');
                give_update_payment_status($payment_id, 'failed', 'Donation cancelled during Monime checkout.');

                give_update_payment_meta($payment_id, '_monime_completion_source', 'redirect_cancel');

                give_update_payment_meta($payment_id, '_monime_redirect_status', 'cancelled');

                give_update_payment_meta($payment_id, '_monime_cancelled_at', current_time('mysql'));

                give_insert_payment_note($payment_id, 'Monime checkout cancelled by donor.');
            }

            return new RedirectResponse($failedUrl);
        }

        /*
         |--------------------------------------------------------------------------
         | Successful Transaction
         |--------------------------------------------------------------------------
         */

        if ($current_status !== 'publish') {
            //error_log('o..... publish ==success');
            give_update_payment_status($payment_id, 'publish', 'Donation completed via Monime redirect.');

            give_update_payment_meta($payment_id, '_monime_completion_source', 'redirect');

            give_update_payment_meta($payment_id, '_monime_redirect_status', 'completed');

            give_update_payment_meta($payment_id, '_monime_completed_at', current_time('mysql'));

            give_insert_payment_note($payment_id, 'Monime redirect marked donation as completed.');
        }

        return new RedirectResponse($successUrl);
    }

    /**
     * Tell GiveWP to create a payment record before redirecting offsite.
     */
    public function shouldCreatePayment(): bool
    {
        return true;
    }

    /**
     * Read a Monime-prefixed GiveWP option as a string.
     */
    private function giveOption(string $key, string $default = ''): string
    {
        return (string) give_get_option('monime_' . $key, $default);
    }

    /**
     * Determine whether a GiveWP checkbox-style setting is enabled.
     */
    private function isGiveOptionChecked(string $key): bool
    {
        $value = give_get_option('monime_' . $key, 'no');

        return !empty($value) && !in_array((string) $value, ['no', 'off', 'false', '0'], true);
    }

    /**
     * Parse comma-separated provider IDs into lowercase provider keys.
     */
    private function parseProviderList($value): array
    {
        if (empty($value)) {
            return [];
        }

        $parts = array_map('trim', explode(',', (string) $value));
        $parts = array_filter($parts);

        return array_map('strtolower', $parts);
    }

    /**
     * Discover provider SVG icons grouped by Monime payment channel.
     */
    private function getProviderIconMap(): array
    {
        if (null !== $this->provider_icon_cache) {
            return $this->provider_icon_cache;
        }

        $channels = [
            'card' => 'cards',
            'momo' => 'momos',
            'bank' => 'banks',
            'wallet' => 'wallets',
        ];

        $base_path = trailingslashit(MONIME_PLUGIN_DIR . 'assets/icons');
        $base_url = trailingslashit(MONIME_PLUGIN_URL . 'assets/icons');

        $map = [];

        foreach ($channels as $channel => $subdir) {
            // Each channel has its own icon folder under assets/icons.
            $full_path = trailingslashit($base_path . $subdir);

            if (!is_dir($full_path)) {
                continue;
            }

            foreach (glob($full_path . '*.svg') as $file) {
                $id = strtolower(pathinfo($file, PATHINFO_FILENAME));

                $map[$channel][$id] = [
                    'id' => $id,
                    'label' => strtoupper($id),
                    'icon' => trailingslashit($base_url . $subdir) . basename($file),
                ];
            }
        }

        $this->provider_icon_cache = $map;

        return $map;
    }

    /**
     * Return visible provider badges for one channel after GiveWP settings filters.
     */
    private function getProviderBadgesForChannel(string $channel): array
    {
        $icons = $this->getProviderIconMap();
        $channel_icons = $icons[$channel] ?? [];

        if (empty($channel_icons)) {
            return [];
        }

        if ('card' === $channel) {
            return $this->isGiveOptionChecked('card_disable') ? [] : array_values($channel_icons);
        }

        if ($this->isGiveOptionChecked($channel . '_disable')) {
            return [];
        }

        $enabled = $this->parseProviderList($this->giveOption($channel . '_enable_providers'));
        $disabled = $this->parseProviderList($this->giveOption($channel . '_disable_providers'));

        if (!empty($enabled)) {
            $selected = [];

            foreach ($enabled as $id) {
                if (isset($channel_icons[$id])) {
                    $selected[$id] = $channel_icons[$id];
                }
            }

            return array_values($selected);
        }

        foreach ($disabled as $id) {
            unset($channel_icons[$id]);
        }

        return array_values($channel_icons);
    }

    /**
     * Build display/overflow badge groups for GiveWP checkout/settings UI.
     */
    private function getProviderBadgeData(): array
    {
        $channels = ['card', 'momo', 'bank', 'wallet'];

        $limits = [
            'card' => -1,
            'momo' => -1,
            'bank' => 2,
            'wallet' => 2,
        ];

        $bank_priority = ['slb003', 'slb004'];

        $display = [];
        $overflow_items = [];

        foreach ($channels as $channel) {
            $badges = $this->getProviderBadgesForChannel($channel);

            $badges = array_map(static function ($badge) use ($channel) {
                $badge['channel'] = $channel;
                return $badge;
            }, $badges);

            if ('bank' === $channel) {
                usort($badges, static function ($a, $b) use ($bank_priority) {
                    $a_pos = array_search($a['id'], $bank_priority, true);
                    $b_pos = array_search($b['id'], $bank_priority, true);

                    $a_pos = false === $a_pos ? 999 : $a_pos;
                    $b_pos = false === $b_pos ? 999 : $b_pos;

                    return $a_pos <=> $b_pos;
                });
            }

            $limit = $limits[$channel] ?? -1;

            if ($limit < 0 || count($badges) <= $limit) {
                $display = array_merge($display, $badges);
                continue;
            }

            $display = array_merge($display, array_slice($badges, 0, $limit));
            $overflow_items = array_merge($overflow_items, array_slice($badges, $limit));
        }

        return [
            'display' => $display,
            'overflow' => !empty($overflow_items)
                ? [[
                    'count' => count($overflow_items),
                    'items' => array_values($overflow_items),
                ]]
                : [],
        ];
    }

    /**
     * Render provider badge preview HTML shown in GiveWP settings/forms.
     */
    private function getProviderIconsPreviewHtml(): string
    {
        $badge_data = $this->getProviderBadgeData();
        $badges = $badge_data['display'] ?? [];
        $overflow = $badge_data['overflow'] ?? [];

        ob_start();
        ?>
		<style>
			.monime-provider-grid {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 8px 0 12px;
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

			.monime-provider-grid img {
				width: 36px;
				height: 36px;
				display: block;
			}

			.monime-provider-overflow {
				position: relative;
			}

			.monime-provider-overflow-trigger {
				font-weight: 600;
				font-size: 13px;
			}

			.monime-provider-overflow-popover {
				position: absolute;
				bottom: 100%;
				left: 50%;
				transform: translateX(-50%);
				margin-bottom: 8px;
				background: #111827;
				padding: 12px;
				border-radius: 8px;
				display: grid;
				grid-template-columns: repeat(4, 36px);
				gap: 8px;
				box-shadow: 0 8px 20px rgba(15, 23, 42, 0.35);
				opacity: 0;
				visibility: hidden;
				pointer-events: none;
				z-index: 1000;
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
					<img src="<?php echo esc_url($badge['icon']); ?>" alt="<?php echo esc_attr($badge['label']); ?>">
				</span>
			<?php endforeach; ?>

			<?php foreach ($overflow as $group): ?>
				<span class="monime-provider-overflow">
					<span class="monime-provider-overflow-trigger">+<?php echo intval($group['count']); ?></span>
					<div class="monime-provider-overflow-popover">
						<?php foreach ($group['items'] as $item): ?>
							<img src="<?php echo esc_url($item['icon']); ?>" alt="<?php echo esc_attr($item['label']); ?>">
						<?php endforeach; ?>
					</div>
				</span>
			<?php endforeach; ?>
		</div>

		<p class="monime-security-note">
			You will be redirected to Monime to complete payment securely.
		</p>
<?php

        return ob_get_clean();
    }
}
