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
 * There is a new version but it does not meet the minimum update stability requirements. Cannot install the update to
 * the backup software.
 *
 * @since  1.0.0
 */
class LiveUpdateStability extends RuntimeException
{
	public function __construct(int $code = 114, Throwable $previous = null)
	{
		$message = 'The available update is less stable than the minimum stability you have chosen for updates. As a result the update will not proceed.';

		parent::__construct($message, $code, $previous);
	}

}
