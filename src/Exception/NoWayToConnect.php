<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * I tried all alternative methods to connect to the remote server but nothing worked.
 *
 * @since  1.0.0
 */
class NoWayToConnect extends ApiException
{
	public function __construct(int $code = 36, Exception $previous = null)
	{
		$message = 'We cannot find a way to connect to your server. It seems that your server is incompatible with Akeeba Remote Control CLI.';

		parent::__construct($message, $code, $previous);
	}
}
