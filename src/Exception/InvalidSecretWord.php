<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * Authentication failed: the credential we presented was rejected.
 *
 * The server answers with the same status whichever credential was presented, and whichever way it was unacceptable:
 * a wrong Secret Word, a wrong or inactive Joomla! API token, an account which may not log into Joomla's API
 * application, or the JSON API being switched off in the component's Options.
 *
 * @since  1.0.0
 */
class InvalidSecretWord extends ApiException
{
	public function __construct(int $code = 42, ?Exception $previous = null)
	{
		$message = 'Authentication error. Please check the credential you are using — a Joomla! API token or the Akeeba Backup Secret Word — and make sure it doesn\'t have any whitespace you missed. Check that the JSON API is enabled in the component\'s Options, or, when using a token, that the account holding it is allowed to log into Joomla\'s API application. Clear any site or external caches, making sure Akeeba Backup\'s URL isn\'t cached.';

		parent::__construct($message, $code, $previous);
	}
}
