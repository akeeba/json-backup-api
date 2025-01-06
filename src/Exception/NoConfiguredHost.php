<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * Missing configuration option: hostname
 *
 * @since  1.0.0
 */
class NoConfiguredHost extends RuntimeException
{
	public function __construct(int $code = 35, ?Exception $previous = null)
	{
		$message = 'You did not specify a host name.';

		parent::__construct($message, $code, $previous);
	}

}
