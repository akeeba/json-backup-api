<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\NoProfileID;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Export the configuration of a backup profile
 *
 * @since  1.0.0
 */
class ExportConfiguration
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(int $id = -1): object
	{
		if ($id <= 0)
		{
			throw new NoProfileID();
		}

		$data = $this->httpClient->doQuery('exportConfiguration', ['profile' => $id]);

		return $data->body->data;
	}
}
