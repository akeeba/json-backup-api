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
use Akeeba\BackupJsonApi\Uri\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(AbstractHttpClient::class)]
class UrlBuilderTest extends UnitTestCase
{
	/**
	 * Builds a client whose transport is never used, so only the URL construction is exercised.
	 *
	 * @param   array  $overrides  Option overrides
	 *
	 * @return  CannedHttpClient
	 */
	private function client(array $overrides = []): CannedHttpClient
	{
		return new CannedHttpClient($this->makeOptions(array_merge(['secret' => 'S3CR3T'], $overrides)));
	}

	#[DataProvider('apiVersionProvider')]
	public function testWorksOutWhichApiVersionToSpeak(array $overrides, int $expected): void
	{
		$this->assertSame($expected, $this->client($overrides)->exposedApiVersion());
	}

	public static function apiVersionProvider(): array
	{
		return [
			'pinned to v1'                     => [['apiVersion' => 1], 1],
			'pinned to v2'                     => [['apiVersion' => 2], 2],
			'pinned to v3'                     => [['apiVersion' => 3], 3],
			// Nobody said, and a Joomla! endpoint could speak the newest version.
			'unspecified, Joomla!'             => [[], 3],
			// Akeeba Solo has no Joomla! API application, so v2 is the newest it could speak.
			'unspecified, Akeeba Solo'         => [['host' => 'https://www.example.com/remote.php'], 2],
			'unspecified, WordPress'           => [['host' => 'https://www.example.com/wp-admin/admin-ajax.php'], 2],
		];
	}

	public function testBuildsAV3UrlAsARouteOfJoomlasApiApplication(): void
	{
		$url = $this->client(['apiVersion' => 3])->makeURL('getBackupInfo', ['backup_id' => 123]);

		$this->assertSame(
			'https://www.example.com/api/index.php/v3/akeebabackup/getBackupInfo?backup_id=123',
			$url
		);
	}

	public function testBuildsAV3UrlAgainstTheRewrittenApiEndpoint(): void
	{
		$url = $this->client(['apiVersion' => 3, 'apiEndpoint' => 'api'])->makeURL('getVersion');

		$this->assertSame('https://www.example.com/api/v3/akeebabackup/getVersion', $url);
	}

	/**
	 * The credential is the whole point of the v3 URL shape: it travels in a header, so it must not appear anywhere in
	 * the URL, where the server's access log, a proxy cache, or a browser history would keep it.
	 */
	#[DataProvider('v3CredentialProvider')]
	public function testAV3UrlNeverCarriesTheCredential(array $credential, string $secretValue): void
	{
		$client = $this->client(array_merge(['apiVersion' => 3], $credential));

		foreach (['GET', 'POST'] as $verb)
		{
			$this->assertStringNotContainsString(
				$secretValue,
				$client->makeURL('getVersion', ['foo' => 'bar'], $verb),
				sprintf('The %s URL leaked the credential', $verb)
			);
		}
	}

	public static function v3CredentialProvider(): array
	{
		return [
			'Secret Word'       => [['secret' => 'S3CR3T', 'token' => ''], 'S3CR3T'],
			'Joomla! API token' => [['secret' => '', 'token' => 'T0K3N'], 'T0K3N'],
		];
	}

	/**
	 * A POST carries its payload in the request body, so nothing but the route belongs in the URL.
	 */
	public function testAV3PostUrlCarriesNoPayload(): void
	{
		$url = $this->client(['apiVersion' => 3, 'verb' => 'POST'])->makeURL('getBackupInfo', ['backup_id' => 123]);

		$this->assertSame('https://www.example.com/api/index.php/v3/akeebabackup/getBackupInfo', $url);
	}

	public function testTheApiMethodIsUrlEncodedIntoTheV3Route(): void
	{
		$url = $this->client(['apiVersion' => 3])->makeURL('not a/method');

		$this->assertStringContainsString('/v3/akeebabackup/not%20a%2Fmethod', $url);
	}

