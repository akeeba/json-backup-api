<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;

/**
 * The raw request does not contain valid JSON data.
 *
 * This happens with both v1 and v2 API. It means that the server threw an error, or something went phenomenally wrong
 * in the network.
 *
 * @since  1.0.0
 */
class InvalidEncapsulatedJSON extends ApiException
{
	public function __construct(string $type, int $code = 23, Exception $previous = null)
	{
		$message = sprintf('Invalid JSON data returned from the server: ‘%s’.', $type);

		parent::__construct($message, $code, $previous);
	}
}
