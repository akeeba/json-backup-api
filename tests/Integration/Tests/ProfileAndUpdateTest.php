<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The backup profiles, and the backup software's own updater.
 */
#[Group('e2e')]
class ProfileAndUpdateTest extends E2ETestCase
{
	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testListsTheBackupProfiles(string $clientName, int $apiVersion): void
	{
		$profiles = $this->makeConnector($clientName, ['apiVersion' => $apiVersion])->getProfiles();

		$this->assertNotEmpty($profiles);
		$this->assertSame(1, (int) $profiles[0]->id);
		$this->assertSame('Default backup profile', $profiles[0]->name);
	}

	#[DataProvider('httpClientProvider')]
	public function testExportsAProfilesConfiguration(string $clientName): void
	{
		$configuration = $this->makeConnector($clientName, ['apiVersion' => 3])->exportConfiguration(2);

		$this->assertSame('Nightly', $configuration->description);
	}

	#[DataProvider('httpClientProvider')]
	public function testImportsAProfile(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$before = count($connector->getProfiles());

		$connector->importConfiguration(json_encode(['description' => 'Imported by the integration suite']));

		$this->assertCount($before + 1, $connector->getProfiles());
	}

	#[DataProvider('httpClientProvider')]
	public function testReadsTheUpdateInformation(string $clientName): void
	{
		$update = $this->makeConnector($clientName, ['apiVersion' => 3])->getUpdateInformation();

		$this->assertSame('10.4.1', $update->version);
		$this->assertTrue($update->hasUpdates);
	}

	/**
	 * The four update steps are separate API calls a caller runs in order. Nothing here checks that the server really
	 * updated itself — the fixture cannot — only that each step is addressed and its answer understood.
	 */
	#[DataProvider('httpClientProvider')]
	public function testRunsEveryStepOfAnUpdate(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$connector->downloadUpdate();
		$connector->extractUpdate();
		$connector->installUpdate();
		$connector->cleanupUpdate();

		$this->assertSame(
			['updateDownload', 'updateExtract', 'updateInstall', 'updateCleanup'],
			array_values(
				array_filter(
					array_map(
						fn(array $request) => $this->apiMethodOf($request),
						$this->site->requests()
					)
				)
			)
		);
	}

	/**
	 * Works out which API method a recorded request was addressing. On the v3 API that is the last path segment; on
	 * the older ones it is a query string parameter.
	 *
	 * @param   array  $request  The request as the fixture recorded it
	 *
	 * @return  string|null
	 */
	private function apiMethodOf(array $request): ?string
	{
		if (isset($request['query']['method']))
		{
			return (string) $request['query']['method'];
		}

		$path = parse_url((string) $request['uri'], PHP_URL_PATH) ?: '';

		return preg_match('#/v3/akeebabackup/([^/]+)$#', $path, $matches) ? rawurldecode($matches[1]) : null;
	}
}