	public function testTheVerbArgumentOverridesTheConfiguredVerb(): void
	{
		$client = $this->client(['apiVersion' => 3, 'verb' => 'POST']);

		// Download URLs are always fetched with GET, whatever the API is being talked to with.
		$this->assertStringContainsString(
			'?backup_id=123',
			$client->makeURL('downloadDirect', ['backup_id' => 123], 'GET')
		);
	}

	public function testBuildsAV2UrlAgainstTheFrontendEndpoint(): void
	{
		$url = $this->client(['apiVersion' => 2])->makeURL('getBackupInfo', ['backup_id' => 123]);
		$uri = new Uri($url);

		$this->assertSame('https://www.example.com/index.php', $uri->toString(['scheme', 'host', 'path']));
		$this->assertSame('Api', $uri->getVar('view'));
		$this->assertSame('getBackupInfo', $uri->getVar('method'));
		$this->assertSame('123', $uri->getVar('backup_id'));
	}

	/**
	 * The v2 API has no header authentication. The Secret Word is a query string parameter, and it is there whether
	 * the request is a GET or a POST, because it addresses the endpoint rather than the method.
	 */
	#[DataProvider('verbProvider')]
	public function testAV2UrlCarriesTheSecretWordInTheQueryString(string $verb): void
	{
		$url = $this->client(['apiVersion' => 2, 'verb' => $verb])->makeURL('getVersion');

		$this->assertSame('S3CR3T', (new Uri($url))->getVar('_akeebaAuth'));
	}

	public static function verbProvider(): array
	{
		return ['GET' => ['GET'], 'POST' => ['POST']];
	}

	public function testAV2PostUrlCarriesNothingButTheAuthentication(): void
	{
		$url = $this->client(['apiVersion' => 2, 'verb' => 'POST'])->makeURL('getBackupInfo', ['backup_id' => 123]);

		$this->assertSame('https://www.example.com/index.php?_akeebaAuth=S3CR3T', $url);
	}

	public function testAV2UrlCarriesTheComponentAndFormatWhenConfigured(): void
	{
		$url = $this->client(['apiVersion' => 2, 'component' => 'com_akeeba', 'format' => 'json'])
			->makeURL('getVersion');
		$uri = new Uri($url);

		$this->assertSame('com_akeeba', $uri->getVar('option'));
		$this->assertSame('json', $uri->getVar('format'));
		$this->assertFalse($uri->hasVar('tmpl'));
	}

	/**
	 * The HTML format renders inside the site's template, and a third party plugin injecting into that template would
	 * corrupt the response. tmpl=component is what keeps Joomla! from wrapping the output.
	 */
	public function testTheHtmlFormatAsksJoomlaNotToWrapTheResponseInATemplate(): void
	{
		$url = $this->client(['apiVersion' => 2, 'component' => 'com_akeeba', 'format' => 'html'])
			->makeURL('getVersion');

		$this->assertSame('component', (new Uri($url))->getVar('tmpl'));
	}

	public function testTmplIsOnlyAddedWhenThereIsAComponentToWrap(): void
	{
		$url = $this->client(['apiVersion' => 2, 'component' => '', 'format' => 'html'])->makeURL('getVersion');

		$this->assertFalse((new Uri($url))->hasVar('tmpl'));
	}

	/**
	 * WordPress routes everything through admin-ajax.php, which dispatches on the `action` parameter. The Joomla!
	 * parameters mean nothing there and are stripped.
	 */
	public function testAWordPressUrlIsAddressedByActionAndCarriesNoJoomlaParameters(): void
	{
		$url = $this->client(
			[
				'apiVersion' => 2,
				'host'       => 'https://www.example.com/wp-admin/admin-ajax.php',
				'component'  => 'com_akeeba',
				'format'     => 'json',
			]
		)->makeURL('getBackupInfo', ['backup_id' => 123]);
		$uri = new Uri($url);

		$this->assertSame(
			'https://www.example.com/wp-admin/admin-ajax.php',
			$uri->toString(['scheme', 'host', 'path'])
		);
		$this->assertSame('akeebabackup_api', $uri->getVar('action'));
		$this->assertSame('getBackupInfo', $uri->getVar('method'));
		$this->assertFalse($uri->hasVar('view'));
		$this->assertFalse($uri->hasVar('option'));
		$this->assertFalse($uri->hasVar('format'));
	}

