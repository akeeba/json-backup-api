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
 * Cannot delete a backup archive record on the remote site.
 *
 * @since  1.0.0
 */
class CannotDeleteRecord extends RuntimeException
{
	public function __construct(int $id, int $code = 107, Throwable $previous = null)
	{
		$message = sprintf("Cannot delete backup record %d.", $id);

		parent::__construct($message, $code, $previous);
	}

}
