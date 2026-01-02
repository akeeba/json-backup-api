<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The remote server is using a version of Akeeba Backup / Solo which is too old for this connector library.
 *
 * @since  1.0.0
 */
class RemoteApiVersionTooLow extends ApiException
{
	public function __construct(int $code = 102, ?Exception $previous = null)
	{
		$message = 'You need to install a newer version of Akeeba Backup / Akeeba Solo on your site';

		parent::__construct($message, $code, $previous);
	}
}
