<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotDeleteFiles;
use Akeeba\BackupJsonApi\Exception\NoBackupID;
use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Delete a backup record (and its archive files on the server)
 *
 * @since  1.0.0
 */
class Delete
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(int $id): void
	{
		if ($id <= 0)
		{
			throw new NoBackupID();
		}

		$data = $this->httpClient->doQuery('delete', [
			'backup_id' => $id
		]);

		if ($data->body->status == 404)
		{
			throw new NoSuchBackupRecord();
		}

		if ($data->body->status != 200)
		{
			throw new CannotDeleteFiles($id);
		}
	}
}
