<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotListProfiles;
use Akeeba\BackupJsonApi\Exception\NoProfileData;
use Akeeba\BackupJsonApi\Exception\NoProfileID;
use Akeeba\BackupJsonApi\HighLevel\ExportConfiguration;
use Akeeba\BackupJsonApi\HighLevel\GetProfiles;
use Akeeba\BackupJsonApi\HighLevel\ImportConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(GetProfiles::class)]
#[CoversClass(ExportConfiguration::class)]
#[CoversClass(ImportConfiguration::class)]
class ProfilesTest extends HighLevelTestCase
{
	public function testListsTheBackupProfiles(): void
	{
		$profiles = [(object) ['id' => 1, 'name' => 'Default'], (object) ['id' => 5, 'name' => 'Nightly']];

		$this->httpClient->willAnswer(200, $profiles);

		$result = (new GetProfiles($this->httpClient))();

		$this->assertCalledOnce('getProfiles');
		$this->assertSame($profiles, $result);
	}

	public function testAFailureToListProfilesThrows(): void
	{
		$this->httpClient->willAnswer(403, 'Not allowed');

		$this->expectException(CannotListProfiles::class);

		(new GetProfiles($this->httpClient))();
	}

	public function testExportsAProfile(): void
	{
		$configuration = (object) ['akeeba.basic.output_directory' => '/backups'];

		$this->httpClient->willAnswer(200, $configuration);

		$result = (new ExportConfiguration($this->httpClient))(5);

		$this->assertCalledOnce('exportConfiguration', ['profile' => 5]);
		$this->assertSame($configuration, $result);
	}

	#[DataProvider('impossibleProfileIdProvider')]
	public function testExportRefusesAnImpossibleProfileId(int $id): void
	{
		$this->expectException(NoProfileID::class);

		(new ExportConfiguration($this->httpClient))($id);
	}

	public static function impossibleProfileIdProvider(): array
	{
		return [
			'zero'        => [0],
			'negative'    => [-1],
			'the default' => [-1],
		];
	}

	public function testImportsAProfile(): void
	{
		$this->httpClient->willAnswer(200, ['id' => 9]);

		$result = (new ImportConfiguration($this->httpClient))('{"description":"Imported"}');

		$this->assertSame('importConfiguration', $this->httpClient->calls[0]['method']);
		$this->assertSame(['id' => 9], $result);
	}

	/**
	 * Profile 0 means “make me a new one”. The JSON arrives as a string and is decoded before it is sent, because the
	 * API takes a structure, not a string containing one.
	 */
	public function testImportCreatesANewProfileFromDecodedJson(): void
	{
		$this->httpClient->willAnswer(200, ['id' => 9]);

		(new ImportConfiguration($this->httpClient))('{"description":"Imported","profile":3}');

		$payload = $this->httpClient->calls[0]['data'];

		$this->assertSame(0, $payload['profile']);
		$this->assertSame('Imported', $payload['data']->description);
	}

	public function testImportRefusesAnEmptyProfile(): void
	{
		$this->expectException(NoProfileData::class);

		(new ImportConfiguration($this->httpClient))('');
	}
}
