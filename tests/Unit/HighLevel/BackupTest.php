<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HighLevel\Backup;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Backup::class)]
class BackupTest extends HighLevelTestCase
{
	/**
	 * The payload of one backup tick, as the server reports it.
	 *
	 * @param   array  $overrides  The members to change
	 *
	 * @return  object
	 */
	private function tick(array $overrides = []): object
	{
		return (object) array_merge(
			[
				'HasRun'   => true,
				'Domain'   => 'Packing',
				'Step'     => 'Archiving files',
				'Substep'  => 'media/com_akeeba',
				'Progress' => 42.0,
				'Warnings' => [],
				'Error'    => '',
			],
			$overrides
		);
	}

	public function testTakesABackupWhichFinishesInOneStep(): void
	{
		$this->httpClient->willAnswer(
			200,
			$this->tick(['HasRun' => false, 'BackupID' => 7, 'Archive' => 'site-backup.jpa'])
		);

		$result = (new Backup($this->httpClient))(new BackupOptions(['profile' => 5]));

		$this->assertSame(['startBackup'], $this->httpClient->calledMethods());
		$this->assertSame(7, $result->id);
		$this->assertSame('site-backup.jpa', $result->archive);
	}

	public function testStepsTheBackupUntilTheServerSaysItIsDone(): void
	{
		$this->httpClient
			->willAnswer(200, $this->tick(['BackupID' => 7, 'backupid' => 'id123', 'Archive' => 'site-backup.jpa']))
			->willAnswer(200, $this->tick())
			->willAnswer(200, $this->tick(['HasRun' => false]));

		$result = (new Backup($this->httpClient))(new BackupOptions());

		$this->assertSame(['startBackup', 'stepBackup', 'stepBackup'], $this->httpClient->calledMethods());
		$this->assertSame(7, $result->id);
		$this->assertSame('site-backup.jpa', $result->archive);
	}

	/**
	 * The backup ID identifies the run in progress on the server, and it only comes back on the first tick. Every
	 * step after that has to quote it, or the server has no idea which backup is being stepped.
	 */
	public function testEveryStepQuotesTheBackupIdTheServerHandedOut(): void
	{
		$this->httpClient
			->willAnswer(200, $this->tick(['backupid' => 'id123']))
			->willAnswer(200, $this->tick())
			->willAnswer(200, $this->tick(['HasRun' => false]));

		(new Backup($this->httpClient))(new BackupOptions());

		$this->assertSame(['backupid' => 'id123'], $this->httpClient->calls[1]['data']);
		$this->assertSame(['backupid' => 'id123'], $this->httpClient->calls[2]['data']);
	}

	/**
	 * An older server does not hand out a backup ID at all. Sending an empty one would be worse than sending none.
	 */
	public function testAStepCarriesNoBackupIdWhenTheServerNeverGaveOne(): void
	{
		$this->httpClient
			->willAnswer(200, $this->tick())
			->willAnswer(200, $this->tick(['HasRun' => false]));

		(new Backup($this->httpClient))(new BackupOptions());

		$this->assertSame([], $this->httpClient->calls[1]['data']);
	}

	public function testSendsTheBackupOptionsToTheServer(): void
	{
		$this->httpClient->willAnswer(200, $this->tick(['HasRun' => false]));

		(new Backup($this->httpClient))(
			new BackupOptions(
				['profile' => 5, 'description' => 'Nightly', 'comment' => 'Taken by the scheduler']
			)
		);

		$this->assertSame(
			[
				'profile'     => 5,
				'description' => 'Nightly',
				'comment'     => 'Taken by the scheduler',
			],
			$this->httpClient->calls[0]['data']
		);
	}

	/**
	 * A backup record with no description at all is hard to find later, so an empty one is replaced rather than sent.
	 */
	public function testAnEmptyDescriptionIsReplacedWithADefault(): void
	{
		$this->httpClient->willAnswer(200, $this->tick(['HasRun' => false]));

		(new Backup($this->httpClient))(new BackupOptions(['description' => '']));

		$this->assertSame('Remote backup', $this->httpClient->calls[0]['data']['description']);
	}

	public function testTheProfileNumberIsSentAsAnInteger(): void
	{
		$this->httpClient->willAnswer(200, $this->tick(['HasRun' => false]));

		(new Backup($this->httpClient))(new BackupOptions(['profile' => '5']));

		$this->assertSame(5, $this->httpClient->calls[0]['data']['profile']);
	}

	public function testReportsProgressOnEveryTickIncludingTheLast(): void
	{
		$this->httpClient
			->willAnswer(200, $this->tick(['Progress' => 10.0]))
			->willAnswer(200, $this->tick(['Progress' => 50.0]))
			->willAnswer(200, $this->tick(['HasRun' => false, 'Progress' => 100.0]));

		$seen = [];

		(new Backup($this->httpClient))(
			new BackupOptions(),
			function ($data) use (&$seen) {
				$seen[] = $data->Progress;
			}
		);

		$this->assertSame([10.0, 50.0, 100.0], $seen);
	}

	public function testTheProgressCallbackIsOptional(): void
	{
		$this->httpClient->willAnswer(200, $this->tick(['HasRun' => false]));

		$result = (new Backup($this->httpClient))(new BackupOptions());

		$this->assertSame(0, $result->id);
	}

	public function testTheProgressCallbackSeesTheServersWarnings(): void
	{
		$this->httpClient->willAnswer(
			200,
			$this->tick(['HasRun' => false, 'Warnings' => ['Could not read /tmp/somefile']])
		);

		$seen = null;

		(new Backup($this->httpClient))(
			new BackupOptions(),
			function ($data) use (&$seen) {
				$seen = $data->Warnings;
			}
		);

		$this->assertSame(['Could not read /tmp/somefile'], $seen);
	}

	public function testAFailureToStartTheBackupThrows(): void
	{
		$this->httpClient->willAnswer(500, 'Could not create the output directory');

		$this->expectException(RemoteError::class);
		$this->expectExceptionMessage('Could not create the output directory');

		(new Backup($this->httpClient))(new BackupOptions());
	}

	public function testAFailureMidBackupThrows(): void
	{
		$this->httpClient
			->willAnswer(200, $this->tick())
			->willAnswer(500, 'Ran out of disk space');

		$this->expectException(RemoteError::class);
		$this->expectExceptionMessage('Ran out of disk space');

		(new Backup($this->httpClient))(new BackupOptions());
	}

	public function testLogsWhatTheServerToldItAboutTheBackup(): void
	{
		$this->httpClient->willAnswer(
			200,
			$this->tick(['HasRun' => false, 'BackupID' => 7, 'backupid' => 'id123', 'Archive' => 'site.jpa'])
		);

		(new Backup($this->httpClient))(new BackupOptions());

		$this->assertTrue($this->logger->hasMessageContaining('Got backup record ID: 7', 'debug'));
		$this->assertTrue($this->logger->hasMessageContaining('Got backupID: id123', 'debug'));
		$this->assertTrue($this->logger->hasMessageContaining('Got archive name: site.jpa', 'debug'));
	}
}
