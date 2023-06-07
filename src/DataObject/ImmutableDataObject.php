<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\DataObject;

/**
 * Access a number of arbitrarily named variables as if they are a standard object. The values are immutable.
 *
 * @since 1.0.0
 */
class ImmutableDataObject extends StrictDataObject
{
	/** @inheritDoc */
	public function __set(string $name, mixed $value): void
	{
		throw new \LogicException('You cannot set the properties of an immutable data object.');
	}

	/** @inheritDoc */
	public function __unset(string $name): void
	{
		throw new \LogicException('You cannot unset the properties of an immutable data object.');
	}

	/**
	 * Creates a new immutable data object, applying the new property values
	 *
	 * @param   array  $properties
	 *
	 * @return  static
	 * @since   1.0.0
	 */
	public function getModifiedClone(array $properties = []): static
	{
		return new static(array_replace_recursive($this->properties, $properties));
	}
}