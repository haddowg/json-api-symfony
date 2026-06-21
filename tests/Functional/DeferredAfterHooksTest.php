<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataPersister\WriteTransactionContext;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookLog;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\LifecycleHooksTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Slice B seam: the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * defers its After* lifecycle dispatch to post-commit when (and ONLY when) a
 * {@see WriteTransactionContext} is active — the behaviour the Atomic Operations
 * executor drives next slice. On the single-op path (the context inactive, its
 * default) the After* hooks fire inline exactly as today.
 *
 * Driven through the real create lifecycle of the {@see LifecycleHooksTestKernel}
 * so all collaborators are production wiring; the process-static {@see HookLog}
 * records the firing order. The context is a shared service, so the test toggles
 * the same instance the handler reads.
 */
final class DeferredAfterHooksTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return LifecycleHooksTestKernel::class;
    }

    protected function afterBoot(): void
    {
        HookWidgetFactory::reset();
        HookLog::reset();
    }

    #[Test]
    public function withAnInactiveContextTheAfterHooksFireInlineAsToday(): void
    {
        // The default single-op path: the context is inactive, so create fires its
        // After* hooks inline — the create response already carries them.
        $response = $this->handle('/hookWidgets', 'POST', [
            'data' => ['type' => 'hookWidgets', 'attributes' => ['name' => 'created']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['serving', 'beforeSave', 'beforeCreate', 'afterCreate', 'afterSave'],
            HookLog::entries(),
        );
    }

    #[Test]
    public function withAnActiveContextTheAfterHooksAreDeferredUntilDrain(): void
    {
        $context = $this->writeTransactionContext();

        // The executor (next slice) marks the batch active before driving the
        // operations. Pre-activate the shared context to stand in for that.
        $context->activate();

        $response = $this->handle('/hookWidgets', 'POST', [
            'data' => ['type' => 'hookWidgets', 'attributes' => ['name' => 'created']],
        ]);

        // The create still succeeds and the BEFORE hooks fired inline (they gate the
        // persist, which DID run — the per-operation flush is non-durable under the
        // batch's open transaction, but the in-memory witness has no transaction
        // semantics here; the point is the AFTER hooks did NOT fire).
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['serving', 'beforeSave', 'beforeCreate'], HookLog::entries());

        // Draining the post-commit queue fires the deferred After* hooks, in order.
        $context->drain();

        self::assertSame(
            ['serving', 'beforeSave', 'beforeCreate', 'afterCreate', 'afterSave'],
            HookLog::entries(),
        );

        $context->deactivate();
    }

    private function writeTransactionContext(): WriteTransactionContext
    {
        $context = static::getContainer()->get(WriteTransactionContext::class);
        \assert($context instanceof WriteTransactionContext);

        return $context;
    }
}
