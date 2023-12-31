<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * Could not perform cleanup after updating the backup software.
 *
 * @since  1.0.0
 */
class LiveUpdateCleanupError extends RuntimeException
{
	public function __construct(string $errorMessage, int $code = 118, Throwable $previous = null)
	{
		$message = sprintf('Update failed to clean up with error ‘%s’', $errorMessage);

		parent::__construct($message, $code, $previous);
	}

}
