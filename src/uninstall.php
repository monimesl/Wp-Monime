<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

require_once __DIR__ . '/Monime/core/Env.php';
require_once __DIR__ . '/Monime/core/logger.php';

\Monime\core\monime_log('info', 'Monime uninstall started');
\Monime\core\Env::clear();
\Monime\core\monime_log('info', 'Monime uninstall finished');
