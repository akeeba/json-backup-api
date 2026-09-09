<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Tests;

use Akeeba\BackupJsonApi\Tests\Integration\E2ETestCase;
use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NotAuthorised;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Presenting a credential, and being refused one.
 */
#[Group('e2e')]
class AuthenticationTest extends E2ETestCase
{
	#[DataProvider('httpClientProvider')]
	public function testAuthenticatesWithAJoomlaApiToken(string $clientName): void
	{
		$connector = $this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('token')]
		);

		$this->assertSame(200, $connector->information()->body->status);
	}

	#[DataProvider('httpClientProvider')]
	public function testAuthenticatesWithTheSecretWordOnTheV3Api(string $clientName): void
	{
		$connector = $this->makeConnector($clientName, ['apiVersion' => 3]);

		$this->assertSame(200, $connector->information()->body->status);
	}

	#[DataProvider('httpClientAndApiVersionProvider')]
	public function testARejectedCredentialIsReportedAsAnAuthenticationError(
		string $clientName, int $apiVersion
	): void
	{
		$connector = $this->makeConnector(
			$clientName,
			['apiVersion' => $apiVersion, 'secret' => 'not the secret word', 'token' => '']
		);

		$this->expectException(InvalidSecretWord::class);

		$connector->information();
	}

	/**
	 * Since Akeeba Backup 10.4.0 the v3 API enforces the token account's privileges per method, so a token can
	 * authenticate perfectly well and still be refused. That is a different answer from “we do not know who you are”,
	 * and it has to reach the caller as a different exception, or the advice they are given is wrong.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARestrictedTokenIsRefusedTheMethodsItMayNotCall(string $clientName): void
	{
		$connector = $this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('restrictedToken')]
		);

		$this->expectException(NotAuthorised::class);

		$connector->delete(1);
	}

	/**
	 * …and the same token is allowed the methods it does have the privilege for. Without this half, the test above
	 * would pass just as well against a token which was simply broken.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARestrictedTokenIsStillAllowedWhatItMayDo(string $clientName): void
	{
		$connector = $this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('restrictedToken')]
		);

		$result = $connector->backup(new BackupOptions(['description' => 'Taken with a restricted token']));

		$this->assertGreaterThan(0, $result->id);
	}

	/**
	 * A refused method must leave the record alone. A rejection message is easy to produce; a row that is still there
	 * is the evidence that nothing happened.
	 */
	#[DataProvider('httpClientProvider')]
	public function testARefusedDeletionDeletesNothing(string $clientName): void
	{
		$connector = $this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('restrictedToken')]
		);

		$this->assertTrue($this->site->hasRecord(1), 'The fixture did not start with the record under test');

		try
		{
			$connector->delete(1);

			$this->fail('The deletion was allowed');
		}
		catch (NotAuthorised)
		{
			// Expected. What matters is what did not happen next.
		}

		$this->assertTrue($this->site->hasRecord(1), 'The record was deleted despite the refusal');
	}

	/**
	 * The v3 API authenticates by header so that the credential never reaches the server's access log, a proxy cache
	 * or a browser history. This asserts it against what the server was actually sent, not against the URL the client
	 * believes it built.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTheV3CredentialNeverTravelsInTheUrl(string $clientName): void
	{
		$this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => '', 'token' => $this->config('token')]
		)->getBackups();

		foreach ($this->site->requests() as $request)
		{
			$this->assertStringNotContainsString($this->config('token'), $request['uri']);
			$this->assertSame($this->config('token'), $request['headers']['X-Joomla-Token']);
		}
	}

	/**
	 * The server treats the two credentials as mutually exclusive: a Secret Word makes it ignore any token in the
	 * same request, and a wrong Secret Word is a hard failure rather than a fall-through. Sending both would make the
	 * token useless, so exactly one is sent — and it is the token, whose privileges the server enforces per method.
	 */
	#[DataProvider('httpClientProvider')]
	public function testOnlyOneCredentialIsEverPresented(string $clientName): void
	{
		$this->makeConnector(
			$clientName,
			['apiVersion' => 3, 'secret' => $this->config('secret'), 'token' => $this->config('token')]
		)->information();

		foreach ($this->site->requests() as $request)
		{
			$this->assertSame($this->config('token'), $request['headers']['X-Joomla-Token']);
			$this->assertNull($request['headers']['X-Akeeba-Auth']);
		}
	}

	/**
	 * The v2 API has no header authentication at all: the Secret Word is a query string parameter, and therefore does
	 * end up in the access log. That is one of the reasons it is deprecated, and it is worth pinning down so nobody
	 * mistakes it for the v3 behaviour.
	 */
	#[DataProvider('httpClientProvider')]
	public function testTheV2CredentialTravelsInTheQueryString(string $clientName): void
	{
		$this->makeConnector($clientName, ['apiVersion' => 2])->information();

		$requests = $this->site->requests();

		$this->assertSame($this->config('secret'), $requests[0]['query']['_akeebaAuth']);
	}
}
