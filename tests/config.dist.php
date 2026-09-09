<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

/**
 * The defaults the integration suite runs with.
 *
 * tests/docker/run.sh writes a tests/config.php from tests/docker/.env and the suite prefers that when it exists. This
 * file is the committed record of what the settings are and what they mean; config.php is generated and git-ignored.
 *
 * Every host below resolves to a container on the Compose network, which is why the suite runs inside one. See
 * tests/integration-README.md.
 */

return [
	// The Joomla! site: index.php for the v1 and v2 APIs, api/index.php for the v3 API.
	'joomla'          => 'http://www.example.test',

	// The same site under a subdomain, so a redirect *up* to a parent domain can be exercised.
	'joomlaAncestor'  => 'http://api.example.test',

	// The Akeeba Solo entry point. The v2 API only: there is no Joomla! API application behind it.
	'solo'            => 'http://www.example.test/remote.php',

	// The Akeeba Backup for WordPress entry point. Also v2 only.
	'wordpress'       => 'http://www.example.test/wp-admin/admin-ajax.php',

	/**
	 * A different, genuinely reachable host, which the library must never follow a redirect to. It records what it is
	 * sent, so a test can assert the credential was not merely refused but never delivered.
	 */
	'sink'            => 'http://evil.test',

	// The Akeeba Backup JSON API Secret Word the fixture accepts.
	'secret'          => 'TheAkeebaBackupSecretWord',

	// A Joomla! API token belonging to an account allowed to call every API method.
	'token'           => 'c2hhMjU2OjcwOjgwMzE3NzRiYWI1YTY0MGY4NWQ2MTI3NjY1YmZiMGY2',

	// A Joomla! API token belonging to an account which may take backups, but not download or delete them.
	'restrictedToken' => 'c2hhMjU2OjcxOmRlYWRiZWVmZGVhZGJlZWZkZWFkYmVlZmRlYWRiZWVm',
];
