<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Unit;

use Koh\IndexNow\Key;
use Koh\IndexNow\Sylius\Http\KeyFileController;
use Koh\IndexNow\Sylius\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ActivationTest extends TestCase
{
    #[Test]
    public function onlyTheProdEnvironmentAnnounces(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');

        self::assertTrue($shop->gate('prod')->forHost('shop-domain.de')->active);
        foreach (['dev', 'test', 'staging'] as $environment) {
            $activation = $shop->gate($environment)->forHost('shop-domain.de');
            self::assertFalse($activation->active, $environment);
            self::assertStringContainsString('only prod', $activation->reason);
        }
    }

    #[Test]
    public function aDevelopmentChannelHostIsRefusedInProdToo(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'budde.dev.shop-domain.eu');

        self::assertStringContainsString('"dev"', $shop->gate()->forHost('budde.dev.shop-domain.eu')->reason);
    }

    #[Test]
    public function aHostWithoutAnEnabledChannelIsRefused(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de', enabled: false);

        self::assertFalse($shop->gate()->forHost('shop-domain.de')->active);
        self::assertFalse($shop->gate()->forHost('elsewhere.de')->active);
    }

    #[Test]
    public function configuredChannelsLimitWhatAnnounces(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');
        $shop->channel(2, 'CH', 'shop-domain.ch');

        $gate = $shop->gate(channelCodes: ['CH']);
        self::assertFalse($gate->forHost('shop-domain.de')->active);
        self::assertTrue($gate->forHost('shop-domain.ch')->active);
    }

    #[Test]
    public function everyHostGetsItsOwnKeyFromTheKernelSecret(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');
        $shop->channel(2, 'CH', 'shop-domain.ch');
        $gate = $shop->gate();

        $de = $gate->forHost('shop-domain.de')->key;
        self::assertNotNull($de);
        self::assertTrue($de->equals(Key::derive(Shop::SECRET, 'shop-domain.de')));
        self::assertFalse($de->equals($gate->forHost('shop-domain.ch')->key ?? $de));
    }

    #[Test]
    public function aShortKernelSecretDeactivatesRatherThanProducingAGuessableKey(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');

        self::assertFalse($shop->gate(secret: 'ThisTokenIsNotSoSecret')->forHost('shop-domain.de')->active);
    }

    #[Test]
    public function theKeyFileIsServedForTheExactKeyOnly(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');
        $key = Key::derive(Shop::SECRET, 'shop-domain.de')->value;
        $controller = new KeyFileController($shop->gate());

        $response = $controller(Request::create('https://shop-domain.de/' . $key . '.txt'), $key);
        self::assertSame($key, $response->getContent());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));

        $this->expectException(NotFoundHttpException::class);
        $controller(Request::create('https://shop-domain.de/abcdef123456.txt'), 'abcdef123456');
    }

    #[Test]
    public function aNonProdShopHasNoKeyFile(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');
        $key = Key::derive(Shop::SECRET, 'shop-domain.de')->value;

        $this->expectException(NotFoundHttpException::class);
        (new KeyFileController($shop->gate('dev')))(Request::create('https://shop-domain.de/' . $key . '.txt'), $key);
    }
}
