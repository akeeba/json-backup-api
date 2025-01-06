<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotListProfiles;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Get a list of the backup profiles
 *
 * @since  1.0.0
 */
class GetProfiles
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): array
	{
		$data = $this->httpClient->doQuery('getProfiles');

		if ($data->body->status != 200)
		{
			throw new CannotListProfiles();
		}

		return $data->body->data;
	}
}
