<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Stub;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A PSR-18 transport failure, as a client would throw when it cannot reach the server at all.
 *
 * @since 1.1.0
 */
class TransportFailure extends RuntimeException implements ClientExceptionInterface
{
}
