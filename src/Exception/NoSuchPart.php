<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use Exception;
use RuntimeException;

/**
 * The backup part file you asked me to retrieve does not exist on this backup record.
 *
 * @since  1.0.0
 */
class NoSuchPart extends RuntimeException
{
	public function __construct(int $code = 43, Exception $previous = null)
	{
		$message = 'The part number you specified does not exist in this backup record.';

		parent::__construct($message, $code, $previous);
	}

}
