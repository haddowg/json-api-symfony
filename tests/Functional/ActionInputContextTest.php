<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionInputContext\ActionInputContextKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An action validates its input document in the context it hydrates it in: a `PATCH`
 * in the update context, anything else in the create context.
 */
#[Group('spec:actions')]
final class ActionInputContextTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionInputContextKernel::class;
    }

    #[Test]
    public function aPatchWritesAFieldReadOnlyOnlyOnCreate(): void
    {
        $response = $this->handle('/contextNotes/-actions/record', 'PATCH', $this->body('PATCH', ['code' => 'A1']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['code' => 'A1', 'note' => 'unset'], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function aPatchChecksTheTypeOfAFieldReadOnlyOnlyOnCreate(): void
    {
        $response = $this->handle('/contextNotes/-actions/record', 'PATCH', $this->body('PATCH', ['code' => 5]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/attributes/code', $this->pointerOf($response));
    }

    #[Test]
    public function aPostLeavesAFieldReadOnlyOnCreateUnwritten(): void
    {
        $response = $this->handle('/contextNotes/-actions/record', 'POST', $this->body('POST', ['code' => 'A1']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['code' => 'unset', 'note' => 'unset'], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function aPatchTakesNullWhereOnlyAnUpdateMay(): void
    {
        $response = $this->handle('/contextNotes/-actions/record', 'PATCH', $this->body('PATCH', ['note' => null]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['code' => 'unset', 'note' => null], $this->decode($response)['meta'] ?? null);
    }

    #[Test]
    public function aPostRefusesNullWhereOnlyAnUpdateMayTakeIt(): void
    {
        $response = $this->handle('/contextNotes/-actions/record', 'POST', $this->body('POST', ['note' => null]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/data/attributes/note', $this->pointerOf($response));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function body(string $method, array $attributes): array
    {
        // An update's resource object names its id, and a create's names none.
        return ['data' => ['type' => 'contextNotes', 'attributes' => $attributes] + ($method === 'PATCH' ? ['id' => '1'] : [])];
    }

    private function pointerOf(\Symfony\Component\HttpFoundation\Response $response): mixed
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        $error = $errors[0];
        self::assertIsArray($error);
        $source = $error['source'] ?? null;
        self::assertIsArray($source);

        return $source['pointer'] ?? null;
    }
}
