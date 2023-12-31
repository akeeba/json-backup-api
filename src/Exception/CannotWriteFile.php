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
 * Cannot write to a local file.
 *
 * @since  1.0.0
 */
class CannotWriteFile extends RuntimeException
{
	public function __construct(string $filePath, int $code = 104, Exception $previous = null)
	{
		$message = sprintf('Cannot open file ‘%s’ for writing.', $filePath);

		parent::__construct($message, $code, $previous);
	}

}
