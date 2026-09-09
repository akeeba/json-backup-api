<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Integration\Engine;

use RuntimeException;

/**
 * The test's own line to the fixture server, out of band of the library under test.
 *
 * Everything here is done with bare cURL on purpose. If the library were used to set a test up, a bug in the library
 * could quietly make the test pass; and some of what this arms — a redirect out of the domain, an answer buried in
 * junk — is precisely what the library exists to cope with, so it cannot be asked for through it.
 *
 * @since 1.1.0
 */
class FixtureControl
{
	public function __construct(private readonly string $baseUrl)
	{
	}

	/**
	 * Returns the fixture to the state every test starts from.
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	public function reset(): void
	{
		$this->call('reset');
	}

	/**
	 * Makes the next request to this server answer with a redirect instead of the API response.
	 *
	 * @param   string  $target  The Location to send. May be absolute, root-relative, scheme-relative or relative.
	 * @param   int     $status  The redirect status
	 * @param   int     $count   How many requests to do this for
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	public function armRedirect(string $target, int $status = 302, int $count = 1): void
	{
		$this->call('arm-redirect', ['to' => $target, 'status' => $status, 'count' => $count]);
	}

	/**
	 * Makes the next response arrive wrapped in the sort of junk a badly configured site emits around it.
	 *
	 * @param   string  $where  'before', 'after', 'both', or 'hashes' for the triple hash markers older versions use
	 * @param   int     $count  How many responses to do this to
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	public function armJunk(string $where = 'before', int $count = 1): void
	{
		$this->call('arm-junk', ['where' => $where, 'count' => $count]);
	}

	/**
	 * Every request this server has been sent since the last reset.
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	public function requests(): array
	{
		return $this->call('requests')['requests'] ?? [];
	}

	/**
	 * The backup records this server currently holds.
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	public function records(): array
	{
		return $this->call('records')['records'] ?? [];
	}

	/**
	 * The checksum and length of one part of one backup archive, so a download can be checked byte for byte.
	 *
	 * @param   int  $recordId  The backup record
	 * @param   int  $part      The part number
	 *
	 * @return  array  ['sha256' => string, 'size' => int]
	 * @since   1.1.0
	 */
	public function archivePart(int $recordId, int $part = 1): array
	{
		return $this->call('archive-part', ['backup_id' => $recordId, 'part' => $part]);
	}

	/**
	 * Does this server hold a backup record with this ID?
	 *
	 * @param   int  $recordId  The record to look for
	 *
	 * @return  bool
	 * @since   1.1.0
	 */
	public function hasRecord(int $recordId): bool
	{
		foreach ($this->records() as $record)
		{
			if ((int) $record['id'] === $recordId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Calls the fixture's control panel.
	 *
	 * @param   string  $action  The action to run
	 * @param   array   $query   Its arguments
	 *
	 * @return  array  The decoded answer
	 * @since   1.1.0
	 */
	private function call(string $action, array $query = []): array
	{
		$url  = $this->baseUrl . '/control.php?' . http_build_query(array_merge(['action' => $action], $query));
		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 15);

		$body   = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($curl);

		// No curl_close() here: it has done nothing since PHP 8.0 and is deprecated from PHP 8.5 on.

		if ($body === false || $status !== 200)
		{
			throw new RuntimeException(
				sprintf('The fixture control panel refused ‘%s’ (HTTP %d) %s', $url, $status, $error)
			);
		}

		return json_decode((string) $body, true) ?: [];
	}
}
