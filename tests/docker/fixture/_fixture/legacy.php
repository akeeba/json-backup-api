<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

require_once __DIR__ . '/fixture.php';

/**
 * Serves a request to the v1 or the v2 JSON API.
 *
 * Both are addressed through the site's own front-end entry point — index.php on Joomla!, remote.php on Akeeba Solo,
 * wp-admin/admin-ajax.php on WordPress — and both authenticate with the Secret Word and nothing else. The version is
 * told apart by the view: 'json' is v1, 'Api' is v2.
 *
 * @param   string|null  $forcedView  The view to assume, for an entry point which does not carry one (WordPress)
 *
 * @return  void
 */
function fixture_serve_legacy(?string $forcedView = null): void
{
	fixture_record_request();
	fixture_maybe_redirect();

	$request = array_merge($_GET, $_POST);
	$view    = strtolower((string) ($forcedView ?? $request['view'] ?? ''));

	if ($view === 'json')
	{
		fixture_serve_api_v1($request);

		return;
	}

	if ($view !== 'api')
	{
		/**
		 * Neither API was addressed. A real site would answer with its home page, which is exactly the sort of thing
		 * the client has to recognise as “not an API response”.
		 */
		header('Content-Type: text/html; charset=utf-8');

		echo '<!DOCTYPE html><html lang="en"><head><title>Example site</title></head><body>'
			. '<h1>Welcome to the example site</h1></body></html>';

		return;
	}

	fixture_serve_api_v2($request);
}

/**
 * Serves a v2 API request.
 *
 * @param   array  $request  The merged query string and POST body
 *
 * @return  void
 */
function fixture_serve_api_v2(array $request): void
{
	$apiMethod = (string) ($request['method'] ?? '');
	$secret    = (string) ($request['_akeebaAuth'] ?? '');

	if (!hash_equals(fixture_secret(), $secret))
	{
		fixture_emit_modern(503, 'Authentication failed');

		return;
	}

	// downloadDirect answers with the archive itself, not with JSON.
	if ($apiMethod === 'downloadDirect')
	{
		fixture_send_archive_part((int) ($request['backup_id'] ?? 0), (int) ($request['part_id'] ?? 1));

		return;
	}

	[$status, $data] = fixture_dispatch($apiMethod, $request);

	fixture_emit_modern($status, $data);
}

/**
 * Serves a v1 API request.
 *
 * The v1 API encapsulates in both directions: the request arrives as a JSON document inside a query string parameter,
 * and the response goes back as a JSON string inside a JSON document, between triple hash markers.
 *
 * @param   array  $request  The merged query string and POST body
 *
 * @return  void
 */
function fixture_serve_api_v1(array $request): void
{
	$encapsulated = json_decode((string) ($request['json'] ?? ''), true);
	$body         = json_decode((string) ($encapsulated['body'] ?? ''), true);

	if (!is_array($body))
	{
		fixture_emit_v1(503, 'Malformed request');

		return;
	}

	if (!fixture_v1_challenge_is_valid((string) ($body['challenge'] ?? '')))
	{
		fixture_emit_v1(503, 'Authentication failed');

		return;
	}

	$apiMethod = (string) ($body['method'] ?? '');
	$data      = (array) ($body['data'] ?? []);

	// downloadDirect answers with the archive itself, not with JSON, on every version of the API.
	if ($apiMethod === 'downloadDirect')
	{
		fixture_send_archive_part((int) ($data['backup_id'] ?? 0), (int) ($data['part_id'] ?? 1));

		return;
	}

	[$status, $payload] = fixture_dispatch($apiMethod, $data);

	fixture_emit_v1($status, $payload);
}

/**
 * Checks a v1 challenge, which is a random salt and the MD5 of that salt concatenated with the Secret Word.
 *
 * @param   string  $challenge  The challenge as it arrived
 *
 * @return  bool
 */
function fixture_v1_challenge_is_valid(string $challenge): bool
{
	if (!str_contains($challenge, ':'))
	{
		return false;
	}

	[$salt, $digest] = explode(':', $challenge, 2);

	return hash_equals(md5($salt . fixture_secret()), $digest);
}

/**
 * Sends a v2 or v3 shaped response.
 *
 * @param   int    $status  The API status
 * @param   mixed  $data    The payload
 *
 * @return  void
 */
function fixture_emit_modern(int $status, mixed $data): void
{
	header('Content-Type: application/json; charset=utf-8');

	echo fixture_decorate((string) json_encode(['status' => $status, 'data' => $data]));
}

/**
 * Sends a v1 shaped response.
 *
 * @param   int    $status  The API status
 * @param   mixed  $data    The payload
 *
 * @return  void
 */
function fixture_emit_v1(int $status, mixed $data): void
{
	header('Content-Type: text/plain; charset=utf-8');

	$document = json_encode(
		[
			'body' => [
				'status' => $status,
				'data'   => json_encode($data),
			],
		]
	);

	echo fixture_decorate('###' . $document . '###');
}
