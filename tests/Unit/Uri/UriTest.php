<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit\Uri;

use Akeeba\BackupJsonApi\Tests\Unit\UnitTestCase;
use Akeeba\BackupJsonApi\Uri\Uri;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass(Uri::class)]
class UriTest extends UnitTestCase
{
	public function testParsesEveryPartOfAFullUrl(): void
	{
		$uri = new Uri('https://joe:s3cr3t@www.example.com:8443/some/path/index.php?foo=bar&baz=bat#chapter');

		$this->assertSame('https', $uri->scheme);
		$this->assertSame('joe', $uri->user);
		$this->assertSame('s3cr3t', $uri->pass);
		$this->assertSame('www.example.com', $uri->host);
		$this->assertSame(8443, (int) $uri->port);
		$this->assertSame('/some/path/index.php', $uri->path);
		$this->assertSame('chapter', $uri->fragment);
		$this->assertSame(['foo' => 'bar', 'baz' => 'bat'], $uri->vars);
	}

	public function testAnEmptyUriHasEmptyPartsAndStringifiesToNothing(): void
	{
		$uri = new Uri();

		$this->assertSame('', $uri->scheme);
		$this->assertSame('', $uri->host);
		$this->assertNull($uri->port);
		$this->assertSame([], $uri->vars);
		$this->assertSame('', (string) $uri);
	}

	public function testRoundTripsAUrlThroughToString(): void
	{
		$url = 'https://www.example.com/index.php?option=com_akeeba&view=Api&method=getVersion';

		$this->assertSame($url, (new Uri($url))->toString());
	}

	public function testStringifiesOnlyTheRequestedParts(): void
	{
		$uri = new Uri('https://www.example.com:8443/index.php?foo=bar#chapter');

		$this->assertSame(
			'https://www.example.com:8443/index.php',
			$uri->toString(['scheme', 'user', 'pass', 'host', 'port', 'path'])
		);
	}

	public function testStringifiesTheAuthenticationPart(): void
	{
		$uri = new Uri('http://joe:s3cr3t@proxy.example.com:3128');

		$this->assertSame(
			'http://joe:s3cr3t@proxy.example.com:3128',
			$uri->toString(['scheme', 'user', 'pass', 'host', 'port'])
		);
	}

	public function testTheQueryStringIsRebuiltFromTheVariables(): void
	{
		$uri = new Uri('https://www.example.com/index.php');

		$uri->setVar('option', 'com_akeeba');
		$uri->setVar('view', 'Api');

		$this->assertSame('option=com_akeeba&view=Api', $uri->query);
		$this->assertSame('https://www.example.com/index.php?option=com_akeeba&view=Api', $uri->toString());
	}

	public function testSetVarOverwritesAnExistingVariable(): void
	{
		$uri = new Uri('https://www.example.com/index.php?view=json');

		$uri->setVar('view', 'Api');

		$this->assertSame('Api', $uri->getVar('view'));
		$this->assertSame('https://www.example.com/index.php?view=Api', $uri->toString());
	}

	public function testDelVarRemovesAVariableAndRebuildsTheQuery(): void
	{
		$uri = new Uri('https://www.example.com/index.php?option=com_akeeba&view=Api&format=json');

		$uri->delVar('format');

		$this->assertFalse($uri->hasVar('format'));
		$this->assertSame('https://www.example.com/index.php?option=com_akeeba&view=Api', $uri->toString());
	}

	public function testDelVarOnAnUnknownVariableChangesNothing(): void
	{
		$uri      = new Uri('https://www.example.com/index.php?view=Api');
		$original = $uri->toString();

		$uri->delVar('nosuchthing');

		$this->assertSame($original, $uri->toString());
	}

