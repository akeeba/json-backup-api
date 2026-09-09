<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HttpAbstraction;

use Akeeba\BackupJsonApi\Tests\Stub\ArrayLogger;
use Akeeba\BackupJsonApi\Tests\Stub\CannedHttpClient;
use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\Exception\UnsafeRedirect;
use Akeeba\BackupJsonApi\HttpAbstraction\AbstractHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The redirect policy.
 *
 * Every API request carries a credential — a header on the v3 API, a query string parameter on v2 — and every HTTP
 * client in this library forwards its request along a redirect chain. A redirect out of the caller's domain would
 * therefore hand that credential to whoever the redirect names, which is exactly what an open redirect on the site,
 * or a hostile site, would exploit. So each hop is vetted before it is walked.
 */
#[CoversClass(AbstractHttpClient::class)]
class RedirectPolicyTest extends UnitTestCase
{
	private function client(string $host): CannedHttpClient
	{
		return new CannedHttpClient($this->makeOptions(['host' => $host]));
	}

	#[DataProvider('allowedRedirectProvider')]
	public function testAllowsARedirectWhichStaysInTheCallersDomain(string $origin, string $target): void
	{
		$this->assertTrue($this->client($origin)->exposedIsRedirectAllowed($target));
	}

	public static function allowedRedirectProvider(): array
	{
		return [
			'the same host'                    => ['https://www.example.com', 'https://www.example.com/en/index.php'],
			'dropping the www'                 => ['https://www.example.com', 'https://example.com/index.php'],
			'a sibling subdomain'              => ['https://www.example.com', 'https://foobar.example.com/index.php'],
			'a deeper subdomain'               => ['https://www.example.com', 'https://a.b.example.com/index.php'],
			'the anchor itself'                => ['https://www.example.com', 'https://example.com'],
			'an upgrade to TLS'                => ['http://www.example.com', 'https://www.example.com/index.php'],
			'plain HTTP throughout'            => ['http://www.example.com', 'http://www.example.com/en/index.php'],
			'up to a parent domain'            => ['https://api.example.com', 'https://example.com/index.php'],
			'a host without a www to drop'     => ['https://example.com', 'https://shop.example.com/index.php'],
			'the same IP address'              => ['https://192.0.2.10', 'https://192.0.2.10/index.php'],
			'a trailing dot is the same name'  => ['https://www.example.com', 'https://www.example.com./index.php'],
			'the host name case is irrelevant' => ['https://www.example.com', 'https://WWW.EXAMPLE.COM/index.php'],
			'a port change is not a host change' => ['https://www.example.com', 'https://www.example.com:8443/index.php'],
		];
	}

	#[DataProvider('refusedRedirectProvider')]
	public function testRefusesARedirectWhichLeavesTheCallersDomain(string $origin, string $target): void
	{
		$this->assertFalse($this->client($origin)->exposedIsRedirectAllowed($target));
	}

	public static function refusedRedirectProvider(): array
	{
		return [
			'an unrelated domain'                => ['https://www.example.com', 'https://evil.net/index.php'],
			// The comparison is anchored on label boundaries, so a longer name cannot swallow the anchor.
			'a name ending in the anchor'        => ['https://www.example.com', 'https://notexample.com/index.php'],
			'the anchor as a subdomain of evil'  => ['https://www.example.com', 'https://www.example.com.evil.net/'],
			'a bare IP address'                  => ['https://www.example.com', 'https://192.0.2.10/index.php'],
			'a different IP address'             => ['https://192.0.2.10', 'https://192.0.2.11/index.php'],
			// An address and a name are never the same host, whichever way round they appear.
			'a name when the origin is an IP'    => ['https://192.0.2.10', 'https://www.example.com/index.php'],
			'an IP when the origin is a name'    => ['https://www.example.com', 'http://192.0.2.10/'],
			'a downgrade to plaintext HTTP'      => ['https://www.example.com', 'http://www.example.com/index.php'],
			'a scheme we do not speak'           => ['https://www.example.com', 'ftp://www.example.com/index.php'],
			'a data URI'                         => ['https://www.example.com', 'data:text/html,<h1>hi</h1>'],
			'a target with no scheme at all'     => ['https://www.example.com', 'www.example.com/index.php'],
			'a target with no host at all'       => ['https://www.example.com', 'https:///index.php'],
			// Recognising a sideways move as safe needs a public suffix list. Without one, this fails closed.
			'sideways to a sibling of a parent'  => ['https://api.example.com', 'https://shop.example.com/index.php'],
		];
	}

	/**
	 * The naive rule — compare the last two labels — would call this a match, because both end in co.uk. Anchoring on
	 * the caller's own host with the www stripped is what makes a public suffix list unnecessary here.
	 */
	public function testAPublicSuffixIsNotADomainWeShare(): void
	{
		$this->assertFalse($this->client('https://www.example.co.uk')->exposedIsRedirectAllowed('https://evil.co.uk/'));
	}

