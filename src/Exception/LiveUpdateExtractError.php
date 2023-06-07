<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * I tried installing the update to the backup software, but extracting the update package failed with an error.
 *
 * @since  1.0.0
 */
class LiveUpdateExtractError extends RuntimeException
{
	public function __construct(string $errorMessage, int $code = 116, Throwable $previous = null)
	{
		$message = sprintf('Update package failed to extract with error ‘%s’', $errorMessage);

		parent::__construct($message, $code, $previous);
	}

}
