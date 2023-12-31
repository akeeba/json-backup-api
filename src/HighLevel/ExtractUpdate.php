<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\LiveUpdateExtractError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Extract the laready downloaded update package
 *
 * @since  1.0.0
 */
class ExtractUpdate
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): void
	{
		$data = $this->httpClient->doQuery('updateExtract', []);

		if ($data->body->status != 200)
		{
			throw new LiveUpdateExtractError($data->body->data);
		}
	}
}