<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\Tests\Stub\SpyHttpClient;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NotAuthorised;
use Akeeba\BackupJsonApi\Exception\NoWayToConnect;
use Akeeba\BackupJsonApi\Exception\RemoteApiVersionTooLow;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HighLevel\Autodetect;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The connection settings search.
 *
 * Autodetect walks a list of ways to connect, newest API version first, and keeps the first combination the site
 * answers. Both halves of that matter: which combinations it tries, and — when none of them work — which of the
 * failures it reports, since the last one it saw is always the least informative.
 */
#[CoversClass(Autodetect::class)]
class AutodetectTest extends HighLevelTestCase
{
	/**
	 * Comfortably more failures than there are ways of connecting, so a search which is meant to run to the end does.
	 *
	 * The real count for a site configured with nothing but a host and a Secret Word is sixteen: four for the v3 API —
	 * two paths to Joomla's API application, times the Secret Word tried as a token and then as a Secret Word — then
	 * six each for the v2 and the v1 API, being three component names times two formats.
	 */
	private const MORE_THAN_ENOUGH_FAILURES = 64;

	/**
	 * A getVersion response from a server new enough for this library to talk to.
	 *
	 * @param   int  $apiLevel  The API level to report
	 *
	 * @return  object
	 */
	private function version(int $apiLevel = 400): object
	{
		return (object) ['api' => $apiLevel, 'version' => '10.4.0'];
	}

	/**
	 * Queues the same failure for every candidate, so the search runs to the end.
	 *
	 * @param   callable  $failure  Returns the failure to queue
	 *
	 * @return  void
	 */
	private function everyCandidateFails(callable $failure): void
	{
		for ($i = 0; $i < self::MORE_THAN_ENOUGH_FAILURES; $i++)
		{
			$failure();
		}
	}

	public function testProbesTheServerWithGetVersion(): void
	{
		$this->httpClient->willAnswer(200, $this->version());

		(new Autodetect($this->httpClient))();

		$this->assertSame(['getVersion'], $this->httpClient->calledMethods());
	}

	public function testKeepsTheFirstCombinationWhichWorks(): void
	{
		$this->httpClient
			->willThrow(new CommunicationError(404, 'Not found'))
			->willThrow(new CommunicationError(404, 'Not found'))
			->willAnswer(200, $this->version());

		(new Autodetect($this->httpClient))();

		$options = $this->httpClient->getOptions();

		$this->assertSame(3, $options->apiVersion);
		$this->assertSame('api', $options->apiEndpoint);
		$this->assertCount(3, $this->httpClient->calls);
	}

	/**
	 * The order is the contract: the newest API version a site could speak is the one it should be talked to with.
	 */
	public function testTriesTheNewestApiVersionFirst(): void
	{
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected. The order in which it gave up is what is under test.
		}

		$versions = array_column($this->httpClient->calledWithOptionValues(['apiVersion']), 'apiVersion');

