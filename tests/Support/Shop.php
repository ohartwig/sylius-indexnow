<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Support;

use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslation;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslation;
use Sylius\Component\Locale\Model\Locale;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** A small shop built from real Sylius model classes, no database. */
final class Shop
{
    public const SECRET = 'an-application-secret-of-sufficient-length-0123456789';

    /** @var InMemoryRepository<ChannelInterface> */
    public InMemoryRepository $channels;

    /** @var InMemoryRepository<ProductInterface> */
    public InMemoryRepository $products;

    /** @var InMemoryRepository<TaxonInterface> */
    public InMemoryRepository $taxons;

    public function __construct()
    {
        $this->channels = new InMemoryRepository(ChannelInterface::class);
        $this->products = new InMemoryRepository(ProductInterface::class);
        $this->taxons = new InMemoryRepository(TaxonInterface::class);
    }

    /** @param list<string> $locales */
    public function channel(int $id, string $code, string $hostname, array $locales = ['de_DE', 'en_US'], bool $enabled = true): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setHostname($hostname);
        $channel->setEnabled($enabled);
        foreach ($locales as $code) {
            $locale = new Locale();
            $locale->setCode($code);
            $channel->addLocale($locale);
        }
        self::setId($channel, $id);
        $this->channels->add($id, $channel);

        return $channel;
    }

    /**
     * @param array<string, string> $slugs locale => slug
     * @param list<ChannelInterface> $channels
     */
    public function product(int $id, array $slugs, array $channels, bool $enabled = true, ?TaxonInterface $mainTaxon = null): Product
    {
        $product = new Product();
        $product->setCode('P' . $id);
        $product->setEnabled($enabled);
        foreach ($slugs as $locale => $slug) {
            $translation = new ProductTranslation();
            $translation->setLocale($locale);
            $translation->setSlug($slug);
            $translation->setName($slug);
            $product->addTranslation($translation);
        }
        foreach ($channels as $channel) {
            $product->addChannel($channel);
        }
        $product->setMainTaxon($mainTaxon);
        self::setId($product, $id);
        $this->products->add($id, $product);

        return $product;
    }

    /** @param array<string, string> $slugs */
    public function taxon(int $id, array $slugs, ?TaxonInterface $parent = null, int $left = 1, int $right = 2, bool $enabled = true): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCode('T' . $id);
        $taxon->setEnabled($enabled);
        $taxon->setParent($parent);
        $taxon->setLeft($left);
        $taxon->setRight($right);
        foreach ($slugs as $locale => $slug) {
            $translation = new TaxonTranslation();
            $translation->setLocale($locale);
            $translation->setSlug($slug);
            $translation->setName($slug);
            $taxon->addTranslation($translation);
        }
        self::setId($taxon, $id);
        $this->taxons->add($id, $taxon);

        return $taxon;
    }

    /** @param list<string> $channelCodes */
    public function gate(string $environment = 'prod', string $key = '', array $channelCodes = [], string $secret = self::SECRET): ChannelGate
    {
        return new ChannelGate($this->channels, $environment, true, $key, $secret, $channelCodes, []);
    }

    public function resolver(ChannelGate $gate, bool $includeMainTaxon = true): UrlResolver
    {
        $routes = new RouteCollection();
        $routes->add('sylius_shop_product_show', new Route('/{_locale}/products/{slug}'));
        $routes->add('sylius_shop_product_index', new Route('/{_locale}/taxons/{slug}', [], ['slug' => '.+']));
        $routes->add('sylius_shop_homepage', new Route('/{_locale}/'));

        return new UrlResolver(
            $gate,
            new UrlGenerator($routes, new RequestContext()),
            $this->products,
            $this->taxons,
            ['product' => 'sylius_shop_product_show', 'taxon' => 'sylius_shop_product_index', 'homepage' => 'sylius_shop_homepage'],
            $includeMainTaxon,
        );
    }

    private static function setId(object $object, int $id): void
    {
        $property = new \ReflectionProperty($object, 'id');
        $property->setValue($object, $id);
    }
}
