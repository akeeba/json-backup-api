<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\DataObject;

use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use JsonSerializable;

/**
 * Access a number of arbitrarily named variables as if they are a standard object.
 *
 * The properties are read / write. You can unset existing properties, and set new ones.
 *
 * @since 1.0.0
 */
class DataObject implements IteratorAggregate, JsonSerializable, Countable
{
	/**
	 * Public constructor.
	 *
	 * @param   array  $properties  The property values to apply (associative array).
	 *
	 * @since   1.0.0
	 */
	public function __construct(protected array $properties = []) {}

	/**
	 * Magic getter.
	 *
	 * @param   string  $name  The name of the virtual property to return the value for.
	 *
	 * @return  mixed
	 * @since   1.0.0
	 */
	public function __get(string $name)
	{
		return $this->getProperty($name);
	}

	/**
	 * Magic setter.
	 *
	 * @param   string  $name   The name of the virtual property to set the value of.
	 * @param   mixed   $value  The value to set the virtual property to.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function __set(string $name, mixed $value): void
	{
		$this->setProperty($name, $value);
	}

	/**
	 * Handles isset() on magic properties.
	 *
	 * @param   string  $name  The name of the virtual property to check.
	 *
	 * @return  bool  TRUE if the property name exists
	 * @since   1.0.0
	 */
	public function __isset(string $name): bool
	{
		return isset($this->properties[$name]);
	}

	/**
	 * Handles unset() on magic properties.
	 *
	 * @param   string  $name  The name of the virtual property to unset.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function __unset(string $name): void
	{
		if (!isset($this->properties[$name]))
		{
			return;
		}

		unset($this->properties[$name]);
	}

	/**
	 * Returns an iterator for all virtual properties.
	 *
	 * @return  Iterator
	 * @since   1.0.0
	 */
	#[\ReturnTypeWillChange]
	public function getIterator(): Iterator
	{
		foreach ($this->properties as $k => $v)
		{
			yield $k => $v;
		}
	}

	/**
	 * Returns the number of known virtual properties.
	 *
	 * @return  int
	 * @since   1.0.0
	 */
	#[\ReturnTypeWillChange]
	public function count(): int
	{
		return count($this->properties);
	}

	/**
	 * Returns the data to serialise with json_encode.
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize(): array
	{
		return $this->properties;
	}

	/**
	 * Gets the value of a property, if it exists, or NULL.
	 *
	 * @param   string  $name  The name of the virtual property to get the name of.
	 *
	 * @return  mixed
	 */
	protected function getProperty(string $name): mixed
	{
		return $this->properties[$name] ?? null;
	}

	/**
	 * Sets the value of a property, if it exists, or defines a new one.
	 *
	 * @param   string  $name   The name of the virtual property to set.
	 * @param   mixed   $value  The value of the virtual property to set.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	protected function setProperty(string $name, mixed $value): void
	{
		if (str_starts_with($name, "\0"))
		{
			throw new InvalidArgumentException("Property names cannot start with a null byte.");
		}

		$this->properties[$name] = $value;
	}
}