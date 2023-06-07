<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Uri;

use Akeeba\BackupJsonApi\DataObject\StrictDataObject;
use RuntimeException;

/**
 * A handy abstraction to PHP's parse_url, parse_str, and http_build_query
 *
 * @property string   $scheme   The scheme, e.g. 'https' or 'http'
 * @property string   $host     The hostname
 * @property int|null $port     The port number, if specified
 * @property string   $user     Basic authentication username
 * @property string   $pass     Basic authentication password
 * @property string   $path     URI path, without query
 * @property string   $query    The query, as a text string e.g. foo=bar&baz=bat
 * @property array    $vars     The query, as individual vars, e.g. ['foo'=>'bar', 'baz'=>'bat']
 * @property string   $fragment The fragment (everything after the #).
 */
class Uri extends StrictDataObject
{
	public function __construct(protected ?string $uri = null)
	{
		$params = [
			'scheme'   => '',
			'host'     => '',
			'port'     => null,
			'user'     => '',
			'pass'     => '',
			'path'     => '',
			'query'    => '',
			'fragment' => '',
			'vars'     => [],
		];

		if ($this->uri !== null)
		{
			$params = array_merge($params, $this->parse($this->uri));
		}

		parent::__construct($params);
	}

	#[\ReturnTypeWillChange]
	public function __toString()
	{
		return $this->toString();
	}

	public function toString(
		array $parts = ['scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment']
	): string
	{
		$this->query ??= $this->buildQuery();

		$uri = '';
		$uri .= in_array('scheme', $parts, true) ? (!empty($this->scheme) ? $this->scheme . '://' : '') : '';
		$uri .= in_array('user', $parts, true) ? $this->user : '';
		$uri .= in_array('pass', $parts, true) ? (!empty($this->pass) ? ':' : '') . $this->pass . (!empty($this->user) ? '@' : '') : '';
		$uri .= in_array('host', $parts, true) ? $this->host : '';
		$uri .= in_array('port', $parts, true) ? (!empty($this->port) ? ':' : '') . $this->port : '';
		$uri .= in_array('path', $parts, true) ? $this->path : '';
		$uri .= in_array('query', $parts, true) ? (!empty($query) ? '?' . $query : '') : '';
		$uri .= in_array('fragment', $parts, true) ? (!empty($this->fragment) ? '#' . $this->fragment : '') : '';

		return $uri;
	}

	public function __get(string $name)
	{
		if ($name === 'query')
		{
			$this->properties['query'] ??= $this->buildQuery();
		}

		return parent::__get($name);
	}

	public function hasVar(string $name): bool
	{
		return array_key_exists($name, $this->vars);
	}

	public function getVar(string $name, mixed $default = null)
	{
		return $this->vars[$name] ?? $default;
	}

	public function setVar(string $name, mixed $value): void
	{
		$this->vars[$name] = $value;
		$this->query       = null;
	}

	public function delVar(string $name): void
	{
		if (!$this->hasVar($name))
		{
			return;
		}

		unset($this->vars[$name]);

		$this->query = null;
	}

	public function setVars(array $vars): void
	{
		$this->vars  = $vars;
		$this->query = null;
	}

	public function setQuery(string $query): void
	{
		if (str_contains($query, '&amp;'))
		{
			$query = str_replace('&amp;', '&', $query);
		}

		parse_str($query, $this->vars);
		$this->query = null;
	}

	public function isTLS(): bool
	{
		return strtolower($this->scheme) === 'https';
	}

	protected function buildQuery()
	{
		return http_build_query($this->vars, '', '&');
	}

	protected function parse(string $uri): array
	{
		static $hasUnicode = null;

		if ($hasUnicode === null)
		{
			$hasUnicode = @preg_match('/\p{L}/u', 'σ') === 1;
		}

		$this->uri = $uri;

		// If PHP is compiled with Unicode–compatible Regular Expressions, prefer it.
		if ($hasUnicode)
		{
			$encodedURL = preg_replace_callback(
				'%[^!*\'();:/@?&=#$,\\[\\]]+%u',
				fn($matches) => urlencode($matches[0]),
				$uri
			);
		}
		// Otherwise, fall back to a simple urlencode and use strtr() to decode select characters
		else
		{
			$encodedURL = strtr(
				urlencode($uri),
				[
					'%21' => '!',
					'%2A' => '*',
					'%27' => "'",
					'%28' => '(',
					'%29' => ')',
					'%3B' => ';',
					'%3A' => ':',
					'%40' => '@',
					'%26' => '&',
					'%3D' => '=',
					'%24' => '$',
					'%2C' => ',',
					'%2F' => '/',
					'%3F' => '?',
					'%23' => '#',
					'%5B' => '[',
					'%5D' => ']',
				]
			);
		}

		// Parse the URL
		$parts = parse_url($encodedURL);

		// Malformed URL. Okay, go away!
		if ($parts === false)
		{
			throw new RuntimeException('Malformed URL');
		}

		// Remember, all parts are still URL-encoded. Let's decode them before returning them.
		$parts = array_map('urldecode', $parts);

		if (str_contains($parts['query'] ?? '', '&amp;'))
		{
			$parts['query'] = str_replace('&amp;', '&', $parts['query']);
		}

		foreach ($parts as $k => $v)
		{
			$this->$k = $v;
		}

		parse_str($parts['query'] ?? '', $this->vars);

		return $parts;
	}
}
