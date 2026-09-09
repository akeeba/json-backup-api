<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Stub;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger which keeps everything it is told in memory, so tests can assert on it.
 *
 * @since 1.1.0
 */
class ArrayLogger extends AbstractLogger
{
	/**
	 * Everything logged so far, as ['level' => string, 'message' => string, 'context' => array].
	 *
	 * @var   array[]
	 * @since 1.1.0
	 */
	public array $records = [];

	/** @inheritDoc */
	public function log($level, Stringable|string $message, array $context = []): void
	{
		$this->records[] = [
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		];
	}

	/**
	 * Returns the messages logged at a given level, or at any level when none is given.
	 *
	 * @param   string|null  $level  The PSR-3 level to filter by
	 *
	 * @return  string[]
	 * @since   1.1.0
	 */
	public function messages(?string $level = null): array
	{
		return array_values(
			array_map(
				fn(array $record) => $record['message'],
				array_filter(
					$this->records,
					fn(array $record) => $level === null || $record['level'] === $level
				)
			)
		);
	}

	/**
	 * Does any message logged so far contain this substring?
	 *
	 * @param   string       $needle  The substring to look for
	 * @param   string|null  $level   The PSR-3 level to filter by
	 *
	 * @return  bool
	 * @since   1.1.0
	 */
	public function hasMessageContaining(string $needle, ?string $level = null): bool
	{
		foreach ($this->messages($level) as $message)
		{
			if (str_contains($message, $needle))
			{
				return true;
			}
		}

		return false;
	}
}
