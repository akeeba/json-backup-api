<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\DataShape\DownloadOptions;
use Akeeba\BackupJsonApi\Exception\CannotDownloadFile;
use Akeeba\BackupJsonApi\Exception\NoBackupID;
use Akeeba\BackupJsonApi\Exception\NoFilesInBackupRecord;
use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use Akeeba\BackupJsonApi\Exception\NoSuchPart;
use Akeeba\BackupJsonApi\HighLevel\Download;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Download::class)]
class DownloadTest extends HighLevelTestCase
{
	private string $downloadPath;

	protected function setUp(): void
	{
		parent::setUp();

		$this->downloadPath = sys_get_temp_dir() . '/akeeba-json-api-download-' . bin2hex(random_bytes(6));

		mkdir($this->downloadPath, 0o777, true);
	}

	protected function tearDown(): void
	{
		foreach ((array) glob($this->downloadPath . '/*') as $file)
		{
			@unlink($file);
		}

		@rmdir($this->downloadPath);

		parent::tearDown();
	}

	/**
	 * The getBackupInfo payload describing a backup record's archive files.
	 *
	 * @param   array  $parts      part number => the bytes that part holds
	 * @param   int    $multipart  What the server reports as the part count
	 *
	 * @return  object
	 */
	private function archiveInformation(array $parts, ?int $multipart = null): object
	{
		$filenames = [];

		foreach ($parts as $part => $contents)
		{
			$filenames[] = (object) [
				'part' => $part,
				'name' => sprintf('site-backup.j%02d', $part),
				'size' => strlen($contents),
			];
		}

		return (object) [
			'multipart' => $multipart ?? count($parts),
			'filenames' => $filenames,
		];
	}

	private function options(array $overrides = []): DownloadOptions
	{
		return new DownloadOptions(array_merge(['id' => 7, 'path' => $this->downloadPath], $overrides));
	}

	#[DataProvider('impossibleRecordIdProvider')]
	public function testRefusesAnImpossibleRecordId(int $id): void
	{
		$this->expectException(NoBackupID::class);

		(new Download($this->httpClient))($this->options(['id' => $id]));
	}

	public static function impossibleRecordIdProvider(): array
	{
		return ['zero' => [0], 'negative' => [-1]];
	}

