<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\DataObject;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\DataObject\DataObject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DataObject::class)]
class DataObjectTest extends UnitTestCase
{
	public function testReadsThePropertiesItWasConstructedWith(): void
	{
		$object = new DataObject(['foo' => 'bar', 'baz' => 42]);

		$this->assertSame('bar', $object->foo);
		$this->assertSame(42, $object->baz);
	}

	public function testAnUnknownPropertyReadsAsNull(): void
	{
		$this->assertNull((new DataObject(['foo' => 'bar']))->nosuchthing);
	}

	public function testPropertiesAreWritableAndNewOnesCanBeAdded(): void
	{
		$object = new DataObject(['foo' => 'bar']);

		$object->foo   = 'changed';
		$object->added = 'new';

		$this->assertSame('changed', $object->foo);
		$this->assertSame('new', $object->added);
		$this->assertCount(2, $object);
	}

	public function testIssetReportsOnlyPropertiesWhichAreSet(): void
	{
		$object = new DataObject(['set' => 'yes', 'null' => null]);

		$this->assertTrue(isset($object->set));
		$this->assertFalse(isset($object->nosuchthing));

		// isset() is false for a NULL value, exactly as it is for a real property.
		$this->assertFalse(isset($object->null));
	}

	public function testUnsetRemovesAProperty(): void
	{
		$object = new DataObject(['foo' => 'bar', 'baz' => 'bat']);

		unset($object->foo);

		$this->assertFalse(isset($object->foo));
		$this->assertCount(1, $object);
	}

	public function testUnsettingAnUnknownPropertyIsHarmless(): void
	{
		$object = new DataObject(['foo' => 'bar']);

		unset($object->nosuchthing);

		$this->assertCount(1, $object);
	}

	public function testIsCountable(): void
	{
		$this->assertCount(0, new DataObject());
		$this->assertCount(3, new DataObject(['a' => 1, 'b' => 2, 'c' => 3]));
	}

	public function testIteratesOverEveryProperty(): void
	{
		$object = new DataObject(['a' => 1, 'b' => 2, 'c' => 3]);

		$this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], iterator_to_array($object));
	}

	public function testSerialisesToJsonAsAPlainObject(): void
	{
		$object = new DataObject(['id' => 7, 'archive' => 'site-backup.jpa']);

		$this->assertSame('{"id":7,"archive":"site-backup.jpa"}', json_encode($object));
	}

	/**
	 * A property name starting with a null byte is how PHP mangles private and protected members inside an object's
	 * property table. Letting one in through the magic setter would let a caller write to what looks like a real,
	 * non-public property.
	 */
	public function testAPropertyNameStartingWithANullByteIsRejected(): void
	{
		$object = new DataObject();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Property names cannot start with a null byte.');

		$object->{"\0evil"} = 'boo';
	}
}