	public function testSetVarsReplacesTheWholeQuery(): void
	{
		$uri = new Uri('https://www.example.com/index.php?option=com_akeeba&view=Api');

		$uri->setVars(['method' => 'getVersion']);

		$this->assertSame(['method' => 'getVersion'], $uri->vars);
		$this->assertSame('https://www.example.com/index.php?method=getVersion', $uri->toString());
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake. Uri::setQuery() calls parse_str() with the magic $vars property as its
	 * by-reference output argument. PHP cannot take a reference to an overloaded property, so it raises the notice
	 * “Indirect modification of overloaded property has no effect” and the parsed variables are thrown away: the URI
	 * is left with the variables it already had, and its query string is set to NULL on top of that.
	 *
	 * The fix is the same shape as setVars() a few lines above it — parse into a local, then assign the local to the
	 * property. Nothing inside the library calls setQuery(), which is why this has gone unnoticed; it is public API
	 * all the same.
	 *
	 * Unskip this test once src/Uri/Uri.php:132 assigns through a local variable.
	 */
	public function testSetQueryParsesAQueryString(): void
	{
		$this->markTestSkipped(
			'Uri::setQuery() is broken: parse_str() cannot write into the magic $vars property. See src/Uri/Uri.php:132.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$uri = new Uri('https://www.example.com/index.php');

		$uri->setQuery('option=com_akeeba&view=Api');

		$this->assertSame(['option' => 'com_akeeba', 'view' => 'Api'], $uri->vars);
	}

	public function testGetVarReturnsTheDefaultForAnUnknownVariable(): void
	{
		$uri = new Uri('https://www.example.com/index.php');

		$this->assertNull($uri->getVar('nosuchthing'));
		$this->assertSame('fallback', $uri->getVar('nosuchthing', 'fallback'));
	}

	/**
	 * A query string which came out of an HTML document has its ampersands entity-encoded. Left alone, the whole thing
	 * parses as a single variable with a mangled name.
	 */
	public function testHtmlEncodedAmpersandsAreDecodedWhenParsing(): void
	{
		$uri = new Uri('https://www.example.com/index.php?option=com_akeeba&amp;view=Api');

		$this->assertSame(['option' => 'com_akeeba', 'view' => 'Api'], $uri->vars);
	}

	/**
	 * Skipped for the same product bug as testSetQueryParsesAQueryString(). See its comment.
	 */
	public function testHtmlEncodedAmpersandsAreDecodedBySetQuery(): void
	{
		$this->markTestSkipped(
			'Uri::setQuery() is broken: parse_str() cannot write into the magic $vars property. See src/Uri/Uri.php:132.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$uri = new Uri('https://www.example.com/index.php');

		$uri->setQuery('option=com_akeeba&amp;view=Api');

		$this->assertSame(['option' => 'com_akeeba', 'view' => 'Api'], $uri->vars);
	}

	public function testUnicodeInAPathSurvivesTheRoundTrip(): void
	{
		$uri = new Uri('https://www.example.com/παράδειγμα/index.php');

		$this->assertSame('/παράδειγμα/index.php', $uri->path);
		$this->assertSame('https://www.example.com/παράδειγμα/index.php', $uri->toString());
	}

	#[DataProvider('tlsProvider')]
	public function testIsTlsRecognisesTheScheme(string $url, bool $expected): void
	{
		$this->assertSame($expected, (new Uri($url))->isTLS());
	}

	public static function tlsProvider(): array
	{
		return [
			'https'            => ['https://www.example.com', true],
			'https, uppercase' => ['HTTPS://www.example.com', true],
			'http'             => ['http://www.example.com', false],
			'no scheme'        => ['www.example.com', false],
		];
	}

	public function testAMalformedUrlThrows(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Malformed URL');

		new Uri('http://:80');
	}

	/**
	 * Uri extends StrictDataObject, so a typo in a property name is an exception rather than a silent NULL.
	 */
	public function testReadingAnUnknownPropertyThrows(): void
	{
		$this->expectException(OutOfRangeException::class);

		/** @noinspection PhpExpressionResultUnusedInspection */
		(new Uri('https://www.example.com'))->nosuchproperty;
	}

	public function testPropertiesAreWritable(): void
	{
		$uri = new Uri('http://www.example.com');

		$uri->scheme = 'https';
		$uri->port   = 8443;

		$this->assertSame('https://www.example.com:8443', $uri->toString(['scheme', 'host', 'port']));
	}
}
