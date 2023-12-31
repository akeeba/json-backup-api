<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The returned API response contains invalid JSON and cannot be processed.
 *
 * This can only happen with APIv1. It used ot be the case this would happen with encrypted replies if the encrypted
 * data could not be processed, but since we are no longer using encrypted data the problem is most likely with a bad
 * transformation from a proxy server.
 *
 * @since  1.0.0
 */
class InvalidJSONBody extends ApiException
{
	public function __construct(int $code = 21, Exception $previous = null)
	{
		$message = 'Invalid response body. Something between the web server and this client is corrupting the response.';

		parent::__construct($message, $code, $previous);
	}

}
