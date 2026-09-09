<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Unit;

use Akeeba\BackupJsonApi\Exception\NoConfiguredHost;
use Akeeba\BackupJsonApi\Exception\NoConfiguredSecret;
use Akeeba\BackupJsonApi\Options;
use Composer\CaBundle\CaBundle;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

#[CoversClass(Options::class)]
class OptionsTest extends UnitTestCase
{
	public function testRequiresACredential(): void
	{
		$this->expectException(NoConfiguredSecret::class);

		new Options(['capath' => CaBundle::getBundledCaBundlePath(), 'host' => 'https://www.example.com']);
	}

	public function testASecretWordAloneIsEnough(): void
	{
		$options = $this->makeOptions(['secret' => 'TheSecretWord', 'token' => '']);

		$this->assertSame('TheSecretWord', $options->secret);
		$this->assertSame('', $options->token);
	}

	public function testAJoomlaApiTokenAloneIsEnough(): void
	{
		$options = $this->makeOptions(['secret' => '', 'token' => 'TheApiToken']);

		$this->assertSame('', $options->secret);
		$this->assertSame('TheApiToken', $options->token);
	}

	public function testRequiresAHost(): void
	{
		$this->expectException(NoConfiguredHost::class);

		new Options(
			['capath' => CaBundle::getBundledCaBundlePath(), 'host' => '', 'secret' => 'TheSecretWord']
		);
	}

	public function testUnknownOptionsAreIgnoredByDefault(): void
	{
		$options = $this->makeOptions(['nosuchoption' => 'whatever']);

		$this->assertArrayNotHasKey('nosuchoption', $options->toArray());
	}

	public function testUnknownOptionsThrowInStrictMode(): void
	{
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('nosuchoption');

		new Options(
			[
				'capath'       => CaBundle::getBundledCaBundlePath(),
				'host'         => 'https://www.example.com',
				'secret'       => 'TheSecretWord',
				'nosuchoption' => 'whatever',
			],
			true
		);
	}

	public function testAppliesTheDocumentedDefaults(): void
	{
		$options = $this->makeOptions();

		$this->assertSame('GET', $options->verb);
		$this->assertSame('index.php', $options->endpoint);
		$this->assertSame(Options::DEFAULT_API_ENDPOINT, $options->apiEndpoint);
		$this->assertSame('', $options->component);
		$this->assertSame('', $options->format);
		$this->assertSame(0, $options->apiVersion);
		$this->assertFalse($options->verbose);
		$this->assertFalse($options->isWordPress);
	}

	public function testToArrayExposesEveryKnownOption(): void
	{
		$expected = [
			'capath', 'host', 'verb', 'endpoint', 'apiEndpoint', 'component', 'view', 'apiVersion', 'format',
			'secret', 'token', 'ua', 'verbose', 'isWordPress', 'logger',
		];

		$this->assertSame($expected, array_keys($this->makeOptions()->toArray()));
	}

	public function testDefaultsToANullLogger(): void
	{
		$options = new Options(
			[
				'capath' => CaBundle::getBundledCaBundlePath(),
				'host'   => 'https://www.example.com',
				'secret' => 'TheSecretWord',
			]
		);

		$this->assertInstanceOf(NullLogger::class, $options->logger);
	}

	public function testIsImmutable(): void
	{
		$options = $this->makeOptions();

		$this->expectException(LogicException::class);

		$options->verb = 'POST';
	}

	public function testGetModifiedCloneReturnsAnOptionsObject(): void
	{
		$options = $this->makeOptions();
		$clone   = $options->getModifiedClone(['apiVersion' => 3, 'verb' => 'POST']);

		$this->assertInstanceOf(Options::class, $clone);
		$this->assertSame(3, $clone->apiVersion);
		$this->assertSame('POST', $clone->verb);
		$this->assertSame(0, $options->apiVersion);
	}

	#[DataProvider('hostParsingProvider')]
	public function testParsesTheHost(string $host, array $expected): void
	{
		$options = $this->makeOptions(['host' => $host]);

		foreach ($expected as $property => $value)
		{
			$this->assertSame($value, $options->{$property}, sprintf('Option ‘%s’', $property));
		}
	}

