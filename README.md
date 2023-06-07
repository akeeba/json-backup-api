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
    'secret' => 'Sυρ3rC4l1Fr@gil15ti(E><pial!d0ciou5',
]);
// Create an HTTP client object. Here, we are using one that makes use of Guzzle 7 (you need to install Guzzle yourself)
$httpClient = new \Akeeba\BackupJsonApi\HttpAbstraction\HttpClientGuzzle($options);
// Get the API client itself. 
$apiClient = new \Akeeba\BackupJsonApi\Connector($httpClient);
```

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
Copyright (C) 2008-2023  Nicholas K. Dionysopoulos / Akeeba Ltd

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

## Alternative licensing

If you would like to use this Software, but the GNU Affero General Public License (especially Article 13 of the license)
is problematic for your use case please [contact us](https://www.akeeba.com/contact-us.html) to purchase an alternative,
proprietary software license for the Software.