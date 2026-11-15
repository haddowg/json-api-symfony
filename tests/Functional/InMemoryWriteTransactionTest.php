<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxScenario;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Service\ResetInterface;

/**
 * {@see WriteTransactionConformanceTestCase} on the in-memory persister, whose transaction
 * is a snapshot of both stores taken through one coordinator.
 */
final class InMemoryWriteTransactionTest extends WriteTransactionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TxInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        TxFactory::reset();
    }

    #[Test]
    public function aBatchContextLeftActiveIsResetBeforeTheNextRequest(): void
    {
        $context = static::getContainer()->get(WriteTransactionContext::class);
        self::assertInstanceOf(WriteTransactionContext::class, $context);
        // A batch that never deactivated its context, in a worker.
        $context->activate();

        $resetter = static::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertFalse($context->isActive());

        // The next single write runs in its own transaction again.
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        self::assertSame(403, $this->handle('/txWidgets', 'POST', $this->widget('third'))->getStatusCode());
        self::assertSame(['seeded'], $this->notes());
    }
}