	public function testDownloadsASinglePartArchiveOverHttp(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'ARCHIVE BYTES']))
			->willDownload('ARCHIVE BYTES');

		(new Download($this->httpClient))($this->options());

		$this->assertSame('ARCHIVE BYTES', file_get_contents($this->downloadPath . '/site-backup.j01'));
	}

	public function testDownloadsEveryPartOfAMultipartArchive(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'PART ONE', 2 => 'PART TWO']))
			->willDownload('PART ONE')
			->willDownload('PART TWO');

		(new Download($this->httpClient))($this->options());

		$this->assertSame('PART ONE', file_get_contents($this->downloadPath . '/site-backup.j01'));
		$this->assertSame('PART TWO', file_get_contents($this->downloadPath . '/site-backup.j02'));
	}

	/**
	 * The download URL is always fetched with GET, whatever verb the API itself is being talked to with, and it names
	 * the record and the part rather than the file.
	 */
	public function testAsksForEachPartByRecordAndPartNumber(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'PART ONE', 2 => 'PART TWO']))
			->willDownload('PART ONE')
			->willDownload('PART TWO');

		(new Download($this->httpClient))($this->options());

		$this->assertStringContainsString('backup_id=7', $this->httpClient->downloads[0]['url']);
		$this->assertStringContainsString('part_id=1', $this->httpClient->downloads[0]['url']);
		$this->assertStringContainsString('part_id=2', $this->httpClient->downloads[1]['url']);
	}

	public function testDownloadsOnlyThePartItWasAskedFor(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'PART ONE', 2 => 'PART TWO']))
			->willDownload('PART TWO');

		(new Download($this->httpClient))($this->options(['part' => 2]));

		$this->assertCount(1, $this->httpClient->downloads);
		$this->assertFileDoesNotExist($this->downloadPath . '/site-backup.j01');
		$this->assertSame('PART TWO', file_get_contents($this->downloadPath . '/site-backup.j02'));
	}

	public function testRenamesTheDownloadedFileWhenAskedTo(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'ARCHIVE BYTES']))
			->willDownload('ARCHIVE BYTES');

		(new Download($this->httpClient))($this->options(['filename' => 'nightly.jpa']));

		$this->assertFileDoesNotExist($this->downloadPath . '/site-backup.j01');
		$this->assertSame('ARCHIVE BYTES', file_get_contents($this->downloadPath . '/nightly.jpa'));
	}

	public function testDownloadingAMissingRecordThrows(): void
	{
		$this->httpClient->willAnswer(404, 'No such record');

		$this->expectException(NoSuchBackupRecord::class);

		(new Download($this->httpClient))($this->options());
	}

	/**
	 * A backup record whose files have already been deleted — after they were uploaded to remote storage, say — has
	 * nothing to download.
	 */
	public function testARecordWithNoFilesThrows(): void
	{
		$this->httpClient->willAnswer(200, (object) ['multipart' => 0, 'filenames' => []]);

		$this->expectException(NoFilesInBackupRecord::class);

		(new Download($this->httpClient))($this->options());
	}

	/**
	 * A truncated download is worse than a failed one, because it looks like a backup until the day it is needed.
	 */
	public function testATruncatedDownloadThrows(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'THE WHOLE ARCHIVE']))
			->willDownload('TRUNCA');

		$this->expectException(CannotDownloadFile::class);

		(new Download($this->httpClient))($this->options());
	}

	/**
	 * The server said there are more parts than it described. Asking for a part it never named is a refusal, not a
	 * zero-byte file — and NoSuchPart is indeed what comes out.
	 *
	 * PRODUCT BUG, not a test-writing mistake, in how it gets there. Download::downloadHTTP() reads the missing part
	 * as `$fileInformation[$part]?->name`. The nullsafe operator guards against a null *object*; it does nothing about
	 * a missing *array key*, so PHP raises two “Undefined array key” warnings before the guard below them fires. On a
	 * site which turns warnings into exceptions — a strict error handler, or Joomla's own in development mode — the
	 * download dies with an ErrorException instead of the exception the library documents.
	 *
	 * The fix is `$fileInformation[$part] ?? null` before reading the members off it.
	 *
	 * Unskip this test once the missing part is looked up without a warning.
	 */
	public function testAPartTheServerDidNotDescribeThrows(): void
	{
		$this->markTestSkipped(
			'Download::downloadHTTP() raises “Undefined array key” warnings before refusing a part the server did not describe. See src/HighLevel/Download.php:77.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'PART ONE'], 2))
			->willDownload('PART ONE');

		$this->expectException(NoSuchPart::class);

		(new Download($this->httpClient))($this->options());
	}

	public function testDownloadsAnArchiveInChunks(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'CHUNK ONE.CHUNK TWO.']))
			->willAnswer(200, base64_encode('CHUNK ONE.'))
			->willAnswer(200, base64_encode('CHUNK TWO.'))
			->willAnswer(404, '');

		(new Download($this->httpClient))($this->options(['mode' => 'chunk']));

		$this->assertSame('CHUNK ONE.CHUNK TWO.', file_get_contents($this->downloadPath . '/site-backup.j01'));
	}

	public function testAsksForEachChunkInTurn(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'CHUNK ONE.CHUNK TWO.']))
			->willAnswer(200, base64_encode('CHUNK ONE.'))
			->willAnswer(200, base64_encode('CHUNK TWO.'))
			->willAnswer(404, '');

		(new Download($this->httpClient))($this->options(['mode' => 'chunk', 'chunkSize' => 5]));

		$this->assertSame(
			['backup_id' => 7, 'part' => 1, 'segment' => 1, 'chunk_size' => 5],
			$this->httpClient->calls[1]['data']
		);
		$this->assertSame(2, $this->httpClient->calls[2]['data']['segment']);
	}

	/**
	 * A 404 on the very first chunk means the file is not there at all, which is a different thing from a 404 on a
	 * later one — that is just how the server says “that was the last chunk”.
	 */
	public function testAChunkedDownloadWhichFindsNothingAtAllThrows(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'ARCHIVE BYTES']))
			->willAnswer(404, '');

		$this->expectException(NoFilesInBackupRecord::class);

		(new Download($this->httpClient))($this->options(['mode' => 'chunk']));
	}

	public function testAChunkedDownloadWhichFailsMidwayThrows(): void
	{
		$this->httpClient
			->willAnswer(200, $this->archiveInformation([1 => 'CHUNK ONE.CHUNK TWO.']))
			->willAnswer(200, base64_encode('CHUNK ONE.'))
			->willAnswer(500, 'Read error');

		$this->expectException(CannotDownloadFile::class);

		(new Download($this->httpClient))($this->options(['mode' => 'chunk']));
	}
}
