<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The composite-attribute conformance witness (core ADRs 0118–0121), run against
 * both providers: the validator bridge cascades an
 * {@see \haddowg\JsonApi\Resource\Field\Obj}'s children and a
 * {@see \haddowg\JsonApi\Resource\Field\OneOf}'s selected variant children,
 * surfacing per-child `422`s with `/data/attributes/<field>/<child>` pointers and
 * rejecting an unknown discriminator; a {@see \haddowg\JsonApi\Resource\Constraint\Shape}
 * is value-validated by the core opis validator. Valid composite values
 * round-trip persistence as a single value each — on the Doctrine kernel, a real
 * `json` column.
 */
abstract class CompositeConformanceTestCase extends JsonApiFunctionalTestCase
{
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
    public function compositeValuesRoundTripThroughPersistence(): void
    {
        // Scalar children of all three composite kinds, written as one value per
        // attribute and read back byte-equal — on the Doctrine kernel this is the
        // single-json-column round-trip (an int `level` survives json encoding).
        $attributes = [
            'name' => 'Gadget',
            'address' => ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1'],
            'block' => ['kind' => 'heading', 'text' => 'Hello', 'level' => 2],
            'contact' => ['kind' => 'phone', 'number' => '+44 20 7946 0000'],
        ];

        $created = $this->handle('/composites', 'POST', [
            'data' => ['type' => 'composites', 'attributes' => $attributes],
        ]);
        self::assertSame(201, $created->getStatusCode());

        $body = $this->decode($created);
        self::assertIsArray($body['data'] ?? null);
        self::assertIsString($body['data']['id'] ?? null);
        $id = $body['data']['id'];

        $fetched = $this->handle('/composites/' . $id);
        self::assertSame(200, $fetched->getStatusCode());

        $fetchedBody = $this->decode($fetched);
        self::assertIsArray($fetchedBody['data'] ?? null);
        self::assertSame($attributes, $fetchedBody['data']['attributes'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCompositeValueReplacesOnUpdate(): void
    {
        // The seeded widget (id 1) carries an address; a PATCH sending a complete
        // new address replaces the stored value, and a fresh read serves the new
        // value from persistence.
        $updated = $this->handle('/composites/1', 'PATCH', [
            'data' => [
                'type' => 'composites',
                'id' => '1',
                'attributes' => [
                    'address' => ['street' => '2 Low Rd', 'city' => 'Leeds', 'postcode' => 'LS1'],
                ],
            ],
        ]);
        self::assertSame(200, $updated->getStatusCode());

        $fetched = $this->decode($this->handle('/composites/1'));
        self::assertIsArray($fetched['data'] ?? null);
        self::assertIsArray($fetched['data']['attributes'] ?? null);
        self::assertSame(
            ['street' => '2 Low Rd', 'city' => 'Leeds', 'postcode' => 'LS1'],
            $fetched['data']['attributes']['address'] ?? null,
        );
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
