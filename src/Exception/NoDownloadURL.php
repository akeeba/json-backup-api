<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * You asked me to download something over cURL, but you did not provide the cURL URL.
 *
 * @since  1.0.0
 */
class NoDownloadURL extends RuntimeException
{
	public function __construct(int $code = 34, ?Exception $previous = null)
	{
		$message = 'You must provide a download URL for use with cURL';

		parent::__construct($message, $code, $previous);
	}

}
