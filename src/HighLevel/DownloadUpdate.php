<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\LiveUpdateDownloadError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Download the update package
 *
 * @since  1.0.0
 */
class DownloadUpdate
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): void
	{
		$data = $this->httpClient->doQuery('updateDownload', array());

		if ($data->body->status != 200)
		{
			throw new LiveUpdateDownloadError($data->body->data);
		}
	}

}