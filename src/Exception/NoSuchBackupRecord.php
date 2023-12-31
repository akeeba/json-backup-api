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
 * The backup record you asked me to operate on does not exist on the remote server.
 *
 * @since  1.0.0
 */
class NoSuchBackupRecord extends RuntimeException
{
	public function __construct(int $code = 110, Throwable $previous = null)
	{
		$message = 'The specified backup record does not exist';

		parent::__construct($message, $code, $previous);
	}

}