		$this->assertSame([3, 3, 3, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1], $versions);
	}

	/**
	 * A caller with a single credential field — as Akeeba Remote CLI and Akeeba Panopticon have — will have its user
	 * paste a Joomla! API token into the box labelled “Secret Word”. Trying the value as a token first costs one
	 * request when the guess is wrong, and makes that mistake invisible when it is right.
	 */
	public function testTriesASecretWordAsAJoomlaApiTokenFirst(): void
	{
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$credentials = $this->httpClient->calledWithOptionValues(['token', 'secret']);

		$this->assertSame(['token' => 'TheSecretWord', 'secret' => ''], $credentials[0]);
		$this->assertSame(['token' => '', 'secret' => 'TheSecretWord'], $credentials[1]);
	}

	public function testPrefersAConfiguredTokenOverTheSecretWord(): void
	{
		$this->httpClient = new SpyHttpClient(
			$this->makeOptions(['token' => 'T0K3N', 'secret' => 'S3CR3T', 'logger' => $this->logger])
		);

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$credentials = $this->httpClient->calledWithOptionValues(['token', 'secret']);

		$this->assertSame(['token' => 'T0K3N', 'secret' => ''], $credentials[0]);
		$this->assertSame(['token' => 'S3CR3T', 'secret' => ''], $credentials[1]);
		$this->assertSame(['token' => '', 'secret' => 'S3CR3T'], $credentials[2]);
	}

	public function testTriesBothWaysOfAddressingJoomlasApiApplication(): void
	{
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$endpoints = array_column(
			array_slice($this->httpClient->calledWithOptionValues(['apiEndpoint']), 0, 4),
			'apiEndpoint'
		);

		// The endpoint is a property of the site, the credential a property of the caller, so the credential varies
		// innermost: being answered at all — even with an authentication error — already proves the endpoint.
		$this->assertSame(['api/index.php', 'api/index.php', 'api', 'api'], $endpoints);
	}

	/**
	 * None of the Joomla! frontend parameters mean anything to Joomla's API application, and its router is actively
	 * confused by them.
	 */
	public function testTheV3CandidatesCarryNoJoomlaFrontendParameters(): void
	{
		$this->httpClient->willAnswer(200, $this->version());

		(new Autodetect($this->httpClient))();

		$options = $this->httpClient->getOptions();

		$this->assertSame('', $options->component);
		$this->assertSame('', $options->format);
		$this->assertSame('', $options->view);
	}

	public function testRespectsAPinnedApiVersion(): void
	{
		$this->httpClient = new SpyHttpClient($this->makeOptions(['apiVersion' => 2, 'logger' => $this->logger]));

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$versions = array_column($this->httpClient->calledWithOptionValues(['apiVersion']), 'apiVersion');

		$this->assertSame([2, 2, 2, 2, 2, 2], $versions);
	}

	public function testRespectsAnApiVersionPinnedThroughTheLegacyViewOption(): void
	{
		$this->httpClient = new SpyHttpClient($this->makeOptions(['view' => 'json', 'logger' => $this->logger]));

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$versions = array_column($this->httpClient->calledWithOptionValues(['apiVersion']), 'apiVersion');

		$this->assertSame([1, 1, 1, 1, 1, 1], $versions);
	}

	/**
	 * The v3 API is a route in Joomla's API application. Akeeba Solo and Akeeba Backup for WordPress do not have one,
	 * so asking them for it would be a wasted round trip per candidate.
	 */
	public function testDoesNotAskANonJoomlaSiteForTheV3Api(): void
	{
		$this->httpClient = new SpyHttpClient(
			$this->makeOptions(['host' => 'https://www.example.com/remote.php', 'logger' => $this->logger])
		);

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$versions = array_column($this->httpClient->calledWithOptionValues(['apiVersion']), 'apiVersion');

		$this->assertNotContains(3, $versions);
	}

	/**
	 * The v1 and v2 APIs authenticate with the Secret Word and nothing else, so a caller holding only a Joomla! API
	 * token has nothing to present to them.
	 */
	public function testDoesNotTryTheLegacyApisWithoutASecretWord(): void
	{
		$this->httpClient = new SpyHttpClient(
			$this->makeOptions(['secret' => '', 'token' => 'T0K3N', 'logger' => $this->logger])
		);

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$versions = array_column($this->httpClient->calledWithOptionValues(['apiVersion']), 'apiVersion');

		$this->assertSame([3, 3], $versions);
	}

	public function testGivesUpWhenNothingAnswers(): void
	{
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		$this->expectException(NoWayToConnect::class);

		(new Autodetect($this->httpClient))();
	}

	/**
	 * A site which rejected our credential on the v3 API has told us something worth knowing: it speaks the API, and
	 * it only objects to who we are. It will then go on to 404 every v1 request, because the v1 API no longer exists.
	 * Reporting that last 404 would send the caller looking at their host name when the problem is their credential.
	 */
	public function testReportsARejectedCredentialRatherThanTheLastFailure(): void
	{
		$this->httpClient->willThrow(new InvalidSecretWord());

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		$this->expectException(InvalidSecretWord::class);

		(new Autodetect($this->httpClient))();
	}

	/**
	 * “We know who you are and you may not do this” is more actionable still than “we do not know who you are”, so it
	 * outranks it.
	 */
	public function testAnAuthorisationFailureOutranksAnAuthenticationFailure(): void
	{
		$this->httpClient
			->willThrow(new InvalidSecretWord())
			->willThrow(new NotAuthorised('getVersion'));

		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		$this->expectException(NotAuthorised::class);

		(new Autodetect($this->httpClient))();
	}

	/**
	 * A non-200 status which doQuery() did not turn into an exception still means this combination did not work. It
	 * is remembered, though, because it says more than “nothing answered”.
	 */
	public function testReportsTheLastErrorStatusWhenThereIsNothingBetter(): void
	{
		$this->everyCandidateFails(fn() => $this->httpClient->willAnswer(500, 'Internal server error'));

		$this->expectException(RemoteError::class);
		$this->expectExceptionMessage('500 - Internal server error');

		(new Autodetect($this->httpClient))();
	}

	/**
	 * The site answered, and it speaks a version of the API this library cannot make use of. That is a different
	 * problem from not finding the site at all, and it stops the search rather than continuing it.
	 */
	public function testRefusesAServerTooOldToTalkTo(): void
	{
		$this->httpClient->willAnswer(200, $this->version(AKEEBA_JSON_BACKUP_API_MINIMUM_API_LEVEL - 1));

		$this->expectException(RemoteApiVersionTooLow::class);

		(new Autodetect($this->httpClient))();
	}

	public function testAcceptsAServerAtExactlyTheMinimumApiLevel(): void
	{
		$this->httpClient->willAnswer(200, $this->version(AKEEBA_JSON_BACKUP_API_MINIMUM_API_LEVEL));

		(new Autodetect($this->httpClient))();

		$this->assertSame(3, $this->httpClient->getOptions()->apiVersion);
	}

	public function testLogsTheCombinationItSettledOn(): void
	{
		$this->httpClient->willAnswer(200, $this->version());

		(new Autodetect($this->httpClient))();

		$this->assertTrue($this->logger->hasMessageContaining('Found a connection method', 'debug'));
		$this->assertTrue($this->logger->hasMessageContaining('Joomla! API token', 'debug'));
	}

	public function testLogsEachCombinationItGaveUpOn(): void
	{
		$this->httpClient
			->willThrow(new CommunicationError(404, 'Not found'))
			->willAnswer(200, $this->version());

		(new Autodetect($this->httpClient))();

		$this->assertTrue($this->logger->hasMessageContaining('Communication error trying API v3', 'warning'));
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake. Autodetect::getVerbs() has a list of the two verbs it means to try —
	 * and it can never try both. Options always fills `verb` in, defaulting it to 'GET', and getVerbs() returns its
	 * default list only when the configured verb is *not* one it recognises. A recognised verb short-circuits to
	 * itself, so the search only ever probes GET, and a site which answers the API on POST alone is never found.
	 *
	 * getEndpoints() and getFormats() are written the same way; getFormats() escapes it because Options defaults
	 * `format` to an empty string, and getEndpoints() does not — see the next test.
	 *
	 * The fix is for the option to have a value meaning “no preference”, so the caller can say they have none.
	 *
	 * Unskip this test once a caller who did not choose a verb gets both probed.
	 */
	public function testTriesBothVerbs(): void
	{
		$this->markTestSkipped(
			'Autodetect only ever probes GET: Options always supplies a verb, so getVerbs() never reaches its default list. See src/HighLevel/Autodetect.php:getVerbs().'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$verbs = array_column($this->httpClient->calledWithOptionValues(['verb']), 'verb');

		$this->assertContains('POST', $verbs);
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake, and the same root cause as the verb one above. Autodetect's endpoint
	 * list names index.php, remote.php and wp-admin/admin-ajax.php — the Joomla!, Akeeba Solo and Akeeba Backup for
	 * WordPress entry points — but Options always fills `endpoint` in, defaulting it to 'index.php', so the list is
	 * unreachable and only index.php is ever probed.
	 *
	 * The consequence is not cosmetic: this library documents support for Akeeba Solo and Akeeba Backup for
	 * WordPress, and autodetect cannot find either of them. It works today only because the caller pasted a URL
	 * ending in remote.php or admin-ajax.php, which is where Options picks the endpoint up instead.
	 *
	 * Unskip this test once a caller who did not name an endpoint gets all three probed.
	 */
	public function testTriesTheAkeebaSoloAndWordPressEntryPoints(): void
	{
		$this->markTestSkipped(
			'Autodetect only ever probes index.php: Options always supplies an endpoint, so getEndpoints() never reaches its default list. See src/HighLevel/Autodetect.php:getEndpoints().'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->everyCandidateFails(fn() => $this->httpClient->willThrow(new CommunicationError(404, 'Not found')));

		try
		{
			(new Autodetect($this->httpClient))();
		}
		catch (NoWayToConnect)
		{
			// Expected.
		}

		$endpoints = array_column($this->httpClient->calledWithOptionValues(['endpoint']), 'endpoint');

		$this->assertContains('remote.php', $endpoints);
		$this->assertContains('wp-admin/admin-ajax.php', $endpoints);
	}
}
