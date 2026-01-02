<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The provided API key (Secret Word) is invalid.
 *
 * @since  1.0.0
 */
class InvalidSecretWord extends ApiException
{
	public function __construct(int $code = 42, ?Exception $previous = null)
	{
		$message = 'Authentication error (invalid Secret Word). Please check the secret word, make sure it doesn\'t have any whitespace you missed. Clear any site or external caches, making sure Akeeba Backup\'s URL isn\'t cached.';

		parent::__construct($message, $code, $previous);
	}
}
