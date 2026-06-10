<?php

namespace Monime\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Shared Monime logger used across the plugin.
 */
function monime_log(string $level, string $message, array $context = []): void
{
	$level = strtolower($level);
	$redacted_context = [];
	$redact_keys = ['token', 'webhook_secret', 'authorization', 'password', 'secret', 'email'];

	foreach ($context as $key => $value) {
		$key_lc = strtolower((string) $key);
		if (in_array($key_lc, $redact_keys, true)) {
			$redacted_context[$key] = '[redacted]';
			continue;
		}

		if (is_scalar($value) || null === $value) {
			$redacted_context[$key] = $value;
			continue;
		}

		$redacted_context[$key] = is_array($value) ? wp_json_encode($value) : gettype($value);
	}

	$line = '[Monime] ' . $message;
	if (!empty($redacted_context)) {
		$line .= ' | ' . wp_json_encode($redacted_context);
	}

	if (function_exists('wc_get_logger')) {
		wc_get_logger()->log($level, $line, ['source' => 'monime']);
		return;
	}

	error_log(strtoupper($level) . ': ' . $line);
}
