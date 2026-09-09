<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\UnsafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * Following redirects, and refusing to.
 *
 * This is the part of the library with something at stake. Every request carries a credential, and every HTTP client
 * forwards its headers along a redirect chain, so a redirect out of the caller's domain would hand that credential to
 * whoever the redirect names.
 *
 * The refusals here are pointed at a host which genuinely exists and genuinely records what it is sent, so each one
 * asserts two things: that the library refused, and that nothing arrived at the other end. Only the second of those
 * would fail if the guard were taken out.
 */
#[Group('e2e')]
class RedirectTest extends E2ETestCase
{
	/**
	 * Redirect targets which stay inside the caller's domain and must therefore be followed.
	 *
	 * {uri} is replaced by the request as it arrived, so each of these is “the same request, somewhere we are allowed
	 * to go”.
	 *
	 * @return  array[]
	 */
	public static function allowedRedirectProvider(): array
	{
		$cases = [];

		$targets = [
			'the same host, root relative' => '{uri}',
			'dropping the www'             => 'http://example.test{uri}',
			'a sibling subdomain'          => 'http://foobar.example.test{uri}',
			'a deeper subdomain'           => 'http://a.b.example.test{uri}',
			'scheme relative'              => '//foobar.example.test{uri}',
		];

		foreach (self::HTTP_CLIENTS as $client)
		{
			foreach ($targets as $label => $target)
			{
				$cases[sprintf('%s, %s', $client, $label)] = [$client, $target];
			}
		}

		return $cases;
	}

	/**
	 * Redirect targets which leave the caller's domain and must therefore be refused. Each is a reachable host which
	 * records what it receives.
	 *
	 * @return  array[]
	 */
	public static function refusedRedirectProvider(): array
	{
		$cases = [];

		$targets = [
			'an unrelated domain'               => ['http://www.example.test', 'http://evil.test/sink.php'],
			'a name which ends in the anchor'    => ['http://www.example.test', 'http://notexample.test/sink.php'],
			'the anchor as a subdomain of evil' => [
				'http://www.example.test',
				'http://www.example.test.evil.test/sink.php',
			],
			// Recognising this as related would need a public suffix list, which this library does without.
			'sideways to a sibling of a parent' => ['http://api.example.test', 'http://shop.example.test/sink.php'],
		];

		foreach (self::HTTP_CLIENTS as $client)
		{
			foreach ($targets as $label => [$origin, $target])
			{
				$cases[sprintf('%s, %s', $client, $label)] = [$client, $origin, $target];
			}
		}

		return $cases;
	}

	#[DataProvider('allowedRedirectProvider')]
	public function testFollowsARedirectWhichStaysInTheCallersDomain(string $clientName, string $target): void
	{
		$this->site->armRedirect($target);

		$result = $this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$this->assertSame(200, $result->body->status);
		$this->assertSame(400, (int) $result->body->data->api);
	}

	/**
	 * A Joomla! site redirects an API URL for entirely mundane reasons — a SEF language prefix, www to non-www — so
	 * this has to work on the way to the archive as well as on the way to the API.
	 */
	#[DataProvider('httpClientProvider')]
	public function testFollowsARedirectUpToAParentDomain(string $clientName): void
	{
		$this->site->armRedirect('http://example.test{uri}');

		$result = $this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'host' => $this->config('joomlaAncestor')]
		)->information();

		$this->assertSame(200, $result->body->status);
	}

	/**
	 * Proves the tests above are worth something: the credential really does travel to wherever the redirect leads.
	 * Take the guard out and this is exactly what would arrive at the sink.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTheCredentialIsCarriedAcrossAnAllowedRedirect(string $clientName): void
	{
		$this->site->armRedirect('http://foobar.example.test{uri}');

		$this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('token')]
		)->information();

		$requests = $this->site->requests();

		$this->assertGreaterThanOrEqual(2, count($requests), 'The redirect was not followed at all');
		$this->assertSame('foobar.example.test', $requests[1]['host']);
		$this->assertSame($this->config('token'), $requests[1]['headers']['X-Joomla-Token']);
	}

	#[DataProvider('refusedRedirectProvider')]
	public function testRefusesARedirectWhichLeavesTheCallersDomain(
		string $clientName, string $origin, string $target
	): void
	{
		$this->site->armRedirect($target);

		$connector = $this->makeConnector($clientName, ['apiVersion' => 3, 'host' => $origin]);

		try
		{
			$connector->information();

			$this->fail(sprintf('The library followed a redirect to %s', $target));
		}
		catch (UnsafeRedirect $e)
		{
			$this->assertStringContainsString($target, $e->getMessage());
		}

		$this->assertNothingReachedTheSink();
	}

	/**
	 * The same refusal, with a Joomla! API token rather than the Secret Word, because the two travel in different
	 * headers and a guard could conceivably cover one and not the other.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARefusedRedirectLeaksNeitherCredential(string $clientName): void
	{
		foreach ([['secret' => $this->config('secret'), 'token' => ''], ['secret' => '', 'token' => $this->config('token')]] as $credential)
		{
			$this->sink->reset();
			$this->site->armRedirect('http://evil.test/sink.php');

			try
			{
				$this->makeConnector($clientName, array_merge(['apiVersion' => 3], $credential))->information();
			}
			catch (Throwable)
			{
				// The refusal itself is asserted elsewhere. This is about what reached the other end.
			}

			$this->assertNothingReachedTheSink();
			$this->assertCredentialDidNotLeak();
		}
	}

	/**
	 * The v2 API carries its credential in the query string rather than in a header, so a redirect discloses it in a
	 * different way. The rule has to hold there too.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARefusedRedirectLeaksNothingOnTheV2ApiEither(string $clientName): void
	{
		$this->site->armRedirect('http://evil.test/sink.php');

		try
		{
			$this->makeConnector($clientName, ['apiVersion' => 2])->information();

			$this->fail('The library followed a redirect off its domain');
		}
		catch (UnsafeRedirect)
		{
			// Expected.
		}

		$this->assertNothingReachedTheSink();
	}

	/**
	 * A server which redirects us in a circle is not a server we can talk to, but it must not be one which hangs us
	 * either.
	 */
	#[DataProvider('httpClientProvider')]
	public function testGivesUpOnARedirectLoop(string $clientName): void
	{
		$this->site->armRedirect('{uri}', 302, 100);

		$this->expectException(CommunicationError::class);

		$this->makeConnector($clientName, ['apiVersion' => 3])->information();
	}

	#[DataProvider('redirectStatusProvider')]
	public function testFollowsEveryKindOfRedirect(string $clientName, int $status): void
	{
		$this->site->armRedirect('http://example.test{uri}', $status);

		$result = $this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$this->assertSame(200, $result->body->status);
	}

	public static function redirectStatusProvider(): array
	{
		$cases = [];

		foreach (self::HTTP_CLIENTS as $client)
		{
			foreach ([301, 302, 303, 307, 308] as $status)
			{
				$cases[sprintf('%s, HTTP %d', $client, $status)] = [$client, $status];
			}
		}

		return $cases;
	}

	/**
	 * A POST which is redirected keeps its payload, which is what the RFC says for everything but a 303. Losing it
	 * would turn a backup step into a request with no backup ID in it.
	 */
	#[DataProvider('httpClientProvider')]
	public function testAPostSurvivesARedirect(string $clientName): void
	{
		$this->site->armRedirect('http://example.test{uri}', 307);

		$result = $this->makeConnector($clientName, ['apiVersion' => 2, 'verb' => 'POST'])
			->getBackup(1);

		$this->assertSame(1, (int) $result->id);
	}
}
