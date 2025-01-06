<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\LiveUpdateInstallError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Install the update package on the remote server
 *
 * @since  1.0.0
 */
class InstallUpdate
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): void
	{
		$data = $this->httpClient->doQuery('updateInstall', array());

		if ($data->body->status != 200)
		{
			throw new LiveUpdateInstallError($data->body->data);
		}
	}
}