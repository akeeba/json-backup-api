<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit;

use Akeeba\BackupJsonApi\Tests\Stub\ArrayLogger;
use Akeeba\BackupJsonApi\Options;
use Composer\CaBundle\CaBundle;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the unit tests.
 *
 * @since 1.1.0
 */
abstract class UnitTestCase extends TestCase
{
	/**
	 * Builds an Options object without touching the network or the system's CA store.
	 *
	 * The bundled CA file is used deliberately. Left to its own devices, Options goes looking for the system's CA
	 * bundle, which is both slow and a different answer on every machine the suite runs on.
	 *
	 * @param   array  $overrides  The options to set, over the defaults every test needs
	 *
	 * @return  Options
	 * @since   1.1.0
	 */
	protected function makeOptions(array $overrides = []): Options
	{
		return new Options(
			array_merge(
				[
					'capath' => CaBundle::getBundledCaBundlePath(),
					'host'   => 'https://www.example.com',
					'secret' => 'TheSecretWord',
					'ua'     => 'AkeebaBackupJsonApiClient/test',
					'logger' => new ArrayLogger(),
				],
				$overrides
			)
		);
	}

	/**
	 * Builds a JSON API v2 / v3 shaped response object, as doQuery() would have returned it.
	 *
	 * @param   int    $status  The API status
	 * @param   mixed  $data    The response payload
	 *
	 * @return  object
	 * @since   1.1.0
	 */
	protected function makeApiResponse(int $status, mixed $data = null): object
	{
		return (object) [
			'body' => (object) [
				'status' => $status,
				'data'   => $data,
			],
		];
	}
}
