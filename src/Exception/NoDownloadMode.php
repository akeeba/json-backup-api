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
 * Missing download option: download mode.
 *
 * @since  1.0.0
 */
class NoDownloadMode extends RuntimeException
{
	public function __construct(int $code = 32, ?Exception $previous = null)
	{
		$message = 'You must specify a download mode (http, curl or chunk).';

		parent::__construct($message, $code, $previous);
	}

}
