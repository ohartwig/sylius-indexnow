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

    #[Test]
    public function theMenuRootItselfBelongsToTheMenu(): void
    {
        $shop = new Shop();
        $root = $shop->taxon(1, ['de_DE' => 'kategorie'], null, 1, 10);
        $shop->taxon(2, ['de_DE' => 'kategorie/letzte'], $root, 8, 9);
        $shop->taxon(3, ['de_DE' => 'kategorie/ausserhalb'], $root, 11, 12);
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $de->setMenuTaxon($root);
        $resolver = $shop->resolver($shop->gate());

        self::assertSame(['https://shop-domain.de/de_DE/taxons/kategorie'], $resolver->resolve(Subject::taxon(1)));
        self::assertSame(['https://shop-domain.de/de_DE/taxons/kategorie/letzte'], $resolver->resolve(Subject::taxon(2)), 'the right edge is inclusive');
        self::assertSame([], $resolver->resolve(Subject::taxon(3)), 'outside the bounds');
    }

    #[Test]
    public function aDisabledMainTaxonIsNotDraggedAlong(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $shop->product(10, ['de_DE' => 'cohiba'], [$de], mainTaxon: $shop->taxon(5, ['de_DE' => 'neu'], enabled: false));

        self::assertSame(['https://shop-domain.de/de_DE/products/cohiba'], $shop->resolver($shop->gate())->resolve(Subject::product(10)));
    }

    #[Test]
    public function aDisabledTaxonIsAnnouncedAsAChangeButNotAtGoLive(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $shop->taxon(5, ['de_DE' => 'alt'], enabled: false);
        $resolver = $shop->resolver($shop->gate());

        self::assertSame(['https://shop-domain.de/de_DE/taxons/alt'], $resolver->resolve(Subject::taxon(5)));
        self::assertSame([], $resolver->resolve(Subject::taxon(5), strict: true));
        self::assertSame([], $resolver->resolve(Subject::taxon(404)));
        self::assertSame([], $resolver->resolve(Subject::product(404)));
    }

    #[Test]
    public function aGoLiveProductDoesNotDragItsListingAlong(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $shop->product(10, ['de_DE' => 'cohiba'], [$de], mainTaxon: $shop->taxon(5, ['de_DE' => 'kuba']));

        self::assertSame(['https://shop-domain.de/de_DE/products/cohiba'], $shop->resolver($shop->gate())->resolve(Subject::product(10), strict: true));
    }

    #[Test]
    public function emptySlugsAndDuplicatesProduceNoUrls(): void
    {
        $shop = new Shop();
        $de = $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE', 'en_US']);
        $taxon = $shop->taxon(5, ['de_DE' => 'kuba', 'en_US' => '']);
        $shop->product(10, ['de_DE' => 'cohiba', 'en_US' => ''], [$de], mainTaxon: $taxon);

        self::assertSame([
            'https://shop-domain.de/de_DE/products/cohiba',
            'https://shop-domain.de/de_DE/taxons/kuba',
        ], $shop->resolver($shop->gate())->resolve(Subject::product(10)));
    }

    #[Test]
    public function aPreResolvedUrlIsPassedThroughAndTheRouterContextIsRestored(): void
    {
        $shop = new Shop();
        $shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $routes = new \Symfony\Component\Routing\RouteCollection();
        $routes->add('sylius_shop_homepage', new \Symfony\Component\Routing\Route('/{_locale}/'));
        $context = new \Symfony\Component\Routing\RequestContext('', 'GET', 'admin.shop-domain.de', 'http');
        $generator = new \Symfony\Component\Routing\Generator\UrlGenerator($routes, $context);
        $resolver = new \Koh\IndexNow\Sylius\Url\UrlResolver($shop->gate(), $generator, $shop->products, $shop->taxons, ['product' => 'x', 'taxon' => 'y', 'homepage' => 'sylius_shop_homepage']);

        self::assertSame(['https://shop-domain.de/de_DE/'], $resolver->resolve(Subject::homepage()));
        self::assertSame($context, $generator->getContext(), 'the admin request keeps its own host');
        self::assertSame(['https://elsewhere.de/x'], $resolver->resolve(Subject::url('https://elsewhere.de/x')));
    }
}
