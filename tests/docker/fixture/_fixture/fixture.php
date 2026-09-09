<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

/**
 * The Akeeba Backup JSON API, as far as a client can tell.
 *
 * This is the far end of the integration suite: a real web server, answering real HTTP, speaking all three versions of
 * the JSON API with the response shapes, the authentication rules and the error statuses the real thing uses. It is
 * not Akeeba Backup — it takes no backups and writes no archives worth the name — and it does not need to be. What the
 * client library does is talk to a server, and everything the tests assert about it is observable from this side of
 * the conversation.
 *
 * It also does what a real server cannot be asked to do on demand: redirect somewhere hostile, bury its answer in PHP
 * warnings, or loop forever. Those behaviours are armed one request at a time through control.php.
 *
 * @since 1.1.0
 */

const FIXTURE_STATE_FILE = '/var/www/state/state.json';

/**
 * The API level this fixture reports. It has to be at or above the library's declared minimum.
 */
const FIXTURE_API_LEVEL = 400;

/**
 * The methods the restricted API token is not allowed to call.
 *
 * Since Akeeba Backup 10.4.0 the v3 API enforces the token's Joomla! user account privileges per method, so a token
 * can authenticate perfectly well and still be refused.
 */
const FIXTURE_RESTRICTED_METHODS = ['delete', 'deleteFiles', 'download', 'downloadDirect'];

/**
 * Reads the whole fixture state.
 *
 * @return  array
 */
function fixture_state(): array
{
	if (!is_file(FIXTURE_STATE_FILE))
	{
		fixture_reset();
	}

	return json_decode((string) file_get_contents(FIXTURE_STATE_FILE), true) ?: [];
}

/**
 * Writes the whole fixture state back.
 *
 * @param   array  $state  The state to write
 *
 * @return  void
 */
