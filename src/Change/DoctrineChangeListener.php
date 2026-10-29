<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Change;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
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
 *
 * A product or taxon is announced only when it is enabled after the flush or
 * was enabled before it. Disabling or removing a public one is announced, so
 * the engine learns it is gone; one that was never enabled — a draft created
 * disabled, edited or removed while disabled — is not, because its slug would
 * reach the engines before the page does. "Before" comes from Doctrine's
 * change set in preUpdate.
 */
class DoctrineChangeListener
{
    /** @var array<string, Subject> */
    private array $subjects = [];

    /** @var array<string, ProductInterface|TaxonInterface> subject key => what decides whether it is public */
    private array $owners = [];

    /** @var array<string, bool> subject key => enabled before this flush */
    private array $wasEnabled = [];

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

    /** Remember a product or taxon that is being disabled: that is a removal. */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof ProductInterface && !$object instanceof TaxonInterface) {
            return;
        }
        $subject = self::subjectOf($object);
        if ($subject !== null && $args->hasChangedField('enabled') && $args->getOldValue('enabled') === true) {
            $this->wasEnabled[$subject->key()] = true;
        }
    }

    /** @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    /**
     * A removed product or taxon has no URL afterwards, so it is resolved now —
     * if it was public; a disabled one never had a URL anyone may know.
     *
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof ProductInterface && !$object instanceof TaxonInterface) {
            $this->collect($object);

            return;
        }
        if (!$this->isOrWasEnabled($object)) {
            return;
        }

        try {
            $urls = $object instanceof ProductInterface ? $this->urls->forProduct($object) : $this->urls->forTaxon($object);
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: could not resolve a removed record: {message}', ['message' => $e->getMessage()]);
            $urls = [];
        }
        foreach ($urls as $url) {
            $subject = Subject::url($url);
            $this->subjects[$subject->key()] = $subject;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $subjects = [];
        foreach ($this->subjects as $key => $subject) {
            $owner = $this->owners[$key] ?? null;
            if ($owner === null || $this->isOrWasEnabled($owner)) {
                $subjects[$key] = $subject;
            }
        }
        $this->onClear();
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
        $this->owners = [];
        $this->wasEnabled = [];
    }

    private function collect(object $object): void
    {
        foreach (self::ownersOf($object) as $owner) {
            $subject = self::subjectOf($owner);
            if ($subject !== null) {
                $this->subjects[$subject->key()] = $subject;
                $this->owners[$subject->key()] = $owner;
            }
        }
    }

    private function isOrWasEnabled(ProductInterface|TaxonInterface $owner): bool
    {
        $subject = self::subjectOf($owner);

        return $owner->isEnabled() || ($subject !== null && ($this->wasEnabled[$subject->key()] ?? false));
    }

    private static function subjectOf(ProductInterface|TaxonInterface $owner): ?Subject
    {
        $id = $owner->getId();
        if (!is_int($id)) {
            return null;
        }

        return $owner instanceof ProductInterface ? Subject::product($id) : Subject::taxon($id);
    }

    /** @return list<ProductInterface|TaxonInterface> */
    private static function ownersOf(object $object): array
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

        $owners = [];
        if ($product instanceof ProductInterface) {
            $owners[] = $product;
        }
        if ($taxon instanceof TaxonInterface) {
            $owners[] = $taxon;
        }

        return $owners;
    }
}
