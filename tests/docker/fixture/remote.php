<?php
/**
 * The Akeeba Solo entry point. It speaks the v2 API only — there is no Joomla! API application behind it.
 */

require __DIR__ . '/_fixture/legacy.php';

fixture_serve_legacy();
