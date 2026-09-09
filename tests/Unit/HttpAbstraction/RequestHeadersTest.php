<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HttpAbstraction;

use Akeeba\BackupJsonApi\Tests\Stub\CannedHttpClient;
use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\HttpAbstraction\AbstractHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(AbstractHttpClient::class)]
class RequestHeadersTest extends UnitTestCase
{
	private function client(array $overrides = []): CannedHttpClient
	{
		return new CannedHttpClient($this->makeOptions($overrides));
	}

	public function testEveryRequestIdentifiesTheClient(): void
	{
		$headers = $this->client(['ua' => 'MyFancyApp/1.2.3'])->exposedRequestHeaders();

		$this->assertSame('MyFancyApp/1.2.3', $headers['User-Agent']);
	}

	/**
	 * The legacy APIs are addressed through the site's frontend, where the credential lives in the query string.
	 * There is nothing to authenticate with in a header, and Joomla's frontend does not want an Accept header either.
	 */
	#[DataProvider('legacyApiVersionProvider')]
	public function testTheLegacyApisAreNotAuthenticatedByHeader(int $apiVersion): void
	{
		$headers = $this->client(['apiVersion' => $apiVersion, 'secret' => 'S3CR3T'])->exposedRequestHeaders();

		$this->assertSame(['User-Agent'], array_keys($headers));
	}

	public static function legacyApiVersionProvider(): array
	{
		return ['v1' => [1], 'v2' => [2]];
	}

	/**
	 * Joomla's API application answers HTTP 406 to a request with no Accept header. Akeeba Backup's webservices
	 * plugin papers over that for its own routes only, so a request which fails to match one gets the raw 406 —
	 * which is a far less legible failure than whatever went wrong actually was.
	 */
	public function testAV3RequestAsksForJson(): void
	{
		$headers = $this->client(['apiVersion' => 3])->exposedRequestHeaders();

		$this->assertSame('application/json', $headers['Accept']);
	}

	public function testAV3RequestPresentsAJoomlaApiTokenWhenItHasOne(): void
	{
		$headers = $this->client(['apiVersion' => 3, 'token' => 'T0K3N', 'secret' => ''])->exposedRequestHeaders();

		$this->assertSame('T0K3N', $headers['X-Joomla-Token']);
		$this->assertArrayNotHasKey('X-Akeeba-Auth', $headers);
	}

	public function testAV3RequestFallsBackToTheSecretWord(): void
	{
		$headers = $this->client(['apiVersion' => 3, 'token' => '', 'secret' => 'S3CR3T'])->exposedRequestHeaders();

		$this->assertSame('S3CR3T', $headers['X-Akeeba-Auth']);
		$this->assertArrayNotHasKey('X-Joomla-Token', $headers);
	}

	/**
	 * The server treats the two credentials as mutually exclusive: a Secret Word in the request makes it ignore any
	 * token, and a *wrong* Secret Word is a hard failure rather than a fall-through to the token. Sending both would
	 * therefore make the token useless. The token wins, because the server enforces its account's privileges per API
	 * method, while the Secret Word is an unscoped grant over the whole component.
	 */
	public function testAV3RequestNeverPresentsBothCredentials(): void
	{
		$headers = $this->client(['apiVersion' => 3, 'token' => 'T0K3N', 'secret' => 'S3CR3T'])
			->exposedRequestHeaders();

		$this->assertSame('T0K3N', $headers['X-Joomla-Token']);
		$this->assertArrayNotHasKey('X-Akeeba-Auth', $headers);
	}

	/**
	 * A credential pasted out of a web page brings whitespace with it more often than not.
	 */
	#[DataProvider('paddedCredentialProvider')]
	public function testCredentialsAreTrimmed(array $overrides, string $header, string $expected): void
	{
		$headers = $this->client(array_merge(['apiVersion' => 3], $overrides))->exposedRequestHeaders();

		$this->assertSame($expected, $headers[$header]);
	}

	public static function paddedCredentialProvider(): array
	{
		return [
			'token' => [['token' => "  T0K3N\n", 'secret' => ''], 'X-Joomla-Token', 'T0K3N'],
			'secret word' => [['token' => '', 'secret' => "  S3CR3T\t"], 'X-Akeeba-Auth', 'S3CR3T'],
		];
	}

	/**
	 * A credential which is nothing but whitespace is no credential at all, and must not be sent as one.
	 */
	public function testAWhitespaceOnlyTokenIsNotPresented(): void
	{
		$headers = $this->client(['apiVersion' => 3, 'token' => '   ', 'secret' => 'S3CR3T'])
			->exposedRequestHeaders();

		$this->assertArrayNotHasKey('X-Joomla-Token', $headers);
		$this->assertSame('S3CR3T', $headers['X-Akeeba-Auth']);
	}
}
