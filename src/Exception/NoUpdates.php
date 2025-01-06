<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * There are no available updates to the backup software.
 *
 * @since  1.0.0
 */
class NoUpdates extends RuntimeException
{
	public function __construct(int $code = 1, ?Throwable $previous = null)
	{
		$message = 'There are no available updates to your Akeeba Backup / Akeeba Solo installation.';

		parent::__construct($message, $code, $previous);
	}

}
