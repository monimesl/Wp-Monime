<?php

namespace Monime\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Centralized Monime configuration reader.
 *
 * This implementation avoids:
 * - stale object cache issues
 * - browser autofill confusion
 * - null/object/array option corruption
 * - repeated get_option boilerplate
 *
 * WordPress options are the ONLY source of truth.
 */
final class Env
{
	/**
	 * Return all plugin settings.
	 */
	public static function get(): array
	{
		return [
			'monime_token'       => self::option('monime_token'),
			'monime_space_id'    => self::option('monime_space_id'),
			'financialAccountId' => self::option('monime_financial_account_id'),
			'monime_logo'        => self::option('monime_logo'),
			'success_url'        => self::option('success_url'),
			'cancel_url'         => self::option('cancel_url'),
			'webhook_secret'     => self::option('webhook_secret'),
		];
	}

	/**
	 * Read a WordPress option safely as a clean string.
	 */
	private static function option(string $key): string
	{
		/*
		 * Force fresh DB read.
		 * Prevents stale object-cache returns.
		 */
		wp_cache_delete($key, 'options');

		$value = get_option($key, '');

		/*
		 * Reject invalid types entirely.
		 */
		if (!is_scalar($value)) {
			return '';
		}

		$value = trim((string) $value);

		/*
		 * Normalize empty values.
		 */
		if ($value === '') {
			return '';
		}

		return $value;
	}

	/**
	 * Delete all Monime credentials/settings completely.
	 */
	public static function clear(): void
	{
		$keys = [
			'monime_token',
			'monime_space_id',
			'monime_financial_account_id',
			'monime_logo',
			'success_url',
			'cancel_url',
			'webhook_secret',
		];

		foreach ($keys as $key) {

			delete_option($key);

			/*
			 * Remove stale cache entries.
			 */
			wp_cache_delete($key, 'options');
		}

		/*
		 * Flush persistent object cache.
		 */
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
	}
}
