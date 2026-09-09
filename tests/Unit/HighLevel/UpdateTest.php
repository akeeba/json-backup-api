<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotGetUpdateInformation;
use Akeeba\BackupJsonApi\Exception\LiveUpdateCleanupError;
use Akeeba\BackupJsonApi\Exception\LiveUpdateDownloadError;
use Akeeba\BackupJsonApi\Exception\LiveUpdateExtractError;
use Akeeba\BackupJsonApi\Exception\LiveUpdateInstallError;
use Akeeba\BackupJsonApi\Exception\LiveUpdateStuck;
use Akeeba\BackupJsonApi\Exception\LiveUpdateSupport;
use Akeeba\BackupJsonApi\Exception\NoUpdates;
use Akeeba\BackupJsonApi\HighLevel\CleanupUpdate;
use Akeeba\BackupJsonApi\HighLevel\DownloadUpdate;
use Akeeba\BackupJsonApi\HighLevel\ExtractUpdate;
use Akeeba\BackupJsonApi\HighLevel\GetUpdateInformation;
use Akeeba\BackupJsonApi\HighLevel\InstallUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(GetUpdateInformation::class)]
#[CoversClass(DownloadUpdate::class)]
#[CoversClass(ExtractUpdate::class)]
#[CoversClass(InstallUpdate::class)]
#[CoversClass(CleanupUpdate::class)]
class UpdateTest extends HighLevelTestCase
{
	/**
	 * The update information the server reports when an update is available and everything is in order.
	 *
	 * @param   array  $overrides  The members to change
	 *
	 * @return  object
	 */
	private function updateInformation(array $overrides = []): object
	{
		return (object) array_merge(
			[
				'supported'   => true,
				'stuck'       => false,
				'hasUpdates'  => true,
				'version'     => '10.4.1',
				'releaseNotes' => 'Bug fixes',
			],
			$overrides
		);
	}

	public function testReadsTheUpdateInformation(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation());

		$result = (new GetUpdateInformation($this->httpClient))();

		$this->assertCalledOnce('updateGetInformation', ['force' => false]);
		$this->assertSame('10.4.1', $result->version);
	}

	public function testAsksTheServerToRecheckWhenForced(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation());

		(new GetUpdateInformation($this->httpClient))(true);

		$this->assertSame(['force' => true], $this->httpClient->calls[0]['data']);
	}

	public function testAFailureToReadTheUpdateInformationThrows(): void
	{
		$this->httpClient->willAnswer(500, 'Boom');

		$this->expectException(CannotGetUpdateInformation::class);

		(new GetUpdateInformation($this->httpClient))();
	}

	public function testAnInstallationWhichCannotUpdateItselfThrows(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation(['supported' => false]));

		$this->expectException(LiveUpdateSupport::class);

		(new GetUpdateInformation($this->httpClient))();
	}

	public function testAStuckUpdaterThrowsAndSuggestsForcingIt(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation(['stuck' => true]));

		$this->expectException(LiveUpdateStuck::class);
		$this->expectExceptionMessage('--force=1');

		(new GetUpdateInformation($this->httpClient))();
	}

	/**
	 * Once the caller has already forced a recheck there is nothing left to suggest, so the advice is dropped rather
	 * than repeated at somebody who has already taken it.
	 */
	public function testAStuckUpdaterDoesNotSuggestForcingItTwice(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation(['stuck' => true]));

		$this->expectException(LiveUpdateStuck::class);
		$this->expectExceptionMessageMatches('/^((?!--force=1).)*$/s');

		(new GetUpdateInformation($this->httpClient))(true);
	}

	/**
	 * “Up to date” is reported as an exception rather than a return value. It is not an error, so a caller which does
	 * not want to hear about it has to catch it.
	 */
	public function testBeingUpToDateThrows(): void
	{
		$this->httpClient->willAnswer(200, $this->updateInformation(['hasUpdates' => false]));

		$this->expectException(NoUpdates::class);

		(new GetUpdateInformation($this->httpClient))();
	}

	#[DataProvider('updateStepProvider')]
	public function testRunsAnUpdateStep(string $operationClass, string $apiMethod): void
	{
		$this->httpClient->willAnswer(200, 'true');

		$operation = new $operationClass($this->httpClient);

		$operation();

		$this->assertCalledOnce($apiMethod);
	}

	#[DataProvider('updateStepProvider')]
	public function testAFailedUpdateStepThrows(
		string $operationClass, string $apiMethod, string $exceptionClass
	): void
	{
		$this->httpClient->willAnswer(500, 'Could not write to the directory');

		$operation = new $operationClass($this->httpClient);

		$this->expectException($exceptionClass);
		$this->expectExceptionMessage('Could not write to the directory');

		$operation();
	}

	public static function updateStepProvider(): array
	{
		return [
			'download' => [DownloadUpdate::class, 'updateDownload', LiveUpdateDownloadError::class],
			'extract'  => [ExtractUpdate::class, 'updateExtract', LiveUpdateExtractError::class],
			'install'  => [InstallUpdate::class, 'updateInstall', LiveUpdateInstallError::class],
			'cleanup'  => [CleanupUpdate::class, 'updateCleanup', LiveUpdateCleanupError::class],
		];
	}
}
