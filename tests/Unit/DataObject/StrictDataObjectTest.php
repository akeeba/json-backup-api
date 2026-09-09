<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\DataObject;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\DataObject\StrictDataObject;
use LogicException;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StrictDataObject::class)]
class StrictDataObjectTest extends UnitTestCase
{
	public function testKnownPropertiesAreReadableAndWritable(): void
	{
		$object = new StrictDataObject(['foo' => 'bar']);

		$object->foo = 'changed';

		$this->assertSame('changed', $object->foo);
	}

	public function testReadingAnUnknownPropertyThrows(): void
	{
		$object = new StrictDataObject(['foo' => 'bar']);

		$this->expectException(OutOfRangeException::class);
		$this->expectExceptionMessage('nosuchthing');

		/** @noinspection PhpExpressionResultUnusedInspection */
		$object->nosuchthing;
	}

	/**
	 * A property whose value is NULL still exists. Reading it must give NULL rather than throwing, or a legitimately
	 * empty value would be indistinguishable from a typo.
	 */
	public function testReadingAPropertyWhoseValueIsNullDoesNotThrow(): void
	{
		$this->assertNull((new StrictDataObject(['nothing' => null]))->nothing);
	}

	public function testWritingAnUnknownPropertyThrows(): void
	{
		$object = new StrictDataObject(['foo' => 'bar']);

		$this->expectException(OutOfRangeException::class);
		$this->expectExceptionMessage('nosuchthing');

		$object->nosuchthing = 'boo';
	}

	public function testUnsettingAnyPropertyThrows(): void
	{
		$object = new StrictDataObject(['foo' => 'bar']);

		$this->expectException(LogicException::class);

		unset($object->foo);
	}
}