	public function testAMalformedTargetIsRefusedRatherThanThrowing(): void
	{
		$this->assertFalse($this->client('https://www.example.com')->exposedIsRedirectAllowed('http://:80'));
	}

	public function testARefusedRedirectThrowsAndNamesBothEnds(): void
	{
		$client = $this->client('https://www.example.com');

		$this->expectException(UnsafeRedirect::class);
		$this->expectExceptionMessage('https://evil.net/steal');

		$client->exposedAssertRedirectAllowed('https://www.example.com/index.php', 'https://evil.net/steal');
	}

	public function testARefusedRedirectIsLoggedAsAnError(): void
	{
		$logger = new ArrayLogger();
		$client = new CannedHttpClient(
			$this->makeOptions(['host' => 'https://www.example.com', 'logger' => $logger])
		);

		try
		{
			$client->exposedAssertRedirectAllowed('https://www.example.com/index.php', 'https://evil.net/steal');
		}
		catch (UnsafeRedirect)
		{
			// Expected. What is under test is what was written to the log on the way out.
		}

		$this->assertTrue($logger->hasMessageContaining('Refusing to follow a redirect', 'error'));
		$this->assertTrue($logger->hasMessageContaining('https://evil.net/steal', 'error'));
	}

	public function testAnAllowedRedirectIsLoggedAndDoesNotThrow(): void
	{
		$logger = new ArrayLogger();
		$client = new CannedHttpClient(
			$this->makeOptions(['host' => 'https://www.example.com', 'logger' => $logger])
		);

		$client->exposedAssertRedirectAllowed(
			'https://www.example.com/index.php',
			'https://www.example.com/en/index.php'
		);

		$this->assertTrue($logger->hasMessageContaining('Following a redirect', 'debug'));
	}

	#[DataProvider('redirectStatusProvider')]
	public function testRecognisesTheRedirectStatuses(int $status, bool $expected): void
	{
		$this->assertSame($expected, $this->client('https://www.example.com')->exposedIsRedirectStatus($status));
	}

	public static function redirectStatusProvider(): array
	{
		return [
			'200 OK'                => [200, false],
			'301 Moved Permanently' => [301, true],
			'302 Found'             => [302, true],
			'303 See Other'         => [303, true],
			// 304 is a cache validator, not a redirect. There is nowhere to go.
			'304 Not Modified'      => [304, false],
			'305 Use Proxy'         => [305, false],
			'307 Temporary Redirect' => [307, true],
			'308 Permanent Redirect' => [308, true],
			'404 Not Found'         => [404, false],
		];
	}

	public function testGivesUpAfterAFiniteNumberOfHops(): void
	{
		$this->assertSame(20, $this->client('https://www.example.com')->exposedMaxRedirects());
	}

	#[DataProvider('locationResolutionProvider')]
	public function testResolvesTheLocationHeaderIntoAnAbsoluteUrl(
		string $currentUrl, string $location, ?string $expected
	): void
	{
		$this->assertSame(
			$expected,
			$this->client('https://www.example.com')->exposedResolveRedirectUrl($currentUrl, $location)
		);
	}

	public static function locationResolutionProvider(): array
	{
		return [
			'already absolute'      => [
				'https://www.example.com/index.php',
				'https://www.example.com/en/index.php',
				'https://www.example.com/en/index.php',
			],
			'scheme relative'       => [
				'https://www.example.com/index.php',
				'//other.example.com/index.php',
				'https://other.example.com/index.php',
			],
			'root relative'         => [
				'https://www.example.com/subdir/index.php',
				'/en/index.php',
				'https://www.example.com/en/index.php',
			],
			'relative to the directory' => [
				'https://www.example.com/subdir/index.php',
				'index.php?foo=bar',
				'https://www.example.com/subdir/index.php?foo=bar',
			],
			'relative, at the root'     => [
				'https://www.example.com/index.php',
				'other.php',
				'https://www.example.com/other.php',
			],
			'the port is carried over'  => [
				'https://www.example.com:8443/index.php',
				'/en/index.php',
				'https://www.example.com:8443/en/index.php',
			],
			'surrounding whitespace is trimmed' => [
				'https://www.example.com/index.php',
				"  /en/index.php\r\n",
				'https://www.example.com/en/index.php',
			],
			// A redirect with nowhere to go is not a redirect we can resolve.
			'an empty header'       => ['https://www.example.com/index.php', '', null],
			'nothing but whitespace' => ['https://www.example.com/index.php', "   \t", null],
		];
	}

	/**
	 * Resolution is deliberately separate from vetting: a Location may resolve perfectly well and still point
	 * somewhere we will not go.
	 */
	public function testResolutionDoesNotVetTheTarget(): void
	{
		$client = $this->client('https://www.example.com');
		$target = $client->exposedResolveRedirectUrl('https://www.example.com/index.php', 'https://evil.net/steal');

		$this->assertSame('https://evil.net/steal', $target);
		$this->assertFalse($client->exposedIsRedirectAllowed($target));
	}
}
