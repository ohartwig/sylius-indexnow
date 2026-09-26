<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\IndexNow\Sylius\Http\KeyFileController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // At the origin root, outside Sylius' /{_locale} prefix: a locale
    // requirement never matches a segment containing a dot, so there is no
    // collision with shop routes.
    $routes->add('koh_indexnow_key_file', '/{key}.txt')
        ->requirements(['key' => '[A-Za-z0-9-]{8,128}'])
        ->controller(KeyFileController::class)
        ->methods(['GET', 'HEAD']);
};
