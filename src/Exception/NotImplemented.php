<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * What you asked me to do is no longer implemented by the Akeeba Backup JSON API on the remote server.
 *
 * @since  1.0.0
 */
class NotImplemented extends ApiException
{
	public function __construct(string $method = '', int $code = 44, ?Exception $previous = null)
	{
		$message = sprintf('The method %s is no longer implemented by the Akeeba Remote JSON API on your server.', $method);

		parent::__construct($message, $code, $previous);
	}

}