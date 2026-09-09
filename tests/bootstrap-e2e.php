<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

/**
 * Bootstrap for the integration test suite.
 *
 * It loads no library code beyond the autoloader and opens no connection of its own beyond one probe. Its whole job is
 * to fail with a sentence a human can act on when the disposable stack is not there, instead of letting every test in
 * the suite report its own connection error.
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoloader))
{
	fwrite(STDERR, 'Composer dependencies are not installed. Run `composer install` first.' . PHP_EOL);

	exit(1);
}

require_once $autoloader;

$configuration = is_file(__DIR__ . '/config.php')
	? require __DIR__ . '/config.php'
	: require __DIR__ . '/config.dist.php';

define('AKEEBA_JSON_BACKUP_API_E2E_CONFIG', $configuration);

$probe = $configuration['joomla'] . '/health.php';
$curl  = curl_init($probe);

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($curl, CURLOPT_TIMEOUT, 10);

$body   = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

// No curl_close() here: it has done nothing since PHP 8.0 and is deprecated from PHP 8.5 on.

if ($body !== 'OK' || $status !== 200)
{
	fwrite(
		STDERR,
		sprintf(
			'The test fixture is not answering at %s.' . PHP_EOL . PHP_EOL
			. 'This suite runs inside the disposable Docker stack, not on your machine. Start it with:' . PHP_EOL
			. PHP_EOL . '    tests/docker/run.sh' . PHP_EOL . PHP_EOL
			. 'See tests/README.md for what the stack is and why the suite runs inside it.' . PHP_EOL,
			$probe
		)
	);

	exit(1);
}
