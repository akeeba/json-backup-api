# Akeeba Backup JSON API Client Library

A PHP client library for talking to the Akeeba Backup and Akeeba Solo JSON API.

This library works with:
* Akeeba Backup for Joomla! 4.7.7 and later
* Akeeba Backup for WordPress 2.0.0 and later
* Akeeba Solo 2.0.0 and later

## Quick Start

### Getting an API client object

```php
// Create an Options object which tells the library where and how to connect to the backup software
$options = new \Akeeba\BackupJsonApi\Options([
    'capath' => \Composer\CaBundle\CaBundle::getBundledCaBundlePath(),
    'ua'     => 'MyFancyApp/1.2.3',
    'host'   => 'example.com',
    'token'  => 'c2hhMjU2OjcwOjgwMzE3NzRiYWI1YTY0MGY4NWQ2MTI3NjY1YmZiMGY2',
]);
// Create an HTTP client object. Here, we are using one that makes use of Guzzle 7 (you need to install Guzzle yourself)
$httpClient = new \Akeeba\BackupJsonApi\HttpAbstraction\HttpClientGuzzle($options);
// Get the API client itself. 
$apiClient = new \Akeeba\BackupJsonApi\Connector($httpClient);
// Let the library work out which API version and connection settings your site supports
$apiClient->autodetect();
```

### Authentication

There are two credentials, and which ones are accepted depends on the API version:

| Option   | Credential                          | v1  | v2  | v3  |
|----------|-------------------------------------|-----|-----|-----|
| `token`  | Joomla! API token (**recommended**) | no  | no  | yes |
| `secret` | Akeeba Backup JSON API Secret Word  | yes | yes | yes |

Provide at least one of the two; providing neither throws `Exception\NoConfiguredSecret`.

Prefer the **Joomla! API token**. It identifies a Joomla! user account, and Akeeba Backup 10.4.0 and later enforce that
account's privileges per API method — so you can give an integration a least-privilege account which may take backups
but not download or delete them. The Secret Word, by contrast, is an unscoped grant over the entire component, and
[it is deprecated](https://www.akeeba.com/news/1790-deprecation-of-the-akeeba-backup-json-api-v2.html) along with the
v2 API.

The token is per user account. Find it in your site's backend under User Menu, Edit Account, Joomla! API Token. It
needs the “API Authentication - Web Services Joomla Token” plugin enabled, and the account needs the API Login
privilege.

On the v3 API both credentials travel as request headers (`X-Joomla-Token` and `X-Akeeba-Auth` respectively), so
neither ends up in a URL — and therefore neither ends up in your server's access log. The v2 API has no such option:
the Secret Word is always a query string parameter.

If you have only one credential field to offer your users, put whatever they give you in `secret` and call
`autodetect()`. It tries that value as a Joomla! API token first and as a Secret Word second, so a user who pastes a
token where a Secret Word used to go does not have to be told the difference.

### Choosing an API version

Set `apiVersion` to `1`, `2`, or `3` to pin a version. Leave it unset — the default — and `autodetect()` will try every
version, newest first, along with every other connection setting it varies, and keep the first combination which works.
Autodetect is the recommended path: it is the only way to find the right settings for an arbitrary site, and its result
is worth caching so you only pay for it once.

| Version | Endpoint                                       | Notes                                           |
|---------|------------------------------------------------|-------------------------------------------------|
| 3       | `<site>/api/index.php/v3/akeebabackup/<method>`| Joomla! only, Akeeba Backup 9.6.0+. Preferred.  |
| 2       | `<site>/index.php?view=Api&method=<method>`    | Deprecated; removal planned for October 2027.   |
| 1       | `<site>/index.php?view=json&json=<payload>`    | Deprecated since December 2019; long retired.   |

The v3 API is a route in Joomla's API application, so it does not exist on Akeeba Solo or Akeeba Backup for WordPress;
autodetect will not waste requests asking those for it. The `apiEndpoint` option sets the path to Joomla's API
application, defaulting to `api/index.php`; autodetect also tries the rewritten `api` form.

Note that a v3 API token which authenticates but lacks the privilege for the method you called throws
`Exception\NotAuthorised`, which is distinct from the `Exception\InvalidSecretWord` you get when the credential itself
is rejected.

### Redirects

Redirects are followed, but only within the domain you gave us. All three bundled HTTP clients apply the same rule.

Following them is not optional: a Joomla! site will redirect an API URL for entirely mundane reasons — adding the
language prefix its SEF configuration calls for, moving between www and non-www, upgrading HTTP to HTTPS.

Following them blindly is not acceptable either. Every request carries a credential, in a header on v3 and in the query
string on v2, and HTTP clients forward those along a redirect chain. A redirect to somebody else's host would hand them
your Secret Word or your API token — which is what an open redirect on your site, or a hostile one, would exploit. So
each hop is checked, and a redirect which leaves your domain raises `Exception\UnsafeRedirect` *before* the request is
made.

Given a host of `www.example.com`, these are followed:

* `www.example.com/en/index.php` — the same host
* `example.com` — dropping the `www.`
* `foobar.example.com` — a sibling subdomain
* `https://www.example.com` from `http://www.example.com` — an upgrade to TLS

and these are refused: `evil.net`, `123.1.2.3`, `notexample.com`, `www.example.com.evil.net`, and a *downgrade* from
HTTPS to plaintext HTTP. The comparison is made on label boundaries and is anchored on your host with any leading
`www.` removed, so with a host of `www.example.co.uk` a redirect to `evil.co.uk` is refused even though the two share
the `co.uk` suffix.

One case fails closed: a redirect *sideways* from a host which is neither your anchor nor beneath it —
`api.example.com` to `shop.example.com`, say. Recognising those as related needs a public suffix list, and this library
is deliberately free of that dependency, so it refuses them. Redirects *up* to a parent (`api.example.com` to
`example.com`) are followed.

### Taking a backup (and tracking its progress)

```php
$backupOptions = new \Akeeba\BackupJsonApi\DataShape\BackupOptions([
    'profile' => 5,
    'description' => 'Remote backup using the API client',
    'comment' => 'Look, mum! I can take backups without logging into the site!'
]);
$apiClient->backup($backupOptions, function ($data) {
    echo "Received backup tick\n";
    echo sprintf("Domain   : %s\n", $data->Domain);
    echo sprintf("Step     : %s\n", $data->Step);
    echo sprintf("Substep  : %s\n", $data->Substep);
    echo sprintf("Progress : %0.2f%%\n", $data->Progress);

    if (!empty($data->Warnings))
    {
        echo "Warnings\n========\n";

        foreach ($data->Warnings as $warning)
        {
            echo $warning . "\n";
        }
    }

    if (!$data->HasRun && empty($data->Error))
    {
        echo "The backup finished successfully.\n";
    }
    elseif (!empty($data->Error))
    {
        echo "The backup finished with an error:\n{$data->Error}\n";
    }
});
```

## License

Akeeba Backup JSON API Client Library — A PHP client library for talking to the Akeeba Backup and Akeeba Solo JSON API.
Copyright (C) 2008-2026  Nicholas K. Dionysopoulos / Akeeba Ltd

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.

## Regulatory status (EU Cyber Resilience Act)

Akeeba Kickstart is not monetised and is not placed on the market within the meaning of Regulation (EU) 2024/2847.