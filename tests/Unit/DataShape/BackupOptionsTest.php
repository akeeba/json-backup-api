<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\DataShape;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BackupOptions::class)]
class BackupOptionsTest extends UnitTestCase
{
	public function testAppliesTheDefaults(): void
	{
		$options = new BackupOptions();

		$this->assertSame(1, $options->profile);
		$this->assertSame('Remote backup', $options->description);
		$this->assertSame('', $options->comment);
	}

	public function testKeepsWhatItWasGiven(): void
	{
		$options = new BackupOptions(
			[
				'profile'     => 5,
				'description' => 'Nightly',
				'comment'     => 'Taken by the scheduler',
			]
		);

		$this->assertSame(5, $options->profile);
		$this->assertSame('Nightly', $options->description);
		$this->assertSame('Taken by the scheduler', $options->comment);
	}

	public function testFillsInOnlyTheOptionsItWasNotGiven(): void
	{
		$options = new BackupOptions(['profile' => 5]);

		$this->assertSame(5, $options->profile);
		$this->assertSame('Remote backup', $options->description);
	}

	public function testIsImmutable(): void
	{
		$options = new BackupOptions();

		$this->expectException(LogicException::class);

		$options->profile = 2;
	}
}
