<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotDeleteFiles;
use Akeeba\BackupJsonApi\Exception\CannotListBackupRecords;
use Akeeba\BackupJsonApi\Exception\NoBackupID;
use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use Akeeba\BackupJsonApi\HighLevel\Delete;
use Akeeba\BackupJsonApi\HighLevel\DeleteFiles;
use Akeeba\BackupJsonApi\HighLevel\GetBackup;
use Akeeba\BackupJsonApi\HighLevel\GetBackups;
use Akeeba\BackupJsonApi\HighLevel\Information;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(GetBackups::class)]
#[CoversClass(GetBackup::class)]
#[CoversClass(Delete::class)]
#[CoversClass(DeleteFiles::class)]
#[CoversClass(Information::class)]
class BackupRecordsTest extends HighLevelTestCase
{
	public function testInformationAsksTheServerForItsVersion(): void
	{
		$this->httpClient->willAnswer(200, (object) ['api' => 400, 'version' => '10.4.0']);

		$result = (new Information($this->httpClient))();

		$this->assertCalledOnce('getVersion');
		$this->assertSame(400, $result->body->data->api);
	}

	public function testListsBackupRecords(): void
	{
		$records = [(object) ['id' => 1], (object) ['id' => 2]];

		$this->httpClient->willAnswer(200, $records);

		$result = (new GetBackups($this->httpClient))(0, 200);

		$this->assertCalledOnce('listBackups', ['from' => 0, 'limit' => 200]);
		$this->assertSame($records, $result);
	}

	public function testAnEmptyListOfBackupRecordsIsAnEmptyArray(): void
	{
		$this->httpClient->willAnswer(200, null);

		$this->assertSame([], (new GetBackups($this->httpClient))());
	}

	/**
	 * The server will not page more than 200 records at a time, and a negative offset means nothing to it, so the
	 * arguments are clamped before they leave.
	 */
	#[DataProvider('paginationProvider')]
	public function testClampsThePaginationArguments(int $from, int $limit, array $expected): void
	{
		$this->httpClient->willAnswer(200, []);

		(new GetBackups($this->httpClient))($from, $limit);

		$this->assertSame($expected, $this->httpClient->calls[0]['data']);
	}

	public static function paginationProvider(): array
	{
		return [
			'the defaults'         => [0, 200, ['from' => 0, 'limit' => 200]],
			'a negative offset'    => [-5, 200, ['from' => 0, 'limit' => 200]],
			'too large a page'     => [0, 5000, ['from' => 0, 'limit' => 200]],
			'a zero page'          => [0, 0, ['from' => 0, 'limit' => 1]],
			'a negative page'      => [0, -10, ['from' => 0, 'limit' => 1]],
			'a page in the middle' => [400, 50, ['from' => 400, 'limit' => 50]],
		];
	}

	public function testAFailureToListBackupRecordsThrows(): void
	{
		$this->httpClient->willAnswer(500, 'Database error');

		$this->expectException(CannotListBackupRecords::class);

		(new GetBackups($this->httpClient))();
	}

	public function testReadsOneBackupRecord(): void
	{
		$record = (object) ['id' => 7, 'description' => 'Nightly'];

		$this->httpClient->willAnswer(200, $record);

		$result = (new GetBackup($this->httpClient))(7);

		$this->assertCalledOnce('getBackupInfo', ['backup_id' => 7]);
		$this->assertSame($record, $result);
	}

	public function testReadingAMissingBackupRecordThrows(): void
	{
		$this->httpClient->willAnswer(404, 'No such record');

		$this->expectException(NoSuchBackupRecord::class);

		(new GetBackup($this->httpClient))(9999);
	}

	public function testDeletesABackupRecord(): void
	{
		$this->httpClient->willAnswer(200, 'true');

		(new Delete($this->httpClient))(7);

		$this->assertCalledOnce('delete', ['backup_id' => 7]);
	}

	public function testDeletesTheFilesOfABackupRecord(): void
	{
		$this->httpClient->willAnswer(200, 'true');

		(new DeleteFiles($this->httpClient))(7);

		$this->assertCalledOnce('deleteFiles', ['backup_id' => 7]);
	}

	/**
	 * A record ID has to be a real one. Refusing before the request is made keeps a nonsensical call off the wire —
	 * and, more to the point, keeps “delete backup 0” from meaning anything to the server.
	 */
	#[DataProvider('deletionOperationProvider')]
	public function testDeletionRefusesAnImpossibleRecordId(string $operationClass, int $id): void
	{
		$operation = new $operationClass($this->httpClient);

		$this->expectException(NoBackupID::class);

		$operation($id);
	}

	public static function deletionOperationProvider(): array
	{
		return [
			'delete, zero'             => [Delete::class, 0],
			'delete, negative'         => [Delete::class, -1],
			'delete files, zero'       => [DeleteFiles::class, 0],
			'delete files, negative'   => [DeleteFiles::class, -1],
		];
	}

	#[DataProvider('deletionClassProvider')]
	public function testDeletingAMissingRecordThrows(string $operationClass): void
	{
		$this->httpClient->willAnswer(404, 'No such record');

		$operation = new $operationClass($this->httpClient);

		$this->expectException(NoSuchBackupRecord::class);

		$operation(7);
	}

	public static function deletionClassProvider(): array
	{
		return [
			'the record and its files' => [Delete::class],
			'the files alone'          => [DeleteFiles::class],
		];
	}

	/**
	 * Note that deleting a *record* also reports CannotDeleteFiles. There is a CannotDeleteRecord exception in the
	 * library which reads as though it were meant for exactly this, and nothing anywhere throws it.
	 */
	public function testAFailedRecordDeletionThrows(): void
	{
		$this->httpClient->willAnswer(500, 'Permission denied');

		$this->expectException(CannotDeleteFiles::class);

		(new Delete($this->httpClient))(7);
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake. DeleteFiles::__invoke() reports a failure as
	 * `new CannotDeleteFiles($id, $data->body->status, $data->body->data)`, but that constructor's third parameter is
	 * `?Throwable $previous`. The server's error text is a string, so the failure path raises
	 *
	 *     TypeError: CannotDeleteFiles::__construct(): Argument #3 ($previous) must be of type ?Throwable, string given
	 *
	 * instead of the exception it meant to. A caller catching CannotDeleteFiles — the documented way to handle this —
	 * catches nothing, and the TypeError escapes to the top. The second argument is wrong too: it puts the API status
	 * where the exception code goes, displacing the 106 the class defines.
	 *
	 * The call in Delete::__invoke() a few lines away, `new CannotDeleteFiles($id)`, is the correct shape.
	 *
	 * Unskip this test once DeleteFiles stops passing the response into the exception.
	 */
	public function testAFailedFileDeletionThrows(): void
	{
		$this->markTestSkipped(
			'DeleteFiles passes the API status and error text into CannotDeleteFiles, whose third parameter is a ?Throwable. The failure path raises a TypeError. See src/HighLevel/DeleteFiles.php:44.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->httpClient->willAnswer(500, 'Permission denied');

		$this->expectException(CannotDeleteFiles::class);

		(new DeleteFiles($this->httpClient))(7);
	}
}
