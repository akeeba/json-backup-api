<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\NoProfileData;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Import an exported backup profile
 *
 * @since  1.0.0
 */
class ImportConfiguration
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(string $jsonData): array
	{
		if (!$jsonData)
		{
			throw new NoProfileData();
		}

		$decodedData = json_decode($jsonData);

		$response = $this->httpClient->doQuery('importConfiguration', ['profile' => 0, 'data' => $decodedData]);

		return $response->body->data;
	}
}
