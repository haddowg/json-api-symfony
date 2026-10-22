<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
            'prefs' => ['theme' => 'dark'],
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

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicResultKeepsAnEmptyMapAnObject(): void
    {
        // The seeded widget stores an empty `prefs` map. Its update result must carry
        // `{}`, the same as a direct read; an associative decode can't tell `{}` from
        // `[]`, so the raw body is the only honest witness.
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '1'],
                'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
            ],
        ]);

        $body = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('"prefs":{}', $body);
        self::assertStringNotContainsString('"prefs":[]', $body);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicResultKeepsANonEmptyMapUnchanged(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '1'],
                'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => ['theme' => 'dark']]],
            ],
        ]);

        $body = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('"prefs":{"theme":"dark"}', $body);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicFailureDocumentCarriesNoEmptyArrayMembers(): void
    {
        // A returned 404 (a missing update target) and a thrown 422 (a value failing
        // its object shape) take the two failure paths through the batch.
        $missing = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '404'],
                'data' => ['type' => 'composites', 'id' => '404', 'attributes' => ['name' => 'Ghost']],
            ],
        ]);
        $invalid = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '1'],
                'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => 'not an object']],
            ],
        ]);

        self::assertSame(404, $missing->getStatusCode(), (string) $missing->getContent());
        self::assertSame(422, $invalid->getStatusCode(), (string) $invalid->getContent());
        self::assertContains('/atomic:operations/0/data/attributes/prefs', $this->fieldPointersOf($invalid), (string) $invalid->getContent());

        foreach ([$missing, $invalid] as $response) {
            $body = (string) $response->getContent();
            foreach (['source', 'links', 'meta', 'jsonapi'] as $member) {
                self::assertStringNotContainsString('"' . $member . '":[]', $body);
            }
        }
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyObjectSatisfiesAnObjectShapeOnCreate(): void
    {
        $created = $this->handle('/composites', 'POST', [
            'data' => ['type' => 'composites', 'attributes' => ['name' => 'Gadget', 'prefs' => new \stdClass()]],
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $id = $this->createdId($created);

        // Stored as an empty map: a stored null would read back as `null`.
        self::assertStringContainsString('"prefs":{}', (string) $this->handle('/composites/' . $id)->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyArrayFailsAnObjectShapeOnCreate(): void
    {
        $response = $this->handle('/composites', 'POST', [
            'data' => ['type' => 'composites', 'attributes' => ['name' => 'Gadget', 'prefs' => []]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/prefs'], $this->fieldPointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyObjectSatisfiesAnObjectShapeOnUpdate(): void
    {
        $this->handle('/composites/1', 'PATCH', [
            'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => ['theme' => 'dark']]],
        ]);

        $updated = $this->handle('/composites/1', 'PATCH', [
            'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => new \stdClass()]],
        ]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());

        self::assertStringContainsString('"prefs":{}', (string) $this->handle('/composites/1')->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function anEmptyArrayFailsAnObjectShapeOnUpdate(): void
    {
        $response = $this->handle('/composites/1', 'PATCH', [
            'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => []]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/prefs'], $this->fieldPointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aStoredEmptyMapSurvivesAPartialUpdate(): void
    {
        // The seeded widget stores an empty `prefs` map, which the update's validation
        // folds in from its rendered `{}` form though the client didn't send it.
        $updated = $this->handle('/composites/1', 'PATCH', [
            'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
        ]);

        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertStringContainsString('"prefs":{}', (string) $updated->getContent());
    }

    #[Test]
    #[Group('spec:crud')]
    public function aMapOverItsSizeLimitStillFails(): void
    {
        $response = $this->handle('/composites/1', 'PATCH', [
            'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]]],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/data/attributes/prefs'], $this->fieldPointersOf($response));
    }

    #[Test]
    #[Group('spec:crud')]
    public function aNonEmptyMapPassesAnObjectShapeUnchanged(): void
    {
        $created = $this->handle('/composites', 'POST', [
            'data' => ['type' => 'composites', 'attributes' => ['name' => 'Gadget', 'prefs' => ['theme' => 'dark', 'size' => 2]]],
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $id = $this->createdId($created);
        self::assertStringContainsString('"prefs":{"theme":"dark","size":2}', (string) $this->handle('/composites/' . $id)->getContent());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicAddWithAnEmptyObjectSatisfiesAnObjectShape(): void
    {
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => ['type' => 'composites', 'attributes' => ['name' => 'Gadget', 'prefs' => new \stdClass()]],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '1'],
                'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => ['theme' => 'dark']]],
            ],
        ]);

        $body = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('"prefs":{}', $body);
        self::assertStringContainsString('"prefs":{"theme":"dark"}', $body);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicAddWithAnEmptyArrayFailsAnObjectShape(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'composites', 'id' => '1'],
                'data' => ['type' => 'composites', 'id' => '1', 'attributes' => ['prefs' => new \stdClass()]],
            ],
            [
                'op' => 'add',
                'data' => ['type' => 'composites', 'attributes' => ['name' => 'Gadget', 'prefs' => []]],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/atomic:operations/1/data/attributes/prefs'], $this->fieldPointersOf($response));
    }

    /**
     * @return list<string>
     */
    private function violationPointers(Response $response): array
    {
        self::assertSame(422, $response->getStatusCode());

        return $this->pointersOf($response);
    }

    private function createdId(Response $response): string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * The error pointers with any trailing `/` dropped: opis reports a whole-value
     * violation at the instance root `/`, which core appends to the field pointer.
     *
     * @return list<string>
     */
    private function fieldPointersOf(Response $response): array
    {
        return \array_map(static fn(string $pointer): string => \rtrim($pointer, '/'), $this->pointersOf($response));
    }

    /**
     * @return list<string>
     */
    private function pointersOf(Response $response): array
    {
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

    /**
     * Posts an Atomic Operations batch. Operation members may hold a `\stdClass`
     * where the wire needs an empty object `{}`.
     *
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: [
            'CONTENT_TYPE' => $ext,
            'HTTP_ACCEPT' => $ext,
        ], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
