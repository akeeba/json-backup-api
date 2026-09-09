<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The server tried to redirect us somewhere we are not prepared to follow it.
 *
 * Every API request carries a credential — a Joomla! API token or the Akeeba Backup Secret Word — and the HTTP clients
 * forward their request headers along a redirect. Following a redirect out of the domain the caller gave us would
 * therefore hand that credential to whoever the redirect points at. A misconfigured site, an open redirect, or a
 * hostile one can all produce such a redirect, so we refuse it instead of guessing.
 *
 * @since  1.1.0
 */
class UnsafeRedirect extends ApiException
{
	public function __construct(string $fromUrl, string $toUrl, int $code = 44, ?Exception $previous = null)
	{
		$message = sprintf(
			'Refusing to follow a redirect from ‘%s’ to ‘%s’: it leaves the domain you asked us to connect to, and following it would disclose your credential to that host. Check for a redirect on your site, and give us the URL your site actually answers on.',
			$fromUrl,
			$toUrl
		);

		parent::__construct($message, $code, $previous);
	}
}
