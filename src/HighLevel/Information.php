<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Get information about the backup engine on the remote server
 *
 * @since  1.0.0
 */
class Information
{
	public function __construct(private HttpClientInterface $httpClient){}

	public function __invoke(): object
	{
		return $this->httpClient->doQuery('getVersion');
	}
}
