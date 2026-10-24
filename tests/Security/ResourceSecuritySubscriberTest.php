<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Security;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without an authorization checker the subscriber refuses an operation gated by an
 * expression instead of letting it through (bundle ADR 0130). The build normally
 * stops this case first; this is the runtime fallback.
 */
final class ResourceSecuritySubscriberTest extends TestCase
{
    #[Test]
    public function aResourceExpressionWithoutACheckerIsRefused(): void
    {
        $subscriber = new ResourceSecuritySubscriber(null, new ResourceSecurityRegistry([
            'widgets' => ['delete' => "is_granted('ROLE_ADMIN')"],
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be evaluated');

        $subscriber->onBeforeDelete($this->deleteEvent());
    }

    #[Test]
    public function anActionExpressionWithoutACheckerIsRefused(): void
    {
        $subscriber = new ResourceSecuritySubscriber(null, new ResourceSecurityRegistry());

        $this->expectException(\LogicException::class);

        $subscriber->onBeforeAction(new BeforeActionEvent('widgets', 'archive', null, "is_granted('ROLE_ADMIN')"));
    }

    #[Test]
    public function documentationOnlyDeclarationsNeedNoChecker(): void
    {
        $subscriber = new ResourceSecuritySubscriber(null, new ResourceSecurityRegistry([
            'widgets' => ['default' => true, 'delete' => false],
        ]));

        $subscriber->onBeforeDelete($this->deleteEvent());
        $subscriber->onBeforeAction(new BeforeActionEvent('widgets', 'archive', null, null));

        $this->addToAssertionCount(1);
    }

    private function deleteEvent(): BeforeDeleteEvent
    {
        return new BeforeDeleteEvent('widgets', $this->createStub(JsonApiRequestInterface::class), new \stdClass(), 'default');
    }
}
