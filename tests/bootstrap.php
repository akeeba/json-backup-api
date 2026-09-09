<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

/**
 * Bootstrap for the unit test suite.
 *
 * This registers the Composer autoloader and nothing else. Unit tests in this project are in-process and do no I/O, so
 * there is no application to boot and no environment to prepare.
 */

defined('AKEEBA_JSON_BACKUP_API_TEST_ROOT') || define('AKEEBA_JSON_BACKUP_API_TEST_ROOT', __DIR__);

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoloader))
{
	fwrite(STDERR, "Composer dependencies are not installed. Run `composer install` first." . PHP_EOL);

	exit(1);
}

require_once $autoloader;
