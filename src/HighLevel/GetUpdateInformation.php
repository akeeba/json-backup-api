<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\CannotGetUpdateInformation;
use Akeeba\BackupJsonApi\Exception\LiveUpdateStuck;
use Akeeba\BackupJsonApi\Exception\LiveUpdateSupport;
use Akeeba\BackupJsonApi\Exception\NoUpdates;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Get information about the update status of the backup software
 *
 * @since  1.0.0
 */
class GetUpdateInformation
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(bool $force = false): object
	{
		$data = $this->httpClient->doQuery('updateGetInformation', array('force' => $force));

		if ($data->body->status != 200)
		{
			throw new CannotGetUpdateInformation();
		}

		// Is it supported?
		$updateInfo = $data->body->data;

		if ( !$updateInfo->supported)
		{
			throw new LiveUpdateSupport();
		}

		// Is it stuck?
		if ($updateInfo->stuck)
		{
			throw new LiveUpdateStuck($force ? '' : 'Try using the command line parameter --force=1');
		}

		// Do we have updates?
		if ( !$updateInfo->hasUpdates)
		{
			throw new NoUpdates();
		}

		return $updateInfo;
	}
}
