<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * You asked me to download something, but you did not tell me where to.
 *
 * @since  1.0.0
 */
class NoDownloadPath extends RuntimeException
{
	public function __construct(int $code = 33, Exception $previous = null)
	{
		$message = 'You must specify a path to download the files to.';

		parent::__construct($message, $code, $previous);
	}

}
