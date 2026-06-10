<?php

namespace Monime\core;

if (!defined('ABSPATH')) {
    exit();
}

use Monime\contracts\MonimeWebhookAdapterInterface;
use Monime\core\Env;
use Monime\registry\AdapterRegistry;

/**
 * WordPress REST webhook endpoint for Monime events.
 *
 * The webhook flow is intentionally centralized here: verify Monime's
 * signature first, then dispatch the decoded payload to the adapter stored in
 * checkout metadata.
 */
class Webhook
{
    /**
     * Attach route registration to the REST API bootstrap hook.
     */
    public static function init()
    {
        monime_log('info', 'Webhook route bootstrap initialized');
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register the public Monime webhook route.
     */
    public static function register_routes()
    {
        monime_log('info', 'Webhook route registered');
        register_rest_route('monime/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Verify the Monime signature and decode the raw JSON payload.
     *
     * Returns an array on success, or WP_REST_Response when verification fails.
     */
    private static function verify(\WP_REST_Request $request)
    {
        $env = Env::get();

        $secret = trim($env['webhook_secret']);
        $raw_payload = $request->get_body();

        if ($secret === '') {
            monime_log('error', 'Webhook secret not configured');
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Webhook secret not configured',
            ], 200);
        }

        $signature_header = $request->get_header('monime-signature') ?: $_SERVER['HTTP_MONIME_SIGNATURE'] ?? '';

        if (empty($signature_header)) {
            monime_log('error', 'Webhook signature missing');
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing signature',
            ], 401);
        }
        $timestamp = null;
        $signature = null;

        // Monime sends signature values as comma-separated key/value parts.
        foreach (explode(',', $signature_header) as $part) {
            $part = trim($part);

            if (strpos($part, 't=') === 0) {
                $timestamp = (int) substr($part, 2);
            }

            if (strpos($part, 'v1=') === 0) {
                $signature = trim(substr($part, 3));
            }
        }
        //foreach (explode(',', $signature_header) as $part) {

        //	$part = trim($part);

        //	if (!function_exists('str_starts_with')) {
        //		function str_starts_with($haystack, $needle)
        ///		{
        //			return strpos($haystack, $needle) === 0;
        //		}
        //	}
        //	if (str_starts_with($part, 't=')) {
        //		$timestamp = (int) substr($part, 2);
        //	}
        //	if (!function_exists('str_starts_with')) {
        //		function str_starts_with($haystack, $needle)
        //		{
        //			return strpos($haystack, $needle) === 0;
        //		}
        //	}
        //	if (str_starts_with($part, 'v1=')) {
        //		$signature = trim(substr($part, 3));
        //	}
        //}

        if (!$timestamp || !$signature) {
            monime_log('error', 'Webhook signature format invalid');
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid signature format',
            ], 401);
        }
        // Reject stale webhook attempts to reduce replay risk.
        $now = time();

        if (abs($now - $timestamp) > 300) {
            monime_log('error', 'Webhook signature timestamp expired', [
                'timestamp' => $timestamp,
            ]);
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Timestamp expired',
            ], 401);
        }

        // Monime signs the timestamp and exact raw request body.
        $signed_payload = $timestamp . '_' . $raw_payload;

        $expected_signature = base64_encode(hash_hmac('sha256', $signed_payload, $secret, true));

        if (!hash_equals($expected_signature, $signature)) {
            monime_log('error', 'Webhook signature mismatch');
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid signature',
            ], 401);
        }

        // Decode only after the signature has been validated.
        $data = json_decode($raw_payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            monime_log('error', 'Webhook JSON invalid', [
                'json_error' => json_last_error_msg(),
            ]);
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid JSON',
            ], 400);
        }
        return $data;
    }

    /**
     * REST callback: verify payload, resolve adapter, then dispatch the event.
     */
    public static function handle(\WP_REST_Request $request)
    {
        $verification_result = self::verify($request);

        if ($verification_result instanceof \WP_REST_Response) {
            return $verification_result;
        }

        $handler = $verification_result['data']['metadata']['handler'] ?? null;

        if (!$handler) {
            monime_log('error', 'Webhook metadata missing handler');
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Missing handler',
            ], 400);
        }

        // Adapter IDs are written into Monime checkout metadata at session creation.
        $adapter = AdapterRegistry::get($handler);

        if (!$adapter) {
            monime_log('error', 'Webhook adapter not found', [
                'handler' => $handler,
            ]);
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => 'Invalid Adapter',
            ], 400);
        }

        if (!$adapter instanceof MonimeWebhookAdapterInterface) {
            monime_log('info', 'Webhook adapter does not support webhooks', [
                'handler' => $handler,
            ]);
            return new \WP_REST_Response([
                'status' => 'ignored',
                'message' => 'Webhook not supported',
            ], 200);
        }

        monime_log('info', 'Webhook dispatched to adapter', [
            'handler' => $handler,
            'adapter_class' => get_class($adapter),
        ]);

        // The adapter performs the platform-specific order/donation update.
        $adapter->handleWebhook($verification_result);

        return new \WP_REST_Response([
            'status' => 'success',
            'message' => 'Webhook processed',
        ], 200);
    }
}
