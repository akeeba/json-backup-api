<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\DataObject;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\DataObject\ImmutableDataObject;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ImmutableDataObject::class)]
class ImmutableDataObjectTest extends UnitTestCase
{
	public function testPropertiesAreReadable(): void
	{
		$this->assertSame('bar', (new ImmutableDataObject(['foo' => 'bar']))->foo);
	}

	public function testWritingAKnownPropertyThrows(): void
	{
		$object = new ImmutableDataObject(['foo' => 'bar']);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('immutable');

		$object->foo = 'changed';
	}

	public function testUnsettingAPropertyThrows(): void
	{
		$object = new ImmutableDataObject(['foo' => 'bar']);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('immutable');

		unset($object->foo);
	}

	public function testGetModifiedCloneAppliesTheOverridesToACopy(): void
	{
		$original = new ImmutableDataObject(['foo' => 'bar', 'baz' => 'bat']);
		$clone    = $original->getModifiedClone(['baz' => 'changed']);

		$this->assertNotSame($original, $clone);
		$this->assertSame('bar', $clone->foo);
		$this->assertSame('changed', $clone->baz);

		// The original is untouched. This is the whole point of the class.
		$this->assertSame('bat', $original->baz);
	}

	public function testGetModifiedCloneWithNoOverridesIsAPlainCopy(): void
	{
		$original = new ImmutableDataObject(['foo' => 'bar']);
		$clone    = $original->getModifiedClone();

		$this->assertNotSame($original, $clone);
		$this->assertSame('bar', $clone->foo);
	}

	public function testGetModifiedCloneCanAddPropertiesTheOriginalDidNotHave(): void
	{
		$clone = (new ImmutableDataObject(['foo' => 'bar']))->getModifiedClone(['added' => 'new']);

		$this->assertSame('new', $clone->added);
	}

	/**
	 * The overrides are applied with array_replace_recursive(), so an array-valued property is merged key by key
	 * rather than replaced wholesale. Autodetect leans on the clone-with-overrides mechanism heavily, so it is worth
	 * pinning down which of the two it does.
	 */
	public function testGetModifiedCloneMergesArrayValuedPropertiesRecursively(): void
	{
		$original = new ImmutableDataObject(['nested' => ['keep' => 'me', 'change' => 'this']]);
		$clone    = $original->getModifiedClone(['nested' => ['change' => 'that']]);

		$this->assertSame(['keep' => 'me', 'change' => 'that'], $clone->nested);
	}
}
