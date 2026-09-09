<?php
/**
 * A credential sink.
 *
 * This is what a redirect the library is supposed to refuse points at. It records every request it receives, so a test
 * can assert not only that the refusal happened but that nothing was ever delivered here — which is the assertion that
 * actually matters, and the one that would fail if the guard were removed.
 */

require __DIR__ . '/_fixture/fixture.php';

fixture_record_request();

header('Content-Type: application/json; charset=utf-8');

echo json_encode(['status' => 200, 'data' => 'YOU HAVE BEEN PHISHED']);
