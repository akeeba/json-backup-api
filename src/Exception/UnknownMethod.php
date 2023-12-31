<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use JetBrains\PhpStorm\Pure;
use Throwable;

/**
 * The API method you tried to use is unknown.
 *
 * Unlike NotImplemented, this means that the method you used was never implemented, or at the very least the remote
 * server does not know anything about it.
 *
 * @since  1.0.0
 */
class UnknownMethod extends ApiException
{
	#[Pure]
	public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
	{
		$message = $message ?: 'The server replied that it does not know of the API method we requested. Is your installation broken?';

		parent::__construct($message, $code, $previous);
	}

}