<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
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

    #[Test]
    public function aProductCreatedDisabledIsNotAnnounced(): void
    {
        // AUD-063-F01: drafts are created disabled (the admin, the shop's MCP
        // server), and their slug went out on the first save.
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $taxon = $this->shop->taxon(5, ['de_DE' => 'zigarren']);
        $product = $this->shop->product(11, ['de_DE' => 'neuheit'], [$de], enabled: false, mainTaxon: $taxon);

        $this->listener->postPersist($this->args($product));
        $this->flush();

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function editingOrRemovingAProductThatWasNeverEnabledAnnouncesNothing(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(11, ['de_DE' => 'neuheit'], [$de], enabled: false);
        $variant = new ProductVariant();
        $variant->setProduct($product);

        $this->listener->preUpdate($this->updateArgs($product, ['code' => ['P11', 'P11-NEU']]));
        $this->listener->postUpdate($this->args($product));
        $this->listener->postUpdate($this->args($product->getTranslation('de_DE')));
        $this->listener->postPersist($this->args($variant));
        $this->listener->preRemove($this->args($product));
        $this->flush();

        self::assertSame([], $this->queued());
    }

    #[Test]
    public function disablingAnEnabledProductAnnouncesItSoTheEngineLearnsItIsGone(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);

        $product->setEnabled(false);
        $this->listener->preUpdate($this->updateArgs($product, ['enabled' => [true, false]]));
        $this->listener->postUpdate($this->args($product));
        $this->flush();

        self::assertSame(['product:10'], $this->queued());
    }

    #[Test]
    public function enablingADraftAnnouncesIt(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(11, ['de_DE' => 'neuheit'], [$de], enabled: false);

        $product->setEnabled(true);
        $this->listener->preUpdate($this->updateArgs($product, ['enabled' => [false, true]]));
        $this->listener->postUpdate($this->args($product));
        $this->flush();

        self::assertSame(['product:11'], $this->queued());
    }

    #[Test]
    public function removingAProductThatWasDisabledInTheSameFlushIsStillAnnounced(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);

        $product->setEnabled(false);
        $this->listener->preUpdate($this->updateArgs($product, ['enabled' => [true, false]]));
        $this->listener->preRemove($this->args($product));
        $this->flush();

        self::assertSame(['https://shop-domain.de/de_DE/products/zigarre'], $this->queued());
    }

    #[Test]
    public function aTaxonFollowsTheSameRule(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $draft = $this->shop->taxon(6, ['de_DE' => 'neu'], enabled: false);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $assignment = new ProductTaxon();
        $assignment->setProduct($product);
        $assignment->setTaxon($draft);

        $this->listener->postPersist($this->args($draft));
        $this->listener->postPersist($this->args($assignment));
        $this->listener->preRemove($this->args($this->shop->taxon(7, ['de_DE' => 'verworfen'], enabled: false)));
        $this->flush();
        self::assertSame(['product:10'], $this->queued(), 'a disabled taxon is neither announced nor resolved on removal');

        $public = $this->shop->taxon(5, ['de_DE' => 'zigarren']);
        $public->setEnabled(false);
        $this->listener->preUpdate($this->updateArgs($public, ['enabled' => [true, false]]));
        $this->listener->postUpdate($this->args($public));
        $this->flush();
        self::assertSame(['product:10', 'taxon:5'], $this->queued());
    }

    #[Test]
    public function onlyAProductOrTaxonCanBeDisabledAndEachFlushStartsAfresh(): void
    {
        $de = $this->shop->channels->find(1);
        self::assertNotNull($de);
        $product = $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $variant = new ProductVariant();
        $variant->setProduct($product);

        $this->listener->preUpdate($this->updateArgs($variant, ['enabled' => [true, false]]));
        $product->setEnabled(false);
        $this->listener->preUpdate($this->updateArgs($product, ['enabled' => [false, false]]));
        $this->listener->postUpdate($this->args($variant));
        $this->flush();
        self::assertSame([], $this->queued(), 'a variant being disabled does not make its disabled product public');

        $product->setEnabled(true);
        $this->listener->postUpdate($this->args($product));
        $this->flush();
        self::assertSame(['product:10'], $this->queued());

        $this->queue->remove(array_map(static fn($item): int => $item->id, $this->queue->due(100)));
        $this->flush();
        self::assertSame([], $this->queued(), 'nothing is left over from the previous flush');
    }

    /** @return list<string> */
    private function queued(): array
    {
        $queued = [];
        foreach ($this->queue->due(100) as $item) {
            $subject = $item->subject;
            $queued[] = $subject->type === Subject::URL ? $subject->url : sprintf('%s:%d', $subject->type, $subject->id);
        }
        sort($queued);

        return $queued;
    }

    private function flush(): void
    {
        $this->listener->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
    }

    /** @param array<string, array{mixed, mixed}> $changeSet */
    private function updateArgs(object $object, array $changeSet): PreUpdateEventArgs
    {
        return new PreUpdateEventArgs($object, $this->createStub(EntityManagerInterface::class), $changeSet);
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
