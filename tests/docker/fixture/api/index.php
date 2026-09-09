<?php
/**
 * Joomla!'s API application: the v3 JSON API lives here.
 *
 * The method is a path segment of the route rather than a query string parameter, and the credential travels in a
 * request header rather than in the URL — which is the whole point of the v3 API, since it keeps the credential out of
 * the server's access log.
 */

require __DIR__ . '/../_fixture/legacy.php';

fixture_record_request();
fixture_maybe_redirect();

/**
 * Joomla!'s API application answers 406 to a request which does not say what it will accept. Akeeba Backup's
 * webservices plugin papers over that for its own routes, but a request which fails to match one gets the raw 406.
 */
if (!isset($_SERVER['HTTP_ACCEPT']) || trim((string) $_SERVER['HTTP_ACCEPT']) === '')
{
	http_response_code(406);

	echo json_encode(['errors' => [['title' => 'Not Acceptable']]]);

	exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';

if (!preg_match('#/v3/akeebabackup/([^/?]+)$#', $path, $matches))
{
	http_response_code(404);

	echo json_encode(['errors' => [['title' => 'Not Found']]]);

	exit;
}

$apiMethod = rawurldecode($matches[1]);
$token     = trim((string) ($_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? ''));
$secret    = trim((string) ($_SERVER['HTTP_X_AKEEBA_AUTH'] ?? ''));

/**
 * The two credentials are mutually exclusive on the real server: a Secret Word makes it ignore any token in the same
 * request, and a wrong Secret Word is a hard failure rather than a fall-through to the token.
 */
$restricted = false;

if ($secret !== '')
{
	if (!hash_equals(fixture_secret(), $secret))
	{
		fixture_emit_modern(503, 'Authentication failed');

		exit;
	}
}
elseif ($token !== '')
{
	if (hash_equals(fixture_restricted_token(), $token))
	{
		$restricted = true;
	}
	elseif (!hash_equals(fixture_token(), $token))
	{
		fixture_emit_modern(503, 'Authentication failed');

		exit;
	}
}
else
{
	fixture_emit_modern(503, 'No credential presented');

	exit;
}

if (!fixture_authorised($apiMethod, $restricted))
{
	fixture_emit_modern(403, sprintf('You are not allowed to call %s', $apiMethod));

	exit;
}

$data = array_merge($_GET, $_POST);

if ($apiMethod === 'downloadDirect')
{
	fixture_send_archive_part((int) ($data['backup_id'] ?? 0), (int) ($data['part_id'] ?? 1));

	exit;
}

[$status, $payload] = fixture_dispatch($apiMethod, $data);

fixture_emit_modern($status, $payload);