	public function testAnAkeebaSoloUrlIsAddressedThroughRemotePhp(): void
	{
		$url = $this->client(['apiVersion' => 2, 'host' => 'https://www.example.com/remote.php'])
			->makeURL('getVersion');
		$uri = new Uri($url);

		$this->assertSame('https://www.example.com/remote.php', $uri->toString(['scheme', 'host', 'path']));
		$this->assertSame('Api', $uri->getVar('view'));
		$this->assertFalse($uri->hasVar('action'));
	}

	public function testBuildsAV1UrlWithAnEncapsulatedChallenge(): void
	{
		$url = $this->client(['apiVersion' => 1])->makeURL('getBackupInfo', ['backup_id' => 123]);
		$uri = new Uri($url);

		$this->assertSame('json', $uri->getVar('view'));

		$encapsulated = json_decode($uri->getVar('json'), false);

		$this->assertSame(1, $encapsulated->encapsulation);

		$body = json_decode($encapsulated->body, false);

		$this->assertSame('getBackupInfo', $body->method);
		$this->assertSame(123, $body->data->backup_id);

		// The challenge is salt:md5(salt . secret). Anyone holding the Secret Word can recompute it.
		[$salt, $digest] = explode(':', $body->challenge, 2);

		$this->assertSame(md5($salt . 'S3CR3T'), $digest);
	}

	public function testEveryV1RequestGetsAFreshSalt(): void
	{
		$client = $this->client(['apiVersion' => 1]);

		$first  = json_decode(json_decode((new Uri($client->makeURL('getVersion')))->getVar('json'))->body)->challenge;
		$second = json_decode(json_decode((new Uri($client->makeURL('getVersion')))->getVar('json'))->body)->challenge;

		$this->assertNotSame($first, $second);
	}

	/**
	 * Joomla's webservices plugin strips option, view, format and tmpl from a request before routing it, because
	 * their presence confuses the API application's router. Sending them to the v3 API is therefore worse than
	 * pointless.
	 */
	public function testTheV3QueryStringIsThePayloadAndNothingElse(): void
	{
		$client = $this->client(['apiVersion' => 3, 'component' => 'com_akeeba', 'format' => 'json']);

		$this->assertSame(
			['backup_id' => 123],
			$client->exposedQueryStringParameters('getBackupInfo', ['backup_id' => 123])
		);
	}

	public function testTheV2QueryStringNamesTheMethodAndTheView(): void
	{
		$parameters = $this->client(['apiVersion' => 2])
			->exposedQueryStringParameters('getBackupInfo', ['backup_id' => 123]);

		$this->assertSame(
			['backup_id' => 123, 'view' => 'Api', 'method' => 'getBackupInfo'],
			$parameters
		);
	}

	public function testTheHostsTrailingSlashDoesNotDoubleUpInTheUrl(): void
	{
		$url = $this->client(['apiVersion' => 2, 'host' => 'https://www.example.com/'])->makeURL('getVersion');

		$this->assertStringStartsWith('https://www.example.com/index.php?', $url);
	}

	public function testASiteInASubdirectoryKeepsItsPath(): void
	{
		$url = $this->client(['apiVersion' => 2, 'host' => 'https://www.example.com/subdir/index.php'])
			->makeURL('getVersion');

		$this->assertStringStartsWith('https://www.example.com/subdir/index.php?', $url);
	}
}
