<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Taking a backup, and doing something with the record afterwards.
 */
#[Group('e2e')]
class BackupWorkflowTest extends E2ETestCase
{
	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testTakesABackupFromStartToFinish(string $clientName, int $apiVersion): void
	{
		$result = $this->makeConnector($clientName, ['apiVersion' => $apiVersion])
			->backup(new BackupOptions(['profile' => 2, 'description' => 'Taken by the integration suite']));

		$this->assertGreaterThan(0, $result->id);
		$this->assertNotSame('', $result->archive);

		// …and the record is really there afterwards, which the return value alone does not prove.
		$this->assertTrue($this->site->hasRecord((int) $result->id));
	}

	#[DataProvider('httpClientProvider')]
	public function testReportsProgressAsTheBackupRuns(string $clientName): void
	{
		$ticks = [];

		$this->makeConnector($clientName, ['apiVersion' => 3])->backup(
			new BackupOptions(),
			function ($data) use (&$ticks) {
				$ticks[] = [
					'domain'   => $data->Domain,
					'progress' => $data->Progress,
					'hasRun'   => $data->HasRun,
				];
			}
		);

		$this->assertGreaterThan(1, count($ticks), 'The backup finished without ever reporting progress');

		// The last tick is the one which says it is over, and the caller must see it.
		$this->assertFalse($ticks[count($ticks) - 1]['hasRun']);
	}

	/**
	 * The server reports warnings as it goes — an unreadable file, say — and they are the whole reason a caller
	 * watches a backup rather than just waiting for it.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTheCallerSeesTheServersWarnings(string $clientName): void
	{
		$warnings = [];

		$this->makeConnector($clientName, ['apiVersion' => 3])->backup(
			new BackupOptions(),
			function ($data) use (&$warnings) {
				$warnings = array_merge($warnings, (array) $data->Warnings);
			}
		);

		$this->assertContains('Could not read /var/www/html/unreadable.txt', $warnings);
	}

	#[DataProvider('httpClientProvider')]
	public function testTheBackupDescriptionReachesTheRecord(string $clientName): void
	{
		$result = $this->makeConnector($clientName, ['apiVersion' => 3])
			->backup(new BackupOptions(['description' => 'A very specific description']));

		$record = $this->makeConnector($clientName, ['apiVersion' => 3])->getBackup((int) $result->id);

		$this->assertSame('A very specific description', $record->description);
	}

	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testListsTheBackupRecords(string $clientName, int $apiVersion): void
	{
		$records = $this->makeConnector($clientName, ['apiVersion' => $apiVersion])->getBackups();

		$this->assertNotEmpty($records);
		$this->assertContains(1, array_map(fn(object $record) => (int) $record->id, $records));
	}

	#[DataProvider('httpClientProvider')]
	public function testPagesThroughTheBackupRecords(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$first  = $connector->getBackups(0, 1);
		$second = $connector->getBackups(1, 1);

		$this->assertCount(1, $first);
		$this->assertCount(1, $second);
		$this->assertNotSame((int) $first[0]->id, (int) $second[0]->id);
	}

	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testReadsOneBackupRecord(string $clientName, int $apiVersion): void
	{
		$record = $this->makeConnector($clientName, ['apiVersion' => $apiVersion])->getBackup(2);

		$this->assertSame(2, (int) $record->id);
		$this->assertSame(3, (int) $record->multipart);
		$this->assertCount(3, $record->filenames);
	}

	#[DataProvider('httpClientProvider')]
	public function testReadingARecordWhichIsNotThereThrows(string $clientName): void
	{
		$this->expectException(NoSuchBackupRecord::class);

		$this->makeConnector($clientName, ['apiVersion' => 3])->getBackup(999999);
	}

	#[DataProvider('httpClientProvider')]
	public function testDeletesTheFilesButKeepsTheRecord(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$connector->deleteFiles(2);

		$this->assertTrue($this->site->hasRecord(2), 'The record itself was deleted');
		$this->assertCount(0, $connector->getBackup(2)->filenames);
	}

	#[DataProvider('httpClientProvider')]
	public function testDeletesTheRecordAsWell(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$this->assertTrue($this->site->hasRecord(1), 'The fixture did not start with the record under test');

		$connector->delete(1);

		$this->assertFalse($this->site->hasRecord(1));
	}
}
