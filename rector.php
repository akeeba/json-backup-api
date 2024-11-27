<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;

return function (RectorConfig $rectorConfig) {
    $rectorConfig->paths([
        __DIR__ . '/src',
    ]);

    $rectorConfig->phpVersion(Rector\ValueObject\PhpVersion::PHP_84);

    $rectorConfig->rules([
        Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
    ]);
};