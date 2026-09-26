<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Koh\IndexNow\Sylius\Change\DoctrineChangeListener;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\ProductImage;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariant;

final class ChangeListenerTest extends TestCase
{
    private QueueStore $queue;

    private Shop $shop;

    private DoctrineChangeListener $listener;

    protected function setUp(): void
    {
        $this->queue = new QueueStore(SqliteDatabase::withQueue());
        $this->shop = new Shop();
        $this->shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $this->listener = new DoctrineChangeListener($this->queue, $this->shop->resolver($this->shop->gate()), new NullLogger());
    }

    #[Test]
    public function everythingShownOnAProductPageMapsToTheProduct(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $taxon = $this->shop->taxon(5, ['de_DE' => 'zigarren']);

        $variant = new ProductVariant();
        $variant->setProduct($product);
        $pricing = new ChannelPricing();
        $pricing->setProductVariant($variant);
        $assignment = new ProductTaxon();
        $assignment->setProduct($product);
        $assignment->setTaxon($taxon);

        foreach ([$product->getTranslation('de_DE'), $variant, $pricing, $assignment] as $changed) {
            $this->listener->postUpdate($this->args($changed));
        }
        self::assertSame([], $this->queue->due(10), 'nothing is written before the flush');

        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        $queued = [];
        foreach ($this->queue->due(10) as $item) {
            $subject = $item->subject;
            $queued[] = sprintf('%s:%d', $subject->type, $subject->id);
        }
        sort($queued);
        self::assertSame(['product:10', 'taxon:5'], $queued, 'one row per product, however many parts of it changed');
    }

    #[Test]
    public function aRemovedProductIsResolvedToItsUrlsBeforeItIsGone(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);

        $this->listener->preRemove($this->args($product));
        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        $items = $this->queue->due(10);
        self::assertCount(1, $items);
        self::assertSame(Subject::URL, $items[0]->subject->type);
        self::assertSame('https://shop-domain.de/de_DE/products/zigarre', $items[0]->subject->url);
    }

    #[Test]
    public function aClearedUnitOfWorkForgetsWhatItCollected(): void
    {
        $this->listener->postPersist($this->args($this->shop->taxon(5, ['de_DE' => 'zigarren'])));
        $this->listener->onClear();
        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        self::assertSame([], $this->queue->due(10));
    }

    #[Test]
    public function aBrokenQueueNeverBreaksTheSaveThatTriggeredIt(): void
    {
        $withoutTables = new QueueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $listener = new DoctrineChangeListener($withoutTables, $this->shop->resolver($this->shop->gate()), new NullLogger());
        $listener->postPersist($this->args($this->shop->taxon(5, ['de_DE' => 'zigarren'])));

        $listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        $this->addToAssertionCount(1);
    }

    /** @return LifecycleEventArgs<ObjectManager> */
    private function args(object $object): LifecycleEventArgs
    {
        return new LifecycleEventArgs($object, $this->createStub(ObjectManager::class));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function changedParts(): iterable
    {
        yield 'the product itself' => ['product', ['product:10']];
        yield 'its translation' => ['translation', ['product:10']];
        yield 'a variant' => ['variant', ['product:10']];
        yield 'a price' => ['price', ['product:10']];
        yield 'a price whose variant is gone' => ['orphan price', []];
        yield 'an image' => ['image', ['product:10']];
        yield 'a taxon assignment' => ['assignment', ['product:10', 'taxon:5']];
        yield 'a taxon' => ['taxon', ['taxon:5']];
        yield 'a taxon translation' => ['taxon translation', ['taxon:5']];
        yield 'anything else' => ['other', []];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('changedParts')]
    public function eachChangedPartMapsToItsPage(string $part, array $expected): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $taxon = $this->shop->taxon(5, ['de_DE' => 'zigarren']);
        $variant = new ProductVariant();
        $variant->setProduct($product);

        $changed = match ($part) {
            'product' => $product,
            'translation' => $product->getTranslation('de_DE'),
            'variant' => $variant,
            'price' => (static function () use ($variant): ChannelPricing { $p = new ChannelPricing(); $p->setProductVariant($variant); return $p; })(),
            'orphan price' => new ChannelPricing(),
            'image' => (static function () use ($product): ProductImage { $i = new ProductImage(); $i->setOwner($product); return $i; })(),
            'assignment' => (static function () use ($product, $taxon): ProductTaxon { $a = new ProductTaxon(); $a->setProduct($product); $a->setTaxon($taxon); return $a; })(),
            'taxon' => $taxon,
            'taxon translation' => $taxon->getTranslation('de_DE'),
            default => new \stdClass(),
        };

        $part === 'product' ? $this->listener->postPersist($this->args($changed)) : $this->listener->postUpdate($this->args($changed));
        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        $queued = [];
        foreach ($this->queue->due(10) as $item) {
            $subject = $item->subject;
            $queued[] = sprintf('%s:%d', $subject->type, $subject->id);
        }
        sort($queued);
        self::assertSame($expected, $queued);
    }

    #[Test]
    public function aRemovedTaxonIsResolvedToItsUrlAndOtherRemovalsToTheirOwner(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $variant = new ProductVariant();
        $variant->setProduct($product);

        $this->listener->preRemove($this->args($this->shop->taxon(5, ['de_DE' => 'zigarren'])));
        $this->listener->preRemove($this->args($variant));
        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));

        $queued = [];
        foreach ($this->queue->due(10) as $item) {
            $subject = $item->subject;
            $queued[] = $subject->type === Subject::URL ? $subject->url : sprintf('%s:%d', $subject->type, $subject->id);
        }
        sort($queued);
        self::assertSame(['https://shop-domain.de/de_DE/taxons/zigarren', 'product:10'], $queued);
    }
}
