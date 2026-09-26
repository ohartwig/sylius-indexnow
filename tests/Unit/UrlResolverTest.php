<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Unit;

use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    #[Test]
    public function aProductHasOneUrlPerChannelHostAndTranslatedLocale(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE', 'en_US']);
        $ch = $shop->channel(2, 'CH', 'shop-domain.ch', ['de_CH']);
        $shop->product(10, ['de_DE' => 'zigarre', 'en_US' => 'cigar'], [$de, $ch]);

        $urls = $shop->resolver($shop->gate(), includeMainTaxon: false)->resolve(Subject::product(10));

        // No de_CH translation: no page on the Swiss channel, and never the
        // fallback locale's slug under the wrong locale.
        self::assertSame([
            'https://shop-domain.de/de_DE/products/zigarre',
            'https://shop-domain.de/en_US/products/cigar',
        ], $urls);
    }

    #[Test]
    public function aProductOutsideAChannelHasNoPageThere(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de');
        $ch = $shop->channel(2, 'CH', 'shop-domain.ch', ['de_DE']);
        $shop->product(10, ['de_DE' => 'zigarre'], [$ch]);

        self::assertSame(['https://shop-domain.ch/de_DE/products/zigarre'], $shop->resolver($shop->gate(), false)->resolve(Subject::product(10)));
    }

    #[Test]
    public function aDisabledProductIsAnnouncedAsAChangeButNotAtGoLive(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $shop->product(10, ['de_DE' => 'ausverkauft'], [$de], enabled: false);
        $resolver = $shop->resolver($shop->gate(), false);

        self::assertSame(['https://shop-domain.de/de_DE/products/ausverkauft'], $resolver->resolve(Subject::product(10)));
        self::assertSame([], $resolver->resolve(Subject::product(10), strict: true));
    }

    #[Test]
    public function aChangedProductTakesItsMainTaxonListingAlong(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $taxon = $shop->taxon(5, ['de_DE' => 'zigarren/kuba']);
        $shop->product(10, ['de_DE' => 'cohiba'], [$de], mainTaxon: $taxon);

        self::assertSame([
            'https://shop-domain.de/de_DE/products/cohiba',
            'https://shop-domain.de/de_DE/taxons/zigarren/kuba',
        ], $shop->resolver($shop->gate())->resolve(Subject::product(10)));
    }

    #[Test]
    public function aTaxonOutsideTheChannelMenuIsNotShownThere(): void
    {
        $shop = new Shop();
        $menuRoot = $shop->taxon(1, ['de_DE' => 'kategorie'], null, 1, 10);
        $inside = $shop->taxon(2, ['de_DE' => 'kategorie/pfeifen'], $menuRoot, 2, 3);
        $otherTree = $shop->taxon(3, ['de_DE' => 'marken'], null, 1, 4);
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $de->setMenuTaxon($menuRoot);
        $resolver = $shop->resolver($shop->gate());

        self::assertSame(['https://shop-domain.de/de_DE/taxons/kategorie/pfeifen'], $resolver->resolve(Subject::taxon(2)));
        self::assertSame([], $resolver->resolve(Subject::taxon(3)));
        unset($inside, $otherTree);
    }

    #[Test]
    public function homepagesAreListedPerChannelAndLocale(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE', 'en_US']);

        self::assertSame(
            ['https://shop-domain.de/de_DE/', 'https://shop-domain.de/en_US/'],
            $shop->resolver($shop->gate())->resolve(Subject::homepage()),
        );
    }

    #[Test]
    public function nothingIsResolvedForHostsThatDoNotAnnounce(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop.dev.shop-domain.de', ['de_DE']);
        $shop->product(10, ['de_DE' => 'zigarre'], [$de]);

        self::assertSame([], $shop->resolver($shop->gate())->resolve(Subject::product(10)));
    }
}
