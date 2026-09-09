<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * Missing configuration options: neither a Secret Word nor a Joomla! API token was given
 *
 * @since  1.0.0
 */
class NoConfiguredSecret extends RuntimeException
{
	public function __construct(int $code = 37, ?Exception $previous = null)
	{
		$message = 'You did not specify a credential to authenticate with. Provide either a Joomla! API token (recommended; JSON API v3 only) or the Akeeba Backup JSON API Secret Word.';

		parent::__construct($message, $code, $previous);
	}

}
