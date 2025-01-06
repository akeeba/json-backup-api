<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * Missing required option: backup record ID
 *
 * @since  1.0.0
 */
class NoBackupID extends RuntimeException
{
	public function __construct(int $code = 31, ?Exception $previous = null)
	{
		$message = 'You must specify a numeric backup ID';

		parent::__construct($message, $code, $previous);
	}

}
