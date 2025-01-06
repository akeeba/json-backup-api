<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Exception;

use RuntimeException;
use Throwable;

/**
 * Could not delete a backup archive file on the remote server.
 *
 * @since  1.0.0
 */
class CannotDeleteFiles extends RuntimeException
{
	public function __construct(int $id, int $code = 106, ?Throwable $previous = null)
	{
		$message = sprintf("Cannot delete backup archive files for backup record %d. Please check if the files have not been already deleted either manually or automatically, e.g. after uploading to a remote location; or whether the backup was taken with an archiver engine which does not generate backup archives, such as DirectFTP.", $id);

		parent::__construct($message, $code, $previous);
	}

}
