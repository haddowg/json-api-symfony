<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The composite-attribute validation witness (core ADRs 0118/0119): the validator
 * bridge cascades an {@see \haddowg\JsonApi\Resource\Field\Obj}'s children and a
 * {@see \haddowg\JsonApi\Resource\Field\OneOf}'s selected variant children, surfacing
 * per-child `422`s with `/data/attributes/<field>/<child>` pointers, and rejecting an
 * unknown discriminator.
 */
final class CompositeValidationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return CompositeInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValidCompositeCreates(): void
    {
        $response = $this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'address' => ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
                    'block' => ['kind' => 'image', 'src' => 'https://example.test/a.png', 'alt' => 'A photo'],
                    'contact' => ['kind' => 'email', 'address' => 'ada@example.test'],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidObjChildPointsAtTheChild(): void
    {
        $pointers = $this->violationPointers($this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'address' => ['street' => '1 High St', 'city' => ''], // city blank, postcode missing
                ],
            ],
        ]));

        self::assertContains('/data/attributes/address/city', $pointers);
        self::assertContains('/data/attributes/address/postcode', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anInvalidOneOfVariantChildPointsAtTheChild(): void
    {
        $pointers = $this->violationPointers($this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'block' => ['kind' => 'heading', 'text' => 'Hi', 'level' => 99], // level out of 1..6
                ],
            ],
        ]));

        self::assertContains('/data/attributes/block/level', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function anUnknownDiscriminatorIsRejected(): void
    {
        $pointers = $this->violationPointers($this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'block' => ['kind' => 'video', 'url' => 'https://example.test/v.mp4'],
                ],
            ],
        ]));

        self::assertContains('/data/attributes/block/kind', $pointers);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aShapeConstraintValueViolationPointsUnderTheField(): void
    {
        // kind=email selects the email branch of the Shape's oneOf, but `address`
        // is missing — the core opis SchemaValueValidator rejects it, and the leaf
        // pointer is prefixed with the field pointer.
        $pointers = $this->violationPointers($this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'contact' => ['kind' => 'email'], // missing `address`
                ],
            ],
        ]));

        self::assertNotSame([], $pointers);
        foreach ($pointers as $pointer) {
            self::assertStringStartsWith('/data/attributes/contact', $pointer);
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function aShapeConstraintUnknownDiscriminatorIsRejected(): void
    {
        // A discriminator matching neither branch fails the whole oneOf.
        $pointers = $this->violationPointers($this->handle('/composites', 'POST', [
            'data' => [
                'type' => 'composites',
                'attributes' => [
                    'name' => 'Gadget',
                    'contact' => ['kind' => 'fax', 'number' => '123'],
                ],
            ],
        ]));

        self::assertNotSame([], $pointers);
        foreach ($pointers as $pointer) {
            self::assertStringStartsWith('/data/attributes/contact', $pointer);
        }
    }

    /**
     * @return list<string>
     */
    private function violationPointers(Response $response): array
    {
        self::assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);
        $errors = $body['errors'] ?? [];
        self::assertIsArray($errors);

        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? [];
            if (\is_array($source) && \is_string($source['pointer'] ?? null)) {
                $pointers[] = $source['pointer'];
            }
        }

        return $pointers;
    }
}
