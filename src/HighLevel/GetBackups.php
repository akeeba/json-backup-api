<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotListBackupRecords;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Get a list of the backup records
 *
 * @since  1.0.0
 */
class GetBackups
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(int $from = 0, $limit = 200): array
	{
		// from in [200, ∞), limit in [1, 200]
		$from = max(0, $from);
		$limit = min(max(1, $limit), 200);

		$data = $this->httpClient->doQuery('listBackups', [
			'from'  => $from,
			'limit' => $limit,
		]);

		if ($data->body->status != 200)
		{
			throw new CannotListBackupRecords();
		}

		return $data->body->data ?: [];
	}
}