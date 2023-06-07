<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Get information about a backup record
 *
 * @since  1.0.0
 */
class GetBackup
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(int $id = 0): object
	{
		$data = $this->httpClient->doQuery('getBackupInfo', ['backup_id' => $id]);

		if ($data->body->status != 200)
		{
			throw new NoSuchBackupRecord();
		}

		return $data->body->data;
	}


}