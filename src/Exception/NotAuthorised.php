<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * We authenticated successfully, but we are not allowed to call the API method we asked for.
 *
 * This can only happen on the JSON API v3, authenticating with a Joomla! API token. Since Akeeba Backup 10.4.0 every
 * v3 API method requires the same privilege on Akeeba Backup as the equivalent operation in the site's backend, and
 * since Joomla! 5.4 an API token may belong to an account which is not a Super User. Authenticating with the Secret
 * Word cannot produce this error: it is a blanket grant over every method.
 *
 * @since  1.1.0
 */
class NotAuthorised extends ApiException
{
	public function __construct(?string $apiMethod = null, int $code = 43, ?Exception $previous = null)
	{
		$message = sprintf(
			'Authorisation error: the Joomla! user account behind the API token is not allowed to call the API method ‘%s’. Grant that account the corresponding Akeeba Backup privilege in Components, Akeeba Backup, Options, Permissions.',
			$apiMethod ?? 'unknown'
		);

		parent::__construct($message, $code, $previous);
	}
}
