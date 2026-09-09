<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration;

use Akeeba\BackupJsonApi\Tests\Integration\Engine\FixtureControl;
use Akeeba\BackupJsonApi\Tests\Stub\ArrayLogger;
use Akeeba\BackupJsonApi\Connector;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientGuzzle;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientJoomla;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientPsr;
use Akeeba\BackupJsonApi\Options;
use Composer\CaBundle\CaBundle;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the integration tests.
 *
 * These talk to a real web server over real HTTP. Nothing is stubbed, mocked or booted in-process: the library sends
 * the requests it would send against anybody's site, and the assertions are about what came back and about what the
 * server can prove it was sent.
 *
 * @since 1.1.0
 */
abstract class E2ETestCase extends TestCase
{
	/**
	 * The three bundled HTTP clients, by the name the data providers use.
	 *
	 * Every behaviour this suite covers has to hold for all three. They are separate implementations of the same
	 * contract — including the redirect policy, which each one enforces in its own way — so a test which exercises
	 * only one of them proves a third of what it looks like it proves.
	 */
	public const HTTP_CLIENTS = ['guzzle', 'joomla', 'psr'];

	protected FixtureControl $site;

	protected FixtureControl $sink;

	protected ArrayLogger $logger;

	protected function setUp(): void
	{
		parent::setUp();

		$this->logger = new ArrayLogger();
		$this->site   = new FixtureControl($this->config('joomla'));
		$this->sink   = new FixtureControl($this->config('sink'));

		$this->site->reset();
		$this->sink->reset();
	}

	/**
	 * A data provider naming each bundled HTTP client.
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	public static function httpClientProvider(): array
	{
		return array_combine(
			self::HTTP_CLIENTS,
			array_map(fn(string $name) => [$name], self::HTTP_CLIENTS)
		);
	}

	/**
	 * A data provider naming each bundled HTTP client, crossed with each API version.
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	public static function httpClientAndApiVersionProvider(): array
	{
		$cases = [];

		foreach (self::HTTP_CLIENTS as $client)
		{
			foreach ([3, 2, 1] as $apiVersion)
			{
				$cases[sprintf('%s, API v%d', $client, $apiVersion)] = [$client, $apiVersion];
			}
		}

		return $cases;
	}

	/**
	 * Reads one setting out of the suite's configuration.
	 *
	 * @param   string  $key  The setting to read
	 *
	 * @return  string
	 * @since   1.1.0
	 */
	protected function config(string $key): string
	{
		$configuration = AKEEBA_JSON_BACKUP_API_E2E_CONFIG;

		if (!isset($configuration[$key]))
		{
			throw new InvalidArgumentException(sprintf('There is no ‘%s’ in the test configuration', $key));
		}

		return (string) $configuration[$key];
	}

	/**
	 * Builds the connection options for a test.
	 *
	 * @param   array  $overrides  What this test needs different from the defaults
	 *
	 * @return  Options
	 * @since   1.1.0
	 */
	protected function makeOptions(array $overrides = []): Options
	{
		return new Options(
			array_merge(
				[
					'capath' => CaBundle::getBundledCaBundlePath(),
					'host'   => $this->config('joomla'),
					'secret' => $this->config('secret'),
					'ua'     => 'AkeebaBackupJsonApiClient/integration-tests',
					'logger' => $this->logger,
				],
				$overrides
			)
		);
	}

	/**
	 * Builds one of the bundled HTTP clients.
	 *
	 * @param   string   $name       Which client: 'guzzle', 'joomla' or 'psr'
	 * @param   Options  $options    The connection options
	 *
	 * @return  HttpClientInterface
	 * @since   1.1.0
	 */
	protected function makeHttpClient(string $name, Options $options): HttpClientInterface
	{
		return match ($name)
		{
			'guzzle' => new HttpClientGuzzle($options),
			'joomla' => new HttpClientJoomla($options),
			/**
			 * The PSR client is handed a PSR-17 request factory, a PSR-17 stream factory and a PSR-18 client. Guzzle
			 * provides all three, and its PSR-18 entry point deliberately does not follow redirects — which is the
			 * whole reason HttpClientPsr walks the chain itself.
			 */
			'psr'    => new HttpClientPsr($options, new HttpFactory(), new HttpFactory(), new GuzzleClient()),
			default  => throw new InvalidArgumentException(sprintf('There is no ‘%s’ HTTP client', $name)),
		};
	}

	/**
	 * Builds an API connector for a test.
	 *
	 * @param   string  $clientName  Which HTTP client to use
	 * @param   array   $overrides   What this test needs different from the default options
	 *
	 * @return  Connector
	 * @since   1.1.0
	 */
	protected function makeConnector(string $clientName, array $overrides = []): Connector
	{
		return new Connector($this->makeHttpClient($clientName, $this->makeOptions($overrides)));
	}

	/**
	 * Asserts that nothing at all reached the credential sink.
	 *
	 * This is the assertion that matters whenever a redirect is refused. A refusal is easy to fake and easy to get
	 * accidentally right; a request which was never made is not.
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	protected function assertNothingReachedTheSink(): void
	{
		$requests = $this->sink->requests();

		$this->assertSame(
			[],
			$requests,
			sprintf(
				'The library sent %d request(s) to the host it refused to follow a redirect to: %s',
				count($requests),
				json_encode(array_column($requests, 'uri'))
			)
		);
	}

	/**
	 * Asserts that the credential never reached the sink, whichever way it might have travelled.
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	protected function assertCredentialDidNotLeak(): void
	{
		foreach ($this->sink->requests() as $request)
		{
			$this->assertNull($request['headers']['X-Joomla-Token'] ?? null, 'A Joomla! API token reached the sink');
			$this->assertNull($request['headers']['X-Akeeba-Auth'] ?? null, 'A Secret Word reached the sink');
			$this->assertArrayNotHasKey('_akeebaAuth', $request['query'] ?? [], 'A Secret Word reached the sink');
		}
	}
}