function fixture_save(array $state): void
{
	file_put_contents(FIXTURE_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Returns the fixture to the state every test starts from.
 *
 * @return  void
 */
function fixture_reset(): void
{
	@mkdir(dirname(FIXTURE_STATE_FILE), 0o777, true);

	fixture_save(
		[
			// Behaviours armed for the next few requests. See control.php.
			'armed'    => [],
			// Every request this container has been sent, so a test can ask what actually arrived.
			'requests' => [],
			'profiles' => [
				['id' => 1, 'name' => 'Default backup profile'],
				['id' => 2, 'name' => 'Nightly'],
			],
			/**
			 * The backup records. Sizes are the length of the bytes fixture_archive_bytes() generates, so a client
			 * which truncates a download is caught by the library's own size check.
			 */
			'records'  => [
				[
					'id'          => 1,
					'description' => 'A single part backup',
					'comment'     => '',
					'meta'        => 'ok',
					'multipart'   => 1,
					'files'       => [
						['part' => 1, 'name' => 'single-2026-01-01.jpa'],
					],
				],
				[
					'id'          => 2,
					'description' => 'A three part backup',
					'comment'     => '',
					'meta'        => 'ok',
					'multipart'   => 3,
					'files'       => [
						['part' => 1, 'name' => 'multi-2026-01-02.j01'],
						['part' => 2, 'name' => 'multi-2026-01-02.j02'],
						['part' => 3, 'name' => 'multi-2026-01-02.jpa'],
					],
				],
				[
					// Its archives were deleted after being uploaded to remote storage.
					'id'          => 3,
					'description' => 'A backup whose files are gone',
					'comment'     => '',
					'meta'        => 'obsolete',
					'multipart'   => 0,
					'files'       => [],
				],
			],
			// Backups in progress, keyed by the backup ID handed out by startBackup.
			'backups'  => [],
			'nextId'   => 4,
		]
	);
}

/**
 * The bytes of one part of one backup archive.
 *
 * Deterministic, so both ends can work out what the whole part should look like, and long enough that a chunked
 * download takes several chunks.
 *
 * @param   int  $recordId  The backup record
 * @param   int  $part      The part number, 1-based
 *
 * @return  string
 */
function fixture_archive_bytes(int $recordId, int $part): string
{
	return str_repeat(sprintf('AKEEBA-%d-%02d;', $recordId, $part), 4096);
}

/**
 * The Secret Word this fixture accepts.
 *
 * @return  string
 */
function fixture_secret(): string
{
	return (string) getenv('FIXTURE_SECRET');
}

/**
 * The Joomla! API token this fixture accepts, belonging to an account allowed to do everything.
 *
 * @return  string
 */
function fixture_token(): string
{
	return (string) getenv('FIXTURE_TOKEN');
}

/**
 * A Joomla! API token belonging to an account which may take backups but not download or delete them.
 *
 * @return  string
 */
function fixture_restricted_token(): string
{
	return (string) getenv('FIXTURE_RESTRICTED_TOKEN');
}

/**
 * Records the request which is being served, so a test can ask what actually reached this container.
 *
 * This is what makes “the credential was not disclosed” an assertion about the world rather than about a log line: the
 * sink container records everything it is sent, and a test can prove a refused redirect never delivered anything.
 *
 * @return  void
 */
function fixture_record_request(): void
{
	$state = fixture_state();

	$state['requests'][] = [
		'time'    => microtime(true),
		'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
		'uri'     => $_SERVER['REQUEST_URI'] ?? '',
		'host'    => $_SERVER['HTTP_HOST'] ?? '',
		'query'   => $_GET,
		'body'    => $_POST,
		'headers' => [
			'X-Joomla-Token' => $_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? null,
			'X-Akeeba-Auth'  => $_SERVER['HTTP_X_AKEEBA_AUTH'] ?? null,
			'User-Agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
			'Accept'         => $_SERVER['HTTP_ACCEPT'] ?? null,
			'Range'          => $_SERVER['HTTP_RANGE'] ?? null,
		],
	];

	fixture_save($state);
}

/**
 * Takes the next armed behaviour of a kind, if there is one, decrementing what is left of it.
 *
 * @param   string  $kind  The behaviour to look for
 *
 * @return  array|null  The armed behaviour, or NULL if none is armed
 */
function fixture_take_armed(string $kind): ?array
{
	$state = fixture_state();
	$armed = $state['armed'] ?? [];

	foreach ($armed as $index => $entry)
	{
		if (($entry['kind'] ?? '') !== $kind)
		{
			continue;
		}

		$entry['count']--;

		if ($entry['count'] <= 0)
		{
			unset($armed[$index]);
		}
		else
		{
			$armed[$index] = $entry;
		}

		$state['armed'] = array_values($armed);

		fixture_save($state);

		return $entry;
	}

	return null;
}

/**
 * Sends a redirect and stops, if one is armed.
 *
 * @return  void
 */
function fixture_maybe_redirect(): void
{
	$armed = fixture_take_armed('redirect');

	if ($armed === null)
	{
		return;
	}

	/**
	 * The Location is a template, because a test cannot know the URL the library will build. {uri} is the request as
	 * it arrived, so `http://example.test{uri}` is “the same request, on a different host” — which is exactly the
	 * shape of the redirect a real site issues when it moves you between www and non-www.
	 */
	$target = strtr(
		(string) ($armed['to'] ?? '/'),
		[
			'{uri}'   => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
			'{path}'  => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
			'{query}' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? ''),
		]
	);
	$status = (int) ($armed['status'] ?? 302);

	header('Location: ' . $target, true, $status);

	/**
	 * A redirect has a body of its own, and the download clients write whatever they receive straight into the file
	 * they are downloading. If they do not throw this away before the next hop it ends up prepended to the archive,
	 * so the body has to be here for the test to be worth anything.
	 */
	echo str_repeat('THIS IS A REDIRECT COURTESY PAGE. IT MUST NOT END UP IN YOUR ARCHIVE. ', 16);

	exit;
}

/**
 * Wraps a JSON response the way whichever junk behaviour is armed says to.
 *
 * @param   string  $json  The response the API meant to send
 *
 * @return  string
 */
function fixture_decorate(string $json): string
{
	$armed = fixture_take_armed('junk');

	if ($armed === null)
	{
		return $json;
	}

	$notice  = '<br />' . "\n" . '<b>Notice</b>: Undefined index: foo in <b>/var/www/html/wp-content/plugins/'
		. 'something/awful.php</b> on line <b>17</b><br />' . "\n";
	$comment = "\n" . '<!-- Page generated by a caching plugin in 0.031 seconds -->';

	return match ($armed['where'] ?? 'before')
	{
		'after'  => $json . $comment,
		'both'   => $notice . $json . $comment,
		'hashes' => '###' . $json . '###',
		default  => $notice . $json,
	};
}

/**
 * Is this credential allowed to call this method?
 *
 * @param   string  $apiMethod   The method being called
 * @param   bool    $restricted  Whether the caller authenticated with the restricted token
 *
 * @return  bool
 */
function fixture_authorised(string $apiMethod, bool $restricted): bool
{
	return !$restricted || !in_array($apiMethod, FIXTURE_RESTRICTED_METHODS, true);
}

/**
 * Runs an API method.
 *
 * @param   string  $apiMethod  The method to run
 * @param   array   $data       Its payload
 *
 * @return  array  [int $status, mixed $data]
 */
function fixture_dispatch(string $apiMethod, array $data): array
{
	$state = fixture_state();

	switch ($apiMethod)
	{
		case 'getVersion':
			return [
				200,
				[
					'api'      => FIXTURE_API_LEVEL,
					'version'  => '10.4.0',
					'date'     => '2026-01-01',
					'edition'  => 'pro',
					'secret'   => '',
				],
			];

		case 'getProfiles':
			return [200, $state['profiles']];

		case 'listBackups':
			$from  = max(0, (int) ($data['from'] ?? 0));
			$limit = min(max(1, (int) ($data['limit'] ?? 200)), 200);

			return [200, array_slice($state['records'], $from, $limit)];

		case 'getBackupInfo':
			$record = fixture_find_record($state, (int) ($data['backup_id'] ?? 0));

			if ($record === null)
			{
				return [404, 'No such backup record'];
			}

			return [
				200,
				[
					'id'          => $record['id'],
					'description' => $record['description'],
					'comment'     => $record['comment'],
					'meta'        => $record['meta'],
					'multipart'   => $record['multipart'],
					'filenames'   => array_map(
						fn(array $file) => [
							'part' => $file['part'],
							'name' => $file['name'],
							'size' => strlen(fixture_archive_bytes($record['id'], $file['part'])),
						],
						$record['files']
					),
				],
			];

		case 'delete':
		case 'deleteFiles':
			$record = fixture_find_record($state, (int) ($data['backup_id'] ?? 0));

			if ($record === null)
			{
				return [404, 'No such backup record'];
			}

			$state['records'] = array_values(
				array_map(
					function (array $candidate) use ($record, $apiMethod) {
						if ($candidate['id'] !== $record['id'])
						{
							return $candidate;
						}

						$candidate['files']     = [];
						$candidate['multipart'] = 0;
						$candidate['meta']      = $apiMethod === 'delete' ? 'deleted' : 'obsolete';

						return $candidate;
					},
					$state['records']
				)
			);

			if ($apiMethod === 'delete')
			{
				$state['records'] = array_values(
					array_filter($state['records'], fn(array $candidate) => $candidate['id'] !== $record['id'])
				);
			}

			fixture_save($state);

			return [200, 'true'];

		case 'startBackup':
			$backupId = 'bkp' . bin2hex(random_bytes(4));
			$recordId = $state['nextId']++;

			$state['backups'][$backupId] = [
				'recordId'    => $recordId,
				'step'        => 0,
				'archive'     => sprintf('remote-%d.jpa', $recordId),
				'description' => (string) ($data['description'] ?? ''),
			];

			$state['records'][] = [
				'id'          => $recordId,
				'description' => (string) ($data['description'] ?? ''),
				'comment'     => (string) ($data['comment'] ?? ''),
				'meta'        => 'ok',
				'multipart'   => 1,
				'files'       => [['part' => 1, 'name' => sprintf('remote-%d.jpa', $recordId)]],
			];

			fixture_save($state);

			return [
				200,
				[
					'HasRun'   => true,
					'Domain'   => 'init',
					'Step'     => 'Initialising',
					'Substep'  => '',
					'Progress' => 0,
					'Warnings' => [],
					'Error'    => '',
					'backupid' => $backupId,
					'BackupID' => $recordId,
					'Archive'  => sprintf('remote-%d.jpa', $recordId),
				],
			];

		case 'stepBackup':
			$backupId = (string) ($data['backupid'] ?? '');

			if (!isset($state['backups'][$backupId]))
			{
				return [404, 'No such backup in progress'];
			}

			$state['backups'][$backupId]['step']++;
			$step = $state['backups'][$backupId]['step'];

			fixture_save($state);

			// Three steps, then done. Enough for a client to have to loop, few enough to stay quick.
			return [
				200,
				[
					'HasRun'   => $step < 3,
					'Domain'   => $step < 3 ? 'Packing' : 'finale',
					'Step'     => $step < 3 ? 'Archiving files' : 'Finished',
					'Substep'  => sprintf('batch %d', $step),
					'Progress' => $step * 33,
					'Warnings' => $step === 2 ? ['Could not read /var/www/html/unreadable.txt'] : [],
					'Error'    => '',
				],
			];

		case 'download':
			$record = fixture_find_record($state, (int) ($data['backup_id'] ?? 0));
			$part   = (int) ($data['part'] ?? 1);

			if ($record === null || !fixture_has_part($record, $part))
			{
				return [404, 'No such part'];
			}

			$bytes     = fixture_archive_bytes($record['id'], $part);
			$chunkSize = max(1, (int) ($data['chunk_size'] ?? 1)) * 1024;
			$segment   = max(1, (int) ($data['segment'] ?? 1));
			$offset    = ($segment - 1) * $chunkSize;

			if ($offset >= strlen($bytes))
			{
				return [404, 'Past the end of the file'];
			}

			return [200, base64_encode(substr($bytes, $offset, $chunkSize))];

		case 'updateGetInformation':
			return [
				200,
				[
					'supported'  => true,
					'stuck'      => false,
					'hasUpdates' => true,
					'version'    => '10.4.1',
					'date'       => '2026-02-01',
					'stability'  => 'stable',
					'infoURL'    => 'https://www.example.test/release-notes',
				],
			];

		case 'updateDownload':
		case 'updateExtract':
		case 'updateInstall':
		case 'updateCleanup':
			return [200, 'true'];

		case 'exportConfiguration':
			$profile = (int) ($data['profile'] ?? 0);

			foreach ($state['profiles'] as $candidate)
			{
				if ($candidate['id'] === $profile)
				{
					return [
						200,
						[
							'description'                   => $candidate['name'],
							'akeeba.basic.output_directory' => '/var/www/html/backups',
						],
					];
				}
			}

			return [404, 'No such profile'];

		case 'importConfiguration':
			$profile = [
				'id'   => count($state['profiles']) + 1,
				'name' => 'Imported profile',
			];

			$state['profiles'][] = $profile;

			fixture_save($state);

			// A list, not a map: the library's ImportConfiguration declares an array return type.
			return [200, [$profile['id']]];
	}

	// The status a real server uses for a method it has never heard of.
	return [405, sprintf('Unknown method %s', $apiMethod)];
}

/**
 * Finds a backup record by its ID.
 *
 * @param   array  $state  The fixture state
 * @param   int    $id     The record ID
 *
 * @return  array|null
 */
function fixture_find_record(array $state, int $id): ?array
{
	foreach ($state['records'] as $record)
	{
		if ((int) $record['id'] === $id)
		{
			return $record;
		}
	}

	return null;
}

/**
 * Does this record have this part?
 *
 * @param   array  $record  The backup record
 * @param   int    $part    The part number
 *
 * @return  bool
 */
function fixture_has_part(array $record, int $part): bool
{
	foreach ($record['files'] as $file)
	{
		if ((int) $file['part'] === $part)
		{
			return true;
		}
	}

	return false;
}

/**
 * Streams the raw bytes of one archive part, the way the downloadDirect method does.
 *
 * @param   int  $recordId  The backup record
 * @param   int  $part      The part number
 *
 * @return  void
 */
function fixture_send_archive_part(int $recordId, int $part): void
{
	$state  = fixture_state();
	$record = fixture_find_record($state, $recordId);

	if ($record === null || !fixture_has_part($record, $part))
	{
		http_response_code(404);

		echo 'No such part';

		exit;
	}

	$bytes = fixture_archive_bytes($recordId, $part);

	header('Content-Type: application/octet-stream');
	header('Content-Length: ' . strlen($bytes));

	echo $bytes;

	exit;
}
