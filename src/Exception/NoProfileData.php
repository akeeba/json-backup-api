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
 * You asked me to import some backup profile data, but no profile data was provided.
 *
 * @since  1.0.0
 */
class NoProfileData extends RuntimeException
{
	public function __construct(int $code = 40, Exception $previous = null)
	{
		$message = 'You must supply the profile data that should be imported';

		parent::__construct($message, $code, $previous);
	}

}
