<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Finding a way to talk to a site, and talking to it.
 */
#[Group('e2e')]
class ConnectionTest extends E2ETestCase
{
	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testReadsTheServerVersionOverEveryApiVersion(string $clientName, int $apiVersion): void
	{
		$result = $this->makeConnector($clientName, ['apiVersion' => $apiVersion])->information();

		$this->assertSame(200, $result->body->status);
		$this->assertSame(400, (int) $result->body->data->api);
		$this->assertSame('10.4.0', $result->body->data->version);
	}

	/**
	 * Autodetect is given nothing but a host and a credential, which is all a user of this library has to offer, and
	 * has to come back with a working combination.
	 */
	#[DataProvider('httpClientProvider')]
	public function testAutodetectFindsTheNewestApiTheSiteSpeaks(string $clientName): void
	{
		$httpClient = $this->makeHttpClient($clientName, $this->makeOptions());

		$this->assertSame(0, $httpClient->getOptions()->apiVersion, 'The test did not start from “nothing said”');

		(new \Akeeba\BackupJsonApi\Connector($httpClient))->autodetect();

		$this->assertSame(3, $httpClient->getOptions()->apiVersion);
	}

	/**
	 * A caller with a single credential field will have its user paste a Joomla! API token into the box labelled
	 * “Secret Word”. Autodetect tries a Secret Word as a token first, so that mistake costs one request and nothing
	 * else.
	 */
	#[DataProvider('httpClientProvider')]
	public function testAutodetectAcceptsATokenPastedIntoTheSecretWordField(string $clientName): void
	{
		$httpClient = $this->makeHttpClient(
			$clientName,
			$this->makeOptions(['secret' => $this->config('token')])
		);

		(new \Akeeba\BackupJsonApi\Connector($httpClient))->autodetect();

		$options = $httpClient->getOptions();

		$this->assertSame(3, $options->apiVersion);
		$this->assertSame($this->config('token'), $options->token);
		$this->assertSame('', $options->secret);
	}

	/**
	 * Once autodetect has settled on a combination, the client keeps it. Its result is worth caching precisely
	 * because it is stable, so this is part of the contract rather than an implementation detail.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTheDetectedSettingsAreTheOnesUsedAfterwards(string $clientName): void
	{
		$httpClient = $this->makeHttpClient($clientName, $this->makeOptions());
		$connector  = new \Akeeba\BackupJsonApi\Connector($httpClient);

		$connector->autodetect();

		$before = $httpClient->getOptions()->toArray();

		$connector->information();

		$this->assertSame($before, $httpClient->getOptions()->toArray());
	}

	/**
	 * Akeeba Solo and Akeeba Backup for WordPress have no Joomla! API application, so there is no v3 route on them.
	 * The client has to work out that the newest version it can speak to those is the v2 API.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTalksToAnAkeebaSoloSite(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['host' => $this->config('solo')]);

		$this->assertSame(400, (int) $connector->information()->body->data->api);
	}

	#[DataProvider('httpClientProvider')]
	public function testTalksToAWordPressSite(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['host' => $this->config('wordpress')]);

		$this->assertSame(400, (int) $connector->information()->body->data->api);
	}

	/**
	 * Joomla!'s API application is reachable two ways, and only one of them works on any given server. Autodetect
	 * tries the form which needs no URL rewriting first, so pinning the other one is the way to prove the rewritten
	 * form is really being addressed.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTalksToTheRewrittenFormOfTheApiApplication(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3, 'apiEndpoint' => 'api']);

		$this->assertSame(400, (int) $connector->information()->body->data->api);

		$uris = array_column($this->site->requests(), 'uri');

		$this->assertStringStartsWith('/api/v3/akeebabackup/', $uris[0]);
	}

	/**
	 * Joomla!'s API application answers 406 to a request which does not say what it will accept, and Akeeba Backup's
	 * webservices plugin only papers over that for its own routes. Sending the header is what keeps a failure legible.
	 */
	#[DataProvider('httpClientProvider')]
	public function testEveryV3RequestSaysItAcceptsJson(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$requests = $this->site->requests();

		$this->assertSame('application/json', $requests[0]['headers']['Accept']);
	}

	#[DataProvider('httpClientProvider')]
	public function testEveryRequestIdentifiesTheClient(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$requests = $this->site->requests();

		$this->assertSame('AkeebaBackupJsonApiClient/integration-tests', $requests[0]['headers']['User-Agent']);
	}
}
