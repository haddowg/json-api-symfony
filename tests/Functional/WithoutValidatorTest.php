<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WithoutValidator\WithoutValidatorKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Without `symfony/validator`, a written value of the wrong JSON type is still a `422`
 * at its pointer, on a create, an update and an action's input. The declared constraint
 * rules are what go unchecked.
 */
final class WithoutValidatorTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return WithoutValidatorKernel::class;
    }

    #[Test]
    public function aCreateRefusesAValueOfTheWrongType(): void
    {
        $response = $this->handle('/typedNotes', 'POST', ['data' => ['type' => 'typedNotes', 'attributes' => ['title' => 5]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/title', 'This value should be of type string.']], $this->errors($response));
    }

    #[Test]
    public function anUpdateRefusesAValueOfTheWrongType(): void
    {
        $response = $this->handle('/typedNotes/1', 'PATCH', ['data' => ['type' => 'typedNotes', 'id' => '1', 'attributes' => ['count' => 'many']]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/count', 'This value should be of type integer.']], $this->errors($response));
    }

    #[Test]
    public function aCreateRefusesNullForAFieldThatIsNotNullable(): void
    {
        $response = $this->handle('/typedNotes', 'POST', ['data' => ['type' => 'typedNotes', 'attributes' => ['title' => null]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/title', 'This value should not be null.']], $this->errors($response));
    }

    #[Test]
    public function anActionInputRefusesAValueOfTheWrongType(): void
    {
        $response = $this->handle('/typedNotes/-actions/record', 'POST', ['data' => ['type' => 'typedNotes', 'attributes' => ['title' => ['a']]]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([['/data/attributes/title', 'This value should be of type string.']], $this->errors($response));
    }

    #[Test]
    public function theConstraintRulesGoUnchecked(): void
    {
        $response = $this->handle('/typedNotes', 'POST', ['data' => ['type' => 'typedNotes', 'attributes' => ['title' => 'Far too long', 'count' => 2]]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function errors(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        $found = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? null;
            self::assertIsArray($source);
            $found[] = [$source['pointer'] ?? null, $error['detail'] ?? null];
        }

        return $found;
    }
}
