<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * An HTTP or other network-level error occurred.
 *
 * @since  1.0.0
 */
class CommunicationError extends RuntimeException
{
	public function __construct(int $errCode, string $errMessage, int $code = 22, Exception $previous = null)
	{
		$message = sprintf('Network error %d with message “%s”. Please check the host name and the status of your network connectivity.', $errCode, $errMessage);

		parent::__construct($message, $code, $previous);
	}

}
