<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit;

use Akeeba\BackupJsonApi\Tests\Stub\SpyHttpClient;
use Akeeba\BackupJsonApi\Connector;
use BadMethodCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Connector::class)]
class ConnectorTest extends UnitTestCase
{
	private SpyHttpClient $httpClient;

	private Connector $connector;

	protected function setUp(): void
	{
		parent::setUp();

		$this->httpClient = new SpyHttpClient($this->makeOptions());
		$this->connector  = new Connector($this->httpClient);
	}

	/**
	 * Every method of the connector is a class under HighLevel, reached by name. The docblock on Connector is the
	 * public list of them, so each one has to actually resolve.
	 */
	#[DataProvider('documentedMethodProvider')]
	public function testEveryDocumentedMethodResolvesToAHighLevelOperation(string $method): void
	{
		$this->assertTrue(
			class_exists('Akeeba\\BackupJsonApi\\HighLevel\\' . ucfirst($method)),
			sprintf('Connector::%s() has no HighLevel class behind it', $method)
		);
	}

	public static function documentedMethodProvider(): array
	{
		$methods = [
			'autodetect', 'information', 'backup', 'getBackups', 'getBackup', 'deleteFiles', 'delete', 'download',
			'getProfiles', 'importConfiguration', 'exportConfiguration', 'getUpdateInformation', 'downloadUpdate',
			'extractUpdate', 'installUpdate', 'cleanupUpdate',
		];

		return array_combine($methods, array_map(fn(string $method) => [$method], $methods));
	}

	public function testDispatchesACallToTheHighLevelOperation(): void
	{
		$this->httpClient->willAnswer(200, (object) ['api' => 400]);

		$result = $this->connector->information();

		$this->assertSame(['getVersion'], $this->httpClient->calledMethods());
		$this->assertSame(400, $result->body->data->api);
	}

	public function testPassesItsArgumentsThrough(): void
	{
		$this->httpClient->willAnswer(200, [(object) ['id' => 1]]);

		$this->connector->getBackups(10, 25);

		$this->assertSame(['from' => 10, 'limit' => 25], $this->httpClient->calls[0]['data']);
	}

	public function testAnUnknownMethodIsARefusalRatherThanASilentNoOp(): void
	{
		$this->expectException(BadMethodCallException::class);
		$this->expectExceptionMessage('nosuchmethod');
		$this->expectExceptionCode(255);

		/** @noinspection PhpUndefinedMethodInspection */
		$this->connector->nosuchmethod();
	}

	/**
	 * The operation objects are built once and kept. Backup and Download read the logger out of the options in their
	 * constructor, so rebuilding them per call would be wasteful as well as surprising.
	 */
	public function testTheOperationObjectIsReusedAcrossCalls(): void
	{
		$this->httpClient->willAnswer(200, (object) ['api' => 400]);
		$this->httpClient->willAnswer(200, (object) ['api' => 400]);

		$this->connector->information();
		$this->connector->information();

		$this->assertSame(['getVersion', 'getVersion'], $this->httpClient->calledMethods());
	}
}
