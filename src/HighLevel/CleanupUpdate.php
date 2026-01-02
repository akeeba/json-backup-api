<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\LiveUpdateCleanupError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Clean-up the download update package after updating the backup software
 *
 * @since  1.0.0
 */
class CleanupUpdate
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): void
	{
		$data = $this->httpClient->doQuery('updateCleanup', array());

		if ($data->body->status != 200)
		{
			throw new LiveUpdateCleanupError($data->body->data);
		}
	}
}