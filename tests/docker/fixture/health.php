<?php
/**
 * The health check the Docker stack waits on before the tests are allowed to start.
 */

require __DIR__ . '/_fixture/fixture.php';

fixture_state();

header('Content-Type: text/plain');

echo 'OK';
