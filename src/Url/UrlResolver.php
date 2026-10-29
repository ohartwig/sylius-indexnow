<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Url;

use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Queue\Subject;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface as BaseTaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * From "product 42 changed" to the shop URLs it has on each announcing channel.
 *
 * One URL per channel hostname and channel locale in which the product (or
 * taxon) has a slug. A product outside a channel has no page there; a taxon
 * outside a channel's menu tree is not shown there.
 *
 * Lenient for changes (a disabled product is still announced, so the engine
 * learns it is gone), strict for the go-live submission. Whether a disabled
 * product or taxon is queued at all is decided earlier: only when it was
 * enabled before the change (DoctrineChangeListener). A product that was never
 * public is not announced.
 */
class UrlResolver
{
    /**
     * @param ObjectRepository<ProductInterface> $products
     * @param ObjectRepository<TaxonInterface> $taxons
     * @param array{product: string, taxon: string, homepage: string} $routes
     */
    public function __construct(
        private readonly ChannelGate $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly ObjectRepository $products,
        private readonly ObjectRepository $taxons,
        private readonly array $routes,
        private readonly bool $includeMainTaxon = true,
    ) {}

    /** @return list<string> */
    public function resolve(Subject $subject, bool $strict = false): array
    {
        return match ($subject->type) {
            Subject::URL => [$subject->url],
            Subject::HOMEPAGE => $this->homepages(),
            Subject::PRODUCT => ($product = $this->products->find($subject->id)) instanceof ProductInterface ? $this->forProduct($product, $strict) : [],
            Subject::TAXON => ($taxon = $this->taxons->find($subject->id)) instanceof TaxonInterface ? $this->forTaxon($taxon, $strict) : [],
            default => [],
        };
    }

    /** @return list<string> */
    public function forProduct(ProductInterface $product, bool $strict = false): array
    {
        if ($strict && !$product->isEnabled()) {
            return [];
        }

        $urls = [];
        foreach ($this->announcingChannels() as $host => $channel) {
            if (!$product->hasChannel($channel)) {
                continue;
            }
            foreach (self::localeCodes($channel) as $locale) {
                $slug = self::productSlug($product, $locale);
                if ($slug !== null) {
                    $urls[] = $this->generate($host, $this->routes['product'], ['_locale' => $locale, 'slug' => $slug]);
                }
            }
        }

        // The listing changed, so it is worth a re-crawl, but only if it is itself public.
        $mainTaxon = $product->getMainTaxon();
        if (!$strict && $this->includeMainTaxon && $mainTaxon instanceof TaxonInterface && $mainTaxon->isEnabled()) {
            $urls = [...$urls, ...$this->forTaxon($mainTaxon)];
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    public function forTaxon(TaxonInterface $taxon, bool $strict = false): array
    {
        if ($strict && !$taxon->isEnabled()) {
            return [];
        }

        $urls = [];
        foreach ($this->announcingChannels() as $host => $channel) {
            if (!self::isInMenu($taxon, $channel)) {
                continue;
            }
            foreach (self::localeCodes($channel) as $locale) {
                $slug = self::taxonSlug($taxon, $locale);
                if ($slug !== null) {
                    $urls[] = $this->generate($host, $this->routes['taxon'], ['_locale' => $locale, 'slug' => $slug]);
                }
            }
        }

        return $urls;
    }

    /** @return list<string> */
    public function homepages(): array
    {
        $urls = [];
        foreach ($this->announcingChannels() as $host => $channel) {
            foreach (self::localeCodes($channel) as $locale) {
                $urls[] = $this->generate($host, $this->routes['homepage'], ['_locale' => $locale]);
            }
        }

        return $urls;
    }

    /** @return array<string, ChannelInterface> */
    public function announcingChannels(): array
    {
        return array_filter(
            $this->gate->channelsByHost(),
            fn(ChannelInterface $channel, string $host): bool => $this->gate->forHost($host)->active,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Generate against the channel's host rather than whatever host the CLI or
     * the admin request happens to carry.
     *
     * @param array<string, string> $parameters
     */
    private function generate(string $host, string $route, array $parameters): string
    {
        $original = $this->urls->getContext();
        $this->urls->setContext(new RequestContext('', 'GET', $host, 'https'));
        try {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urls->setContext($original);
        }
    }

    /** @return list<string> */
    private static function localeCodes(ChannelInterface $channel): array
    {
        $codes = [];
        foreach ($channel->getLocales() as $locale) {
            $code = $locale->getCode();
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Only a translation that exists in this locale — the fallback locale's slug would be a different page. */
    private static function productSlug(ProductInterface $product, string $locale): ?string
    {
        foreach ($product->getTranslations() as $translation) {
            if ($translation instanceof ProductTranslationInterface && $translation->getLocale() === $locale) {
                $slug = $translation->getSlug();

                return is_string($slug) && $slug !== '' ? $slug : null;
            }
        }

        return null;
    }

    private static function taxonSlug(TaxonInterface $taxon, string $locale): ?string
    {
        foreach ($taxon->getTranslations() as $translation) {
            if ($translation instanceof TaxonTranslationInterface && $translation->getLocale() === $locale) {
                $slug = $translation->getSlug();

                return is_string($slug) && $slug !== '' ? $slug : null;
            }
        }

        return null;
    }

    /** Inside the channel's menu tree, by nested-set bounds; everywhere when the channel has no menu taxon. */
    private static function isInMenu(TaxonInterface $taxon, ChannelInterface $channel): bool
    {
        $menu = $channel->getMenuTaxon();
        if ($menu === null) {
            return true;
        }
        // Nested-set bounds are only comparable inside one tree.
        if (self::rootOf($taxon)->getCode() !== self::rootOf($menu)->getCode()) {
            return false;
        }

        return $taxon->getLeft() >= $menu->getLeft() && $taxon->getRight() <= $menu->getRight();
    }

    /**
     * The tree root. getRoot() is filled in by the tree extension and is null
     * on a root itself or before persisting, so null must not mean "same tree".
     */
    private static function rootOf(BaseTaxonInterface $taxon): BaseTaxonInterface
    {
        $root = $taxon->getRoot();
        if ($root instanceof BaseTaxonInterface) {
            return $root;
        }
        $seen = [];
        while (($parent = $taxon->getParent()) instanceof BaseTaxonInterface && !isset($seen[spl_object_id($parent)])) {
            $seen[spl_object_id($parent)] = true;
            $taxon = $parent;
        }

        return $taxon;
    }
}
