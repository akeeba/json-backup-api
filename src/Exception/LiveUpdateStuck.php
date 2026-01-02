<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * The remote server reports the backup system is stuck. Cannot list update status, or install updates to the backup
 * software.
 *
 * @since  1.0.0
 */
class LiveUpdateStuck extends RuntimeException
{
	public function __construct(string $extra = '', int $code = 113, ?Throwable $previous = null)
	{
		$message = 'The update system reports that it\'s stuck trying to load update information.';

		if (!empty($extra))
		{
			$message .= ' ' . $extra;
		}

		parent::__construct($message, $code, $previous);
	}

}
