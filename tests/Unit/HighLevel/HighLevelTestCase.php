<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\HighLevel;

use Akeeba\BackupJsonApi\Tests\Stub\ArrayLogger;
use Akeeba\BackupJsonApi\Tests\Stub\SpyHttpClient;
use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;

/**
 * Base class for the tests of the high level operations.
 *
 * Every one of them is a small object which turns one or more JSON API calls into a result or an exception. None of
 * them needs a server: the conversation is what is under test, and SpyHttpClient stands in for the far end of it.
 *
 * @since 1.1.0
 */
abstract class HighLevelTestCase extends UnitTestCase
{
	protected SpyHttpClient $httpClient;

	protected ArrayLogger $logger;

	protected function setUp(): void
	{
		parent::setUp();

		$this->logger     = new ArrayLogger();
		$this->httpClient = new SpyHttpClient($this->makeOptions(['logger' => $this->logger]));
	}

	/**
	 * Asserts that exactly one API call was made, to this method and with this payload.
	 *
	 * @param   string  $method  The API method which should have been called
	 * @param   array   $data    The payload it should have been called with
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	protected function assertCalledOnce(string $method, array $data = []): void
	{
		$this->assertCount(1, $this->httpClient->calls);
		$this->assertSame($method, $this->httpClient->calls[0]['method']);
		$this->assertSame($data, $this->httpClient->calls[0]['data']);
	}
}
