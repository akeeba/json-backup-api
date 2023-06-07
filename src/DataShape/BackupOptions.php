<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\DataShape;

use Akeeba\BackupJsonApi\DataObject\ImmutableDataObject;

/**
 * Options for running a backup
 *
 * @property  int     $profile      Backup profile number
 * @property  string  $description  Backup description
 * @property  string  $comment      Backup comment
 *
 * @since 1.0.0
 */
class BackupOptions extends ImmutableDataObject
{
	/** @inheritDoc */
	public function __construct($properties = [])
	{
		parent::__construct(array_merge(
			[
				'profile'     => 1,
				'description' => 'Remote backup',
				'comment'     => '',
			],
			$properties
		));
	}

}