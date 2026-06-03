<?php

namespace Monime\core;

/**
 * Return the Monime API endpoints used by the plugin.
 *
 * Keeping the URLs in one helper makes the client class easier to read and
 * gives us a single place to update if Monime changes endpoint paths later.
 */
function get_urls()
{
	return [
		"checkout_session_url" => "https://api.monime.io/v1/checkout-sessions",


	];
};
