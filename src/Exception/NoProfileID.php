<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * You did not provide the profile ID you want me to operate on.
 *
 * @since  1.0.0
 */
class NoProfileID extends RuntimeException
{
	public function __construct(int $code = 39, Exception $previous = null)
	{
		$message = 'You must specify a numeric profile ID';

		parent::__construct($message, $code, $previous);
	}

}
