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
 * Missing configuration options: secret key
 *
 * @since  1.0.0
 */
class NoConfiguredSecret extends RuntimeException
{
	public function __construct(int $code = 37, Exception $previous = null)
	{
		$message = 'You did not specify a secret key.';

		parent::__construct($message, $code, $previous);
	}

}
