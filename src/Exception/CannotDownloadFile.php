<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * Cannot download a file from the remote server.
 *
 * This error indicates that the problem happens while trying to retrieve the file data. We have not tried to write it
 * to a local file just yet.
 *
 * @since  1.0.0
 */
class CannotDownloadFile extends RuntimeException
{
	public function __construct(string $message, int $code = 105, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}

}
