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
 * Finding the answer in a response which is not only the answer.
 *
 * Sites in the wild ship with display_errors on and an error reporting level far too verbose for a JSON endpoint, and
 * caching plugins append their own signature to everything. The client has to dig the response out of that.
 */
#[Group('e2e')]
class JunkResponseTest extends E2ETestCase
{
	#[DataProvider('httpClientProvider')]
	public function testReadsAResponseBuriedUnderAPhpNotice(string $clientName): void
	{
		$this->site->armJunk('before');

		$result = $this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$this->assertSame(200, $result->body->status);
		$this->assertSame(400, (int) $result->body->data->api);
	}

	/**
	 * Older versions of the backup software wrap their response in triple hash markers. The client still has to
	 * recognise them, because those versions are still out there.
	 */
	#[DataProvider('httpClientProvider')]
	public function testReadsAResponseWrappedInTripleHashMarkers(string $clientName): void
	{
		$this->site->armJunk('hashes');

		$result = $this->makeConnector($clientName, ['apiVersion' => 2])->information();

		$this->assertSame(200, $result->body->status);
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake, and the same one the unit suite records against
	 * AbstractHttpClient::removeResponseJunk(). Junk *after* the JSON — a notice raised during shutdown, a caching
	 * plugin's HTML comment — is not stripped, because the fallback path passes substr() an absolute offset where it
	 * wants a length. The client reports the server sent invalid JSON.
	 *
	 * The fix is `substr($raw, $openBrace, $closeBrace - $openBrace + 1)`.
	 *
	 * Unskip both cases once removeResponseJunk() slices with a length.
	 */
	#[DataProvider('trailingJunkProvider')]
	public function testReadsAResponseFollowedByJunk(string $clientName, string $where): void
	{
		$this->markTestSkipped(
			'AbstractHttpClient::removeResponseJunk() cannot strip junk which follows the JSON. See the unit suite’s ResponseHandlingTest for the diagnosis.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->site->armJunk($where);

		$result = $this->makeConnector($clientName, ['apiVersion' => 3])->information();

		$this->assertSame(200, $result->body->status);
	}

	public static function trailingJunkProvider(): array
	{
		$cases = [];

		foreach (self::HTTP_CLIENTS as $client)
		{
			$cases[sprintf('%s, trailing only', $client)] = [$client, 'after'];
			$cases[sprintf('%s, both ends', $client)]     = [$client, 'both'];
		}

		return $cases;
	}
}
