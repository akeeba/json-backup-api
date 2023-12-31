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
 * Cannot list the backup profiles.
 *
 * @since  1.0.0
 */
class CannotListProfiles extends RuntimeException
{
	public function __construct(int $code = 109, Throwable $previous = null)
	{
		$message = 'Cannot list backup records.';

		parent::__construct($message, $code, $previous);
	}

}
