<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use Akeeba\BackupJsonApi\DataShape\DownloadOptions;
use Akeeba\BackupJsonApi\Exception\CannotDownloadFile;
use Akeeba\BackupJsonApi\Exception\NoFilesInBackupRecord;
use Akeeba\BackupJsonApi\Exception\UnsafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Getting an archive off the server, byte for byte.
 *
 * A download is the one operation where being almost right is worse than failing: a truncated archive looks like a
 * backup right up until the day somebody needs it. Every assertion here is against the checksum of what the server
 * holds, not against the size of what arrived.
 */
#[Group('e2e')]
class ArchiveDownloadTest extends E2ETestCase
{
	private string $downloadPath;

	protected function setUp(): void
	{
		parent::setUp();

		$this->downloadPath = sys_get_temp_dir() . '/akeeba-e2e-download-' . bin2hex(random_bytes(6));

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
	 * Asserts a downloaded file is byte for byte what the server holds.
	 *
	 * @param   string  $fileName  The file in the download directory
	 * @param   int     $recordId  The backup record it came from
	 * @param   int     $part      The part number it is
	 *
	 * @return  void
	 */
	private function assertPartDownloadedIntact(string $fileName, int $recordId, int $part): void
	{
		$path     = $this->downloadPath . '/' . $fileName;
		$expected = $this->site->archivePart($recordId, $part);

		$this->assertFileExists($path);
		$this->assertSame($expected['size'], filesize($path), 'The downloaded part is the wrong length');
		$this->assertSame($expected['sha256'], hash_file('sha256', $path), 'The downloaded part is corrupt');
	}

	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testDownloadsASinglePartArchive(string $clientName, int $apiVersion): void
	{
		$this->makeConnector($clientName, ['apiVersion' => $apiVersion])->download(
			new DownloadOptions(['id' => 1, 'path' => $this->downloadPath, 'mode' => 'http'])
		);

		$this->assertPartDownloadedIntact('single-2026-01-01.jpa', 1, 1);
	}

	#[DataProvider('httpClientProvider')]
	public function testDownloadsEveryPartOfAMultipartArchive(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(['id' => 2, 'path' => $this->downloadPath, 'mode' => 'http'])
		);

		$this->assertPartDownloadedIntact('multi-2026-01-02.j01', 2, 1);
		$this->assertPartDownloadedIntact('multi-2026-01-02.j02', 2, 2);
		$this->assertPartDownloadedIntact('multi-2026-01-02.jpa', 2, 3);
	}

	#[DataProvider('httpClientProvider')]
	public function testDownloadsOnlyThePartItWasAskedFor(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(['id' => 2, 'path' => $this->downloadPath, 'mode' => 'http', 'part' => 2])
		);

		$this->assertPartDownloadedIntact('multi-2026-01-02.j02', 2, 2);
		$this->assertFileDoesNotExist($this->downloadPath . '/multi-2026-01-02.j01');
	}

	/**
	 * The chunked mode fetches the archive through the API itself, base64 encoded, a slice at a time. It exists for
	 * servers where a direct download is not an option, and it has to produce the same bytes.
	 */
	#[DataProvider('httpClientProvider')]
	public function testDownloadsAnArchiveInChunks(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(['id' => 1, 'path' => $this->downloadPath, 'mode' => 'chunk', 'chunkSize' => 2])
		);

		$this->assertPartDownloadedIntact('single-2026-01-01.jpa', 1, 1);
	}

	#[DataProvider('httpClientProvider')]
	public function testRenamesTheDownloadedFileWhenAskedTo(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(
				['id' => 1, 'path' => $this->downloadPath, 'mode' => 'http', 'filename' => 'nightly.jpa']
			)
		);

		$this->assertPartDownloadedIntact('nightly.jpa', 1, 1);
		$this->assertFileDoesNotExist($this->downloadPath . '/single-2026-01-01.jpa');
	}

	#[DataProvider('httpClientProvider')]
	public function testARecordWhoseFilesAreGoneCannotBeDownloaded(string $clientName): void
	{
		$this->expectException(NoFilesInBackupRecord::class);

		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(['id' => 3, 'path' => $this->downloadPath, 'mode' => 'http'])
		);
	}

	/**
	 * A redirect response has a body of its own, and the download clients write whatever arrives straight into the
	 * file. Somebody has to throw that away before the next hop, or the courtesy page ends up prepended to the
	 * archive — which is a corrupt backup that is exactly the right length to look plausible.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARedirectMidDownloadDoesNotEndUpInTheArchive(string $clientName): void
	{
		// The first request is getBackupInfo, the second is the archive itself. Arm both.
		$this->site->armRedirect('http://example.test{uri}', 302, 2);

		$this->makeConnector($clientName, ['apiVersion' => 3])->download(
			new DownloadOptions(['id' => 1, 'path' => $this->downloadPath, 'mode' => 'http'])
		);

		$this->assertPartDownloadedIntact('single-2026-01-01.jpa', 1, 1);
	}

	/**
	 * The download URL carries no credential of its own on the v3 API — it is authenticated by header like everything
	 * else — so a redirect off the domain would disclose it just as an API call would.
	 */
	#[DataProvider('httpClientProvider')]
	public function testADownloadWillNotFollowARedirectOffTheDomain(string $clientName): void
	{
		$this->site->armRedirect('http://evil.test/sink.php', 302, 1);

		try
		{
			$this->makeConnector(
				$clientName,
				['apiVersion' => 3, 'secret' => '', 'token' => $this->config('token')]
			)->download(
				new DownloadOptions(['id' => 1, 'path' => $this->downloadPath, 'mode' => 'http'])
			);

			$this->fail('The download followed a redirect off its domain');
		}
		catch (CannotDownloadFile $e)
		{
			// Download wraps whatever went wrong; the reason has to still be legible underneath.
			$this->assertInstanceOf(UnsafeRedirect::class, $e->getPrevious());
		}
		catch (UnsafeRedirect)
		{
			// Refused before the download proper began, which is just as good.
		}

		$this->assertNothingReachedTheSink();
	}
}
