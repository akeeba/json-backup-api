<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The remote server encountered an error trying to execute the API request you specified.
 *
 * @since  1.0.0
 */
class RemoteError extends ApiException
{
	public function __construct(string $errorMessage, int $code = 101, ?Exception $previous = null)
	{
		$message = sprintf('The remote JSON API on your server reports an error with message ‘%s’', $errorMessage);

		parent::__construct($message, $code, $previous);
	}
}
