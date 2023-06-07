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
 * Cannot retrieve the software update information.
 *
 * @since  1.0.0
 */
class CannotGetUpdateInformation extends RuntimeException
{
	public function __construct(int $code = 111, Throwable $previous = null)
	{
		$message = 'Cannot retrieve update information.';

		parent::__construct($message, $code, $previous);
	}

}
