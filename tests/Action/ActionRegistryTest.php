<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Action;

use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Characterizes the {@see ActionRegistry} enumeration seam added for OpenAPI Slice 4
 * (bundle ADR 0076 + the OpenAPI metadata work): {@see ActionRegistry::forServerType()}
 * lists every action declared for a `(server, type)` (the composite-key lookup
 * cannot), and rehydrates each scalar descriptor — including the comma-joined `tags`
 * split back into a list.
 */
#[Group('spec:openapi')]
final class ActionRegistryTest extends TestCase
{
    #[Test]
    public function itListsAllActionsForAServerType(): void
    {
        $registry = new ActionRegistry($this->noHandlers(), [
            ActionRegistry::key('default', 'articles', ActionScope::Resource, 'publish') => $this->descriptor('articles', 'publish', tags: 'Content'),
            ActionRegistry::key('default', 'articles', ActionScope::Collection, 'import') => $this->descriptor('articles', 'import', scope: ActionScope::Collection),
            ActionRegistry::key('default', 'people', ActionScope::Resource, 'invite') => $this->descriptor('people', 'invite'),
            ActionRegistry::key('admin', 'articles', ActionScope::Resource, 'publish') => $this->descriptor('articles', 'publish', server: 'admin'),
        ]);

        $actions = $registry->forServerType('default', 'articles');

        $paths = \array_map(static fn($descriptor): string => $descriptor->path, $actions);
        self::assertEqualsCanonicalizing(['publish', 'import'], $paths);
    }

    #[Test]
    public function itRehydratesTheCommaJoinedTagsIntoAList(): void
    {
        $registry = new ActionRegistry($this->noHandlers(), [
            ActionRegistry::key('default', 'articles', ActionScope::Resource, 'publish') => $this->descriptor('articles', 'publish', tags: 'Content,Editorial'),
        ]);

        $action = $registry->forServerType('default', 'articles')[0];

        self::assertSame(['Content', 'Editorial'], $action->tags);
    }

    #[Test]
    public function emptyTagsRehydrateToAnEmptyList(): void
    {
        $registry = new ActionRegistry($this->noHandlers(), [
            ActionRegistry::key('default', 'articles', ActionScope::Resource, 'publish') => $this->descriptor('articles', 'publish'),
        ]);

        self::assertSame([], $registry->descriptorFor('default', 'articles', ActionScope::Resource, 'publish')?->tags);
    }

    #[Test]
    public function anUnknownServerTypeYieldsNoActions(): void
    {
        $registry = new ActionRegistry($this->noHandlers(), []);

        self::assertSame([], $registry->forServerType('default', 'articles'));
    }

    /**
     * @return array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, security: ?string, handlerServiceId: string, server: string, tags: string}
     */
    private function descriptor(string $type, string $path, ActionScope $scope = ActionScope::Resource, string $server = 'default', string $tags = ''): array
    {
        return [
            'type' => $type,
            'path' => $path,
            'methods' => ['POST'],
            'scope' => $scope->name,
            'input' => ActionInput::None->name,
            'inputType' => $type,
            'outputType' => $type,
            'security' => null,
            'handlerServiceId' => 'app.handler',
            'server' => $server,
            'tags' => $tags,
        ];
    }

    private function noHandlers(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('never resolves a handler');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
