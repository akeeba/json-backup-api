<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\DataShape;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\DataShape\DownloadOptions;
use Akeeba\BackupJsonApi\Exception\NoDownloadMode;
use Akeeba\BackupJsonApi\Exception\NoDownloadPath;
use Akeeba\BackupJsonApi\Exception\NoDownloadURL;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(DownloadOptions::class)]
class DownloadOptionsTest extends UnitTestCase
{
	private string $downloadPath;

	protected function setUp(): void
	{
		parent::setUp();

		$this->downloadPath = sys_get_temp_dir() . '/akeeba-json-api-tests-' . bin2hex(random_bytes(6));

		mkdir($this->downloadPath, 0o777, true);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->downloadPath))
		{
			rmdir($this->downloadPath);
		}

		parent::tearDown();
	}

	public function testAppliesTheDefaults(): void
	{
		$options = new DownloadOptions(['path' => $this->downloadPath]);

		$this->assertSame('http', $options->mode);
		$this->assertSame($this->downloadPath, $options->path);
		$this->assertSame(0, $options->id);
		$this->assertSame('', $options->filename);
		$this->assertFalse($options->delete);
		$this->assertSame(0, $options->chunkSize);

		// A negative part number means “every part”, which is not the same as part zero.
		$this->assertSame(-1, $options->part);
	}

	#[DataProvider('validModeProvider')]
	public function testAcceptsEveryDownloadModeItSupports(string $mode, array $extra): void
	{
		$options = new DownloadOptions(array_merge(['mode' => $mode, 'path' => $this->downloadPath], $extra));

		$this->assertSame($mode, $options->mode);
	}

	public static function validModeProvider(): array
	{
		return [
			'http'  => ['http', []],
			'chunk' => ['chunk', []],
			'curl'  => ['curl', ['url' => 'https://www.example.com/backups']],
		];
	}

	public function testRejectsAnUnknownDownloadMode(): void
	{
		$this->expectException(NoDownloadMode::class);

		new DownloadOptions(['mode' => 'carrier-pigeon', 'path' => $this->downloadPath]);
	}

	public function testRejectsAPathWhichIsNotADirectory(): void
	{
		$this->expectException(NoDownloadPath::class);

		new DownloadOptions(['path' => $this->downloadPath . '/nosuchdirectory']);
	}

	public function testRejectsAnEmptyPath(): void
	{
		$this->expectException(NoDownloadPath::class);

		new DownloadOptions(['path' => '']);
	}

	public function testStripsATrailingSlashFromThePath(): void
	{
		$options = new DownloadOptions(['path' => $this->downloadPath . '/']);

		$this->assertSame($this->downloadPath, $options->path);
	}

	public function testChunkModeGetsAUsableChunkSize(): void
	{
		// Anything at or below 1 MiB per chunk is treated as “not set”, and becomes the 10 MiB default.
		$this->assertSame(10, (new DownloadOptions(['mode' => 'chunk', 'path' => $this->downloadPath]))->chunkSize);
		$this->assertSame(
			10,
			(new DownloadOptions(['mode' => 'chunk', 'path' => $this->downloadPath, 'chunkSize' => 1]))->chunkSize
		);
	}

	public function testChunkModeKeepsAChunkSizeItWasGiven(): void
	{
		$options = new DownloadOptions(['mode' => 'chunk', 'path' => $this->downloadPath, 'chunkSize' => 25]);

		$this->assertSame(25, $options->chunkSize);
	}

	public function testCurlModeNeedsADownloadUrl(): void
	{
		$this->expectException(NoDownloadURL::class);

		new DownloadOptions(['mode' => 'curl', 'path' => $this->downloadPath]);
	}

	/**
	 * cURL takes the credentials separately, through CURLOPT_USERPWD, so they have to come out of the URL.
	 */
	#[DataProvider('curlUrlProvider')]
	public function testCurlModeSplitsTheCredentialOutOfTheUrl(
		string $given, string $expectedUrl, string $expectedAuthentication
	): void
	{
		$options = new DownloadOptions(['mode' => 'curl', 'path' => $this->downloadPath, 'url' => $given]);

		$this->assertSame($expectedUrl, $options->url);
		$this->assertSame($expectedAuthentication, $options->authentication);
	}

	public static function curlUrlProvider(): array
	{
		return [
			'user and password'      => [
				'ftp://user:password@ftp.example.com/path',
				'ftp://ftp.example.com/path',
				'user:password',
			],
			'trailing slash'         => [
				'ftp://user:password@ftp.example.com/path/',
				'ftp://ftp.example.com/path',
				'user:password',
			],
			'no credential at all'   => [
				'https://www.example.com/backups',
				'https://www.example.com/backups',
				'',
			],
			// Without a colon there is no password, so there is nothing this could hand to CURLOPT_USERPWD.
			'a user but no password' => [
				'ftp://user@ftp.example.com/path',
				'ftp://user@ftp.example.com/path',
				'',
			],
			// The last at-sign wins, so a password containing one survives.
			'an at-sign in the password' => [
				'ftp://us:er:pa@ss@ftp.example.com/path',
				'ftp://ftp.example.com/path',
				'us:er:pa@ss',
			],
		];
	}

	/**
	 * The authentication is a product of parsing a cURL URL, so it does not exist in the other two modes. Reading it
	 * there is a programming error, and the strict data object says so rather than handing back an empty string.
	 */
	public function testThereIsNoAuthenticationOutsideCurlMode(): void
	{
		$options = new DownloadOptions(['mode' => 'http', 'path' => $this->downloadPath]);

		$this->expectException(OutOfRangeException::class);

		/** @noinspection PhpExpressionResultUnusedInspection */
		$options->authentication;
	}
}
