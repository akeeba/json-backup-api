<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\DataObject;

/**
 * Access a number of arbitrarily named variables as if they are a standard object.
 *
 * The properties are read / write. You can NOT unset existing properties, or set new ones.
 *
 * @since 1.0.0
 */
class StrictDataObject extends DataObject
{
	/** @inheritDoc */
	public function __get(string $name)
	{
		if (!array_key_exists($name, $this->properties))
		{
			throw new \OutOfRangeException(sprintf('Property “%s” does not exist', $name));
		}

		return parent::__get($name);
	}

	/** @inheritDoc */
	public function __set(string $name, mixed $value): void
	{
		if (!array_key_exists($name, $this->properties))
		{
			throw new \OutOfRangeException(sprintf('Property “%s” does not exist', $name));
		}

		parent::__set($name, $value);
	}

	/** @inheritDoc */
	public function __unset(string $name): void
	{
		throw new \LogicException('You cannot unset the properties of a strict data object.');
	}

}