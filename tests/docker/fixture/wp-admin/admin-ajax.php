<?php
/**
 * The Akeeba Backup for WordPress entry point.
 *
 * WordPress dispatches on the `action` parameter and knows nothing about Joomla!'s views, so the client strips view,
 * option and format from the URL and this end assumes the v2 API.
 */

require __DIR__ . '/../_fixture/legacy.php';

if (($_REQUEST['action'] ?? '') !== 'akeebabackup_api')
{
	http_response_code(400);

	echo '0';

	exit;
}

fixture_serve_legacy('Api');
