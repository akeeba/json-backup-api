<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;

/**
 * A generic exception telling users something went wrong with an API call
 *
 * @since 1.0.0
 */
abstract class ApiException extends RuntimeException
{

}
