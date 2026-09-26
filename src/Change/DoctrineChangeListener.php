<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Change;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Queue\Reason;
use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;

/**
 * Notices product and taxon changes, from the admin, an import or the API alike.
 *
 * Everything a visitor sees on a product page maps to that product: its
 * translations, variants, prices, images and taxon assignments. Changes are
 * buffered and written in one go after the flush; a failure is logged and
 * never breaks the save that triggered it.
 */
class DoctrineChangeListener
{
    /** @var array<string, Subject> */
    private array $subjects = [];

    public function __construct(
        private readonly QueueStore $queue,
        private readonly UrlResolver $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    /** @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    /**
     * A removed product or taxon has no URL afterwards, so it is resolved now.
     *
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        try {
            $urls = match (true) {
                $object instanceof ProductInterface => $this->urls->forProduct($object),
                $object instanceof TaxonInterface => $this->urls->forTaxon($object),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: could not resolve a removed record: {message}', ['message' => $e->getMessage()]);
            $urls = [];
        }

        if ($urls === null) {
            $this->collect($object);

            return;
        }
        foreach ($urls as $url) {
            $subject = Subject::url($url);
            $this->subjects[$subject->key()] = $subject;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $subjects = $this->subjects;
        $this->subjects = [];
        if ($subjects === []) {
            return;
        }

        try {
            $this->queue->enqueue($subjects, Reason::Change);
        } catch (\Throwable $e) {
            $this->logger->error('IndexNow: {count} changes could not be queued: {message}', [
                'count' => count($subjects),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    public function onClear(): void
    {
        $this->subjects = [];
    }

    private function collect(object $object): void
    {
        foreach (self::subjectsOf($object) as $subject) {
            $this->subjects[$subject->key()] = $subject;
        }
    }

    /** @return list<Subject> */
    private static function subjectsOf(object $object): array
    {
        $product = match (true) {
            $object instanceof ProductInterface => $object,
            $object instanceof ProductTranslationInterface => $object->getTranslatable(),
            $object instanceof ProductVariantInterface => $object->getProduct(),
            $object instanceof ChannelPricingInterface => $object->getProductVariant()?->getProduct(),
            $object instanceof ProductImageInterface => $object->getOwner(),
            $object instanceof ProductTaxonInterface => $object->getProduct(),
            default => null,
        };
        $taxon = match (true) {
            $object instanceof TaxonInterface => $object,
            $object instanceof TaxonTranslationInterface => $object->getTranslatable(),
            $object instanceof ProductTaxonInterface => $object->getTaxon(),
            default => null,
        };

        $subjects = [];
        if ($product instanceof ProductInterface && is_int($product->getId())) {
            $subjects[] = Subject::product($product->getId());
        }
        if ($taxon instanceof TaxonInterface && is_int($taxon->getId())) {
            $subjects[] = Subject::taxon($taxon->getId());
        }

        return $subjects;
    }
}
