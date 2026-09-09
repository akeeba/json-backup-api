<?php
/**
 * The fixture's control panel.
 *
 * Tests drive this directly, out of band of the library under test, to put the server into a state the library cannot
 * ask for: redirecting somewhere hostile, burying its answer in PHP warnings, or looping forever. It also hands back
 * the record of what the server was actually sent.
 */

require __DIR__ . '/_fixture/fixture.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? '');

switch ($action)
{
	case 'reset':
		fixture_reset();

		echo json_encode(['ok' => true]);

		break;

	case 'arm-redirect':
		$state            = fixture_state();
		$state['armed'][] = [
			'kind'   => 'redirect',
			'to'     => (string) ($_GET['to'] ?? '/'),
			'status' => (int) ($_GET['status'] ?? 302),
			'count'  => max(1, (int) ($_GET['count'] ?? 1)),
		];

		fixture_save($state);

		echo json_encode(['ok' => true]);

		break;

	case 'arm-junk':
		$state            = fixture_state();
		$state['armed'][] = [
			'kind'  => 'junk',
			'where' => (string) ($_GET['where'] ?? 'before'),
			'count' => max(1, (int) ($_GET['count'] ?? 1)),
		];

		fixture_save($state);

		echo json_encode(['ok' => true]);

		break;

	case 'requests':
		echo json_encode(['requests' => fixture_state()['requests']]);

		break;

	case 'records':
		echo json_encode(['records' => fixture_state()['records']]);

		break;

	case 'archive-part':
		$recordId = (int) ($_GET['backup_id'] ?? 0);
		$part     = (int) ($_GET['part'] ?? 1);

		echo json_encode(
			[
				'sha256' => hash('sha256', fixture_archive_bytes($recordId, $part)),
				'size'   => strlen(fixture_archive_bytes($recordId, $part)),
			]
		);

		break;

	default:
		http_response_code(400);

		echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
