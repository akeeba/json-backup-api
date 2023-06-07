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
 * The remote server supports that the update system is not supported. Cannot apply updates to the backup software.
 *
 * @since  1.0.0
 */
class LiveUpdateSupport extends RuntimeException
{
	public function __construct(int $code = 112, Throwable $previous = null)
	{
		$message = 'Your server does not support the update system.';

		parent::__construct($message, $code, $previous);
	}

}