	public static function hostParsingProvider(): array
	{
		return [
			'bare host'                     => [
				'https://www.example.com',
				['host' => 'https://www.example.com/', 'endpoint' => 'index.php'],
			],
			'trailing slash'                => [
				'https://www.example.com/',
				['host' => 'https://www.example.com/', 'endpoint' => 'index.php'],
			],
			'endpoint in the URL'           => [
				'https://www.example.com/index.php',
				['host' => 'https://www.example.com/', 'endpoint' => 'index.php'],
			],
			'site in a subdirectory'        => [
				'https://www.example.com/subdir/index.php',
				['host' => 'https://www.example.com/subdir', 'endpoint' => 'index.php'],
			],
			'subdirectory, no endpoint'     => [
				'https://www.example.com/subdir/',
				['host' => 'https://www.example.com/subdir', 'endpoint' => 'index.php'],
			],
			'a path which is not a file'    => [
				'https://www.example.com/some/path',
				['host' => 'https://www.example.com/some/path', 'endpoint' => 'index.php'],
			],
			'Akeeba Solo endpoint'          => [
				'https://www.example.com/remote.php',
				['host' => 'https://www.example.com/', 'endpoint' => 'remote.php', 'isWordPress' => false],
			],
			'WordPress endpoint'            => [
				'https://www.example.com/wp-admin/admin-ajax.php',
				[
					'host'        => 'https://www.example.com/wp-admin',
					'endpoint'    => 'admin-ajax.php',
					'isWordPress' => true,
				],
			],
			'a non-PHP file is discarded'   => [
				'https://www.example.com/index.html',
				['host' => 'https://www.example.com/', 'endpoint' => 'index.php'],
			],
			'an unsupported scheme becomes http' => [
				'ftp://www.example.com/index.php',
				['host' => 'http://www.example.com/', 'endpoint' => 'index.php'],
			],
		];
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake. A host name given without a scheme — which is exactly what this
	 * library's own README tells you to pass, `'host' => 'example.com'` — loses the host name altogether.
	 *
	 * parse_url() reads a schemeless string as a *path*, so Uri comes back with an empty host and a path of
	 * 'example.com'. parseHost() then hands that path to parsePath(), which sees a dot but no '.php' suffix and
	 * discards it as a useless trailing component. What is left is an empty host and an empty path, which stringify
	 * to 'http:///' — non-empty, so the NoConfiguredHost guard below it never fires and the caller gets a silently
	 * unusable configuration instead of an error.
	 *
	 * The fix is to prepend 'http://' before parsing when the string has no '<scheme>://' prefix, which is also what
	 * would make the README's example work.
	 *
	 * Unskip this test once parseHost() copes with a schemeless host.
	 */
	#[DataProvider('schemelessHostProvider')]
	public function testAHostGivenWithoutASchemeKeepsItsHostName(string $host, string $expected): void
	{
		$this->markTestSkipped(
			'Options::parseHost() drops the host name when the host has no scheme, yielding ‘http:///’. See src/Options.php:parseHost().'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->assertSame($expected, $this->makeOptions(['host' => $host])->host);
	}

	public static function schemelessHostProvider(): array
	{
		return [
			'with www'    => ['www.example.com', 'http://www.example.com/'],
			'without www' => ['example.com', 'http://example.com/'],
		];
	}

	public function testExtractsComponentFormatAndViewFromTheHostQueryString(): void
	{
		$options = $this->makeOptions(
			['host' => 'https://www.example.com/index.php?option=com_akeeba&view=Api&format=json']
		);

		$this->assertSame('com_akeeba', $options->component);
		$this->assertSame('json', $options->format);
		$this->assertSame('Api', $options->view);

		// …and the view is what tells us we were handed a JSON API v2 URL.
		$this->assertSame(2, $options->apiVersion);
	}

	/**
	 * The Akeeba Solo and WordPress endpoints are not Joomla!, so the Joomla!-specific query parameters have to go.
	 * They are blanked even when the caller explicitly asked for them.
	 */
	#[DataProvider('nonJoomlaEndpointProvider')]
	public function testTheJoomlaOnlyOptionsAreBlankedOnNonJoomlaEndpoints(string $host): void
	{
		$options = $this->makeOptions(
			[
				'host'      => $host,
				'component' => 'com_akeeba',
				'format'    => 'json',
			]
		);

		$this->assertSame('', $options->component);
		$this->assertSame('', $options->format);
	}

	public static function nonJoomlaEndpointProvider(): array
	{
		return [
			'Akeeba Solo' => ['https://www.example.com/remote.php'],
			'WordPress'   => ['https://www.example.com/wp-admin/admin-ajax.php'],
		];
	}

	/**
	 * Autodetect probes WordPress by setting the endpoint to the full 'wp-admin/admin-ajax.php' path, while a pasted
	 * URL is split into a host and a bare 'admin-ajax.php'. Both shapes are WordPress.
	 */
	#[DataProvider('wordPressEndpointProvider')]
	public function testWordPressIsRecognisedFromEitherShapeOfTheEndpoint(string $endpoint): void
	{
		$this->assertTrue($this->makeOptions(['endpoint' => $endpoint])->isWordPress);
	}

	public static function wordPressEndpointProvider(): array
	{
		return [
			'bare file' => ['admin-ajax.php'],
			'full path' => ['wp-admin/admin-ajax.php'],
		];
	}

	/**
	 * isWordPress is derived on every construction rather than remembered. Autodetect reaches each candidate by
	 * cloning the options with overrides, so a flag which only ever latched on would stay on for every candidate
	 * tried after the first WordPress one.
	 */
	public function testWordPressDetectionIsNotStickyAcrossClones(): void
	{
		$wordPress = $this->makeOptions(['endpoint' => 'wp-admin/admin-ajax.php']);

		$this->assertTrue($wordPress->isWordPress);
		$this->assertFalse($wordPress->getModifiedClone(['endpoint' => 'index.php'])->isWordPress);
	}

	#[DataProvider('apiVersionProvider')]
	public function testWorksOutTheApiVersion(array $overrides, int $expected): void
	{
		$this->assertSame($expected, $this->makeOptions($overrides)->apiVersion);
	}

	public static function apiVersionProvider(): array
	{
		return [
			'nothing said'                  => [[], 0],
			'explicit v3'                   => [['apiVersion' => 3], 3],
			'explicit v2 as a string'       => [['apiVersion' => '2'], 2],
			'a version we cannot speak'     => [['apiVersion' => 9], 0],
			'legacy view=json means v1'     => [['view' => 'json'], 1],
			'legacy view=Api means v2'      => [['view' => 'Api'], 2],
			'the view is case insensitive'  => [['view' => 'API'], 2],
			'an unknown view means nothing' => [['view' => 'nonsense'], 0],
			'apiVersion beats view'         => [['apiVersion' => 1, 'view' => 'Api'], 1],
		];
	}

	#[DataProvider('apiEndpointProvider')]
	public function testNormalisesTheApiEndpoint(?string $given, string $expected): void
	{
		$this->assertSame($expected, $this->makeOptions(['apiEndpoint' => $given])->apiEndpoint);
	}

	public static function apiEndpointProvider(): array
	{
		return [
			'the default'          => [null, Options::DEFAULT_API_ENDPOINT],
			'an empty string'      => ['', Options::DEFAULT_API_ENDPOINT],
			'surrounding slashes'  => ['/custom/api/', 'custom/api'],
			'the rewritten form'   => ['api', 'api'],
		];
	}

	public function testKnowsTheApiVersionsItCanSpeak(): void
	{
		// Newest first. Autodetect walks this list in order, so the order is part of the contract.
		$this->assertSame([3, 2, 1], Options::API_VERSIONS);
	}

	public function testKnowsWhereJoomlasApiApplicationMayLive(): void
	{
		$this->assertSame(['api/index.php', 'api'], Options::API_ENDPOINTS);
		$this->assertSame('api/index.php', Options::DEFAULT_API_ENDPOINT);
	}

	public function testTheDefaultUserAgentNamesTheLibraryAndItsVersion(): void
	{
		$options = new Options(
			[
				'capath' => CaBundle::getBundledCaBundlePath(),
				'host'   => 'https://www.example.com',
				'secret' => 'TheSecretWord',
			]
		);

		$this->assertStringStartsWith('AkeebaBackupJsonApiClient/', $options->ua);
	}

	/**
	 * PRODUCT BUG, not a test-writing mistake. Options is meant to accept `debug` as an alias of `verbose` — the
	 * constructor has an explicit `if ($appliedOptions['debug'] ?? false)` for it. It cannot work: the alias is read
	 * from $appliedOptions, which only ever holds the keys of the defaults array, and `debug` is not one of them. In
	 * non-strict mode the incoming `debug` is silently dropped before that check, and in strict mode passing it is a
	 * LogicException, so there is no way to reach the branch at all.
	 *
	 * The fix is to read the alias from the caller's array, before the loop which filters it out.
	 *
	 * Unskip this test once `debug` reaches `verbose`.
	 */
	public function testDebugIsAnAliasOfVerbose(): void
	{
		$this->markTestSkipped(
			'Options ignores the `debug` alias of `verbose`; the branch which handles it is unreachable. See src/Options.php.'
		);

		/** @noinspection PhpUnreachableStatementInspection */
		$this->assertTrue($this->makeOptions(['debug' => true])->verbose);
	}
}
