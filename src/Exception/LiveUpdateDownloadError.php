<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * I tried to install an update to the backup software, but I couldn't download the update package to the remote server.
 *
 * @since  1.0.0
 */
class LiveUpdateDownloadError extends RuntimeException
{
	public function __construct(string $errorMessage, int $code = 115, ?Throwable $previous = null)
	{
		$message = sprintf('Update download failed with error ‘%s’', $errorMessage);

		parent::__construct($message, $code, $previous);
	}

}
