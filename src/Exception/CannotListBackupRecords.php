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
 * Cannot produce a listing of backup records.
 *
 * @since  1.0.0
 */
class CannotListBackupRecords extends RuntimeException
{
	public function __construct(int $code = 108, Throwable $previous = null)
	{
		$message = 'Could not list backup records';

		parent::__construct($message, $code, $previous);
	}

}
