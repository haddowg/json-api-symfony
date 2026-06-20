<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The acceptance suite for the flattened-attribute (`on()`) trio + the eager-load
 * declaration (bundle ADR 0085), run identically against the in-memory witness
 * ({@see InMemoryFlattenTest}) and the Doctrine reference ({@see DoctrineFlattenTest}),
 * so a failure localises to the provider, not the fixture.
 *
 * The fixture (`books` over `authors`/`countries`):
 *
 *  - `authorName` is FLATTENED from the hidden to-one `author` relation
 *    (`on('author')`, stored as the author's `name`): a read flattens `author.name`,
 *    a write mutates the loaded author in place;
 *  - `authorCountry` is FLATTENED over the MULTI-HOP chain `on('author.country')`
 *    (stored as the country's `name`): a read flattens `author.country.name` walking
 *    two to-one hops, a write mutates the loaded country in place, any intermediate
 *    null short-circuits to null / 422s on write;
 *  - `editorName` is FLATTENED from the VISIBLE to-one `editor` relation
 *    (`on('editor')`): because `editor` renders as a relationship it can carry linkage
 *    in a write body, so a same-body write (associate/switch `editor` + set
 *    `editorName`) witnesses that `on()` attributes hydrate AFTER relationships;
 *  - `display` is COMPUTED (`computedUsing()`): read-only, the value owned by the
 *    closure;
 *  - the multi-hop `on('author.country')` eager-loads each book's `author`, then each
 *    author's `country`, segment by segment (O(depth)); `country` is hidden and never
 *    renders;
 *  - `author` never renders as a relationship — it is `hidden()`, the idiomatic
 *    internal association backing a flattened attribute; `editor` DOES render (it is a
 *    visible backing relation).
 */
abstract class FlattenConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching')]
    public function aFlattenedAttributeReadsTheRelatedModelsMember(): void
    {
        // GET /books/1 — `authorName` flattens author 1's `name` ("Ada Lovelace"),
        // identical on both providers.
        $document = $this->getDocument('/books/1');

        $attributes = $this->attributes($document['data'] ?? null);
        self::assertSame('Algorithms', $attributes['title'] ?? null);
        self::assertSame('Ada Lovelace', $attributes['authorName'] ?? null, 'authorName flattens author.name');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFlattenedAttributeOverANullRelatedModelRendersNull(): void
    {
        // Book 4 has no author, so the flattened `authorName` is lenient -> null.
        $document = $this->getDocument('/books/4');

        $attributes = $this->attributes($document['data'] ?? null);
        self::assertArrayHasKey('authorName', $attributes);
        self::assertNull($attributes['authorName'], 'a null related model yields a null flattened value');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aComputedAttributeRendersTheClosureValue(): void
    {
        $document = $this->getDocument('/books/1');

        $attributes = $this->attributes($document['data'] ?? null);
        self::assertSame('Book: Algorithms', $attributes['display'] ?? null, 'the computedUsing closure owns the output');
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aHiddenBackingRelationDoesNotRenderAsARelationship(): void
    {
        // The `on()` backing `author` is not a relationship: it is hidden(), and the
        // eager set is never expanded into `included`. The VISIBLE `editor` (the
        // non-hidden backing for `editorName`) DOES render as a relationship — but
        // eager-loading it never expands it into `included`. So a plain read carries the
        // `editor` relationship only and NO included member, even though every backing
        // relation (and the multi-hop `author.country` chain) was eager-loaded.
        $document = $this->getDocument('/books/1');
        $book = $document['data'] ?? null;
        self::assertIsArray($book);

        $relationships = $book['relationships'] ?? [];
        self::assertIsArray($relationships);
        self::assertArrayNotHasKey('author', $relationships, 'the hidden on() backing relation is not a relationship');
        self::assertArrayHasKey('editor', $relationships, 'the visible on() backing relation renders as a relationship');

        self::assertArrayNotHasKey('included', $document, 'an eager-loaded relation is never expanded into included');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFlattenedAttributeOverAVisibleBackingRelationReadsTheRelatedModel(): void
    {
        // `editorName` flattens the VISIBLE `editor` relation (book 1's editor is
        // author 1, "Ada Lovelace"). The flattened read works the same whether the
        // backing relation is hidden or visible.
        $attributes = $this->attributes($this->getDocument('/books/1')['data'] ?? null);
        self::assertSame('Ada Lovelace', $attributes['editorName'] ?? null, 'editorName flattens editor.name');
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function aSameBodyCreateFlattensOntoTheAssociatedRelatedModel(): void
    {
        // The headline same-body guarantee on CREATE: a POST that associates `editor`
        // (author 2) AND sets the flattened `editorName` in ONE document must write the
        // value onto the editor associated in THAT body — `on()` attributes hydrate
        // AFTER relationships, so the freshly associated editor is visible. A 422
        // RELATED_ATTRIBUTE_OWNER_MISSING here would mean the relationship was applied
        // too late (the defect this case guards).
        $response = $this->handle(
            self::BASE_URI . '/books',
            'POST',
            [
                'data' => [
                    'type' => 'books',
                    'attributes' => ['title' => 'New Title', 'editorName' => 'Grace The Editor'],
                    'relationships' => [
                        'editor' => ['data' => ['type' => 'authors', 'id' => '2']],
                    ],
                ],
            ],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $created = $this->attributes($this->decode($response)['data'] ?? null);
        self::assertSame('Grace The Editor', $created['editorName'] ?? null, 'the create response reflects the same-body flattened write');

        // The flattened value landed on the associated editor (author 2), mutated in
        // place — re-fetching /authors/2 is the witness.
        $editor = $this->getDocument('/authors/2');
        self::assertSame('Grace The Editor', $this->attributes($editor['data'] ?? null)['name'] ?? null, 'the associated editor was updated in place');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aSameBodyUpdateFlattensOntoTheNewlyAssociatedRelatedModel(): void
    {
        // The headline same-body guarantee on UPDATE: a PATCH that SWITCHES `editor`
        // (book 1 starts editor = author 1, switched to author 2) AND sets `editorName`
        // in ONE document must write the value onto the NEW editor (author 2), leaving
        // the previously associated editor (author 1) untouched. Applying the
        // relationship after the flattened pass would corrupt the OLD owner instead
        // (the defect this case guards).
        $response = $this->handle(
            self::BASE_URI . '/books/1',
            'PATCH',
            [
                'data' => [
                    'type' => 'books',
                    'id' => '1',
                    'attributes' => ['editorName' => 'Renamed Editor'],
                    'relationships' => [
                        'editor' => ['data' => ['type' => 'authors', 'id' => '2']],
                    ],
                ],
            ],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $patched = $this->attributes($this->decode($response)['data'] ?? null);
        self::assertSame('Renamed Editor', $patched['editorName'] ?? null, 'the patch response reflects the same-body flattened write');

        // The NEW editor (author 2) got the value; the OLD editor (author 1) is untouched.
        $newEditor = $this->getDocument('/authors/2');
        self::assertSame('Renamed Editor', $this->attributes($newEditor['data'] ?? null)['name'] ?? null, 'the newly associated editor received the flattened value');

        $oldEditor = $this->getDocument('/authors/1');
        self::assertSame('Ada Lovelace', $this->attributes($oldEditor['data'] ?? null)['name'] ?? null, 'the previously associated editor is untouched');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aFlattenedAttributeIsConsistentAcrossACollectionRead(): void
    {
        // The eager batch loads every book's distinct author in one pass; each book's
        // `authorName` flattens its OWN author, no cross-row bleed.
        $document = $this->getDocument('/books?sort=title');

        $byTitle = [];
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $resource) {
            $attributes = $this->attributes($resource);
            $title = $attributes['title'] ?? null;
            self::assertIsString($title);
            $byTitle[$title] = $attributes['authorName'] ?? null;
        }

        self::assertSame('Ada Lovelace', $byTitle['Algorithms'] ?? null);
        self::assertSame('Grace Hopper', $byTitle['Compilers'] ?? null);
        self::assertSame('Edsger Dijkstra', $byTitle['Structured Programming'] ?? null);
        self::assertArrayHasKey('Orphan', $byTitle);
        self::assertNull($byTitle['Orphan'], 'the authorless book flattens to null');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedAttributeWriteMutatesTheRelatedModel(): void
    {
        // PATCH /books/1 setting `authorName` mutates the LOADED author (author 1) in
        // place — no relationship in the body. Re-fetching /authors/1 is the witness
        // that the change persisted (Doctrine UoW auto-persist of the dirty loaded
        // entity; in-memory shared reference).
        $response = $this->handle(
            self::BASE_URI . '/books/1',
            'PATCH',
            ['data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $patched = $this->attributes($this->decode($response)['data'] ?? null);
        self::assertSame('Ada King', $patched['authorName'] ?? null, 'the PATCH response reflects the flattened write');

        // The author itself was mutated, not the book: re-fetch /authors/1.
        $author = $this->getDocument('/authors/1');
        self::assertSame('Ada King', $this->attributes($author['data'] ?? null)['name'] ?? null, 'the related author was updated in place');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aComputedAttributeIsReadOnlyOnWrite(): void
    {
        // `display` is read-only (computedUsing): sending it is silently ignored, and
        // the response value still derives from the closure (it never wrote anything).
        $response = $this->handle(
            self::BASE_URI . '/books/2',
            'PATCH',
            ['data' => ['type' => 'books', 'id' => '2', 'attributes' => ['display' => 'HACKED']]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $attributes = $this->attributes($this->decode($response)['data'] ?? null);
        self::assertSame('Book: Compilers', $attributes['display'] ?? null, 'a computed attribute ignores a write and derives from the closure');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOverANullRelatedModelIs422(): void
    {
        // Book 4 has no author, so a flattened `authorName` write is the require-exists
        // 422 (RELATED_ATTRIBUTE_OWNER_MISSING) — a flattened attribute never
        // auto-instantiates the related model. The pointer is the attribute itself.
        $response = $this->handle(
            self::BASE_URI . '/books/4',
            'PATCH',
            ['data' => ['type' => 'books', 'id' => '4', 'attributes' => ['authorName' => 'Nobody']]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('RELATED_ATTRIBUTE_OWNER_MISSING', $error['code'] ?? null);
        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/authorName', $source['pointer'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aMultiHopFlattenedAttributeReadsTheFinalRelatedModel(): void
    {
        // `authorCountry` flattens the MULTI-HOP `on('author.country')`: the chain walks
        // book -> author -> country, reading the country's `name`. Both hops are
        // eager-loaded segment by segment, so it renders "Wonderland". Identical on both
        // providers.
        $attributes = $this->attributes($this->getDocument('/books/1')['data'] ?? null);
        self::assertSame('Wonderland', $attributes['authorCountry'] ?? null, 'the multi-hop on(author.country) reads the final country.name');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aMultiHopFlattenedAttributeShortCircuitsAnIntermediateNullToNull(): void
    {
        // Book 4 has no author, so the FIRST hop of `author.country` is null: the chain
        // short-circuits to a null flattened value (lenient read), no error.
        $attributes = $this->attributes($this->getDocument('/books/4')['data'] ?? null);
        self::assertArrayHasKey('authorCountry', $attributes);
        self::assertNull($attributes['authorCountry'], 'a null intermediate hop yields a null flattened value');
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aMultiHopFlattenedAttributeIsConsistentAcrossACollectionRead(): void
    {
        // The multi-hop walk batches both hops across the whole page: every authored
        // book flattens its author's country, and the authorless book short-circuits to
        // null — no cross-row bleed.
        $document = $this->getDocument('/books?sort=title');

        $byTitle = [];
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $resource) {
            $attributes = $this->attributes($resource);
            $title = $attributes['title'] ?? null;
            self::assertIsString($title);
            $byTitle[$title] = $attributes['authorCountry'] ?? null;
        }

        self::assertSame('Wonderland', $byTitle['Algorithms'] ?? null);
        self::assertSame('Wonderland', $byTitle['Compilers'] ?? null);
        self::assertSame('Wonderland', $byTitle['Structured Programming'] ?? null);
        self::assertArrayHasKey('Orphan', $byTitle);
        self::assertNull($byTitle['Orphan'], 'the authorless book short-circuits the multi-hop chain to null');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aMultiHopFlattenedWriteMutatesTheFinalRelatedModel(): void
    {
        // PATCH /books/1 setting `authorCountry` walks book -> author -> country and
        // mutates the FINAL related model (the loaded country) in place. The country is
        // SHARED by every author, so re-fetching another book's `authorCountry` is the
        // witness that the same country object was mutated.
        $response = $this->handle(
            self::BASE_URI . '/books/1',
            'PATCH',
            ['data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorCountry' => 'Oz']]],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $patched = $this->attributes($this->decode($response)['data'] ?? null);
        self::assertSame('Oz', $patched['authorCountry'] ?? null, 'the multi-hop PATCH response reflects the flattened write');

        // The shared country was mutated at the END of the chain: another book whose
        // author points at the same country now flattens "Oz" too.
        $other = $this->attributes($this->getDocument('/books/2')['data'] ?? null);
        self::assertSame('Oz', $other['authorCountry'] ?? null, 'the final related country was mutated in place (shared across the graph)');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aMultiHopFlattenedWriteOverANullIntermediateHopIs422(): void
    {
        // Book 4 has no author, so the FIRST hop of `author.country` is null: a write is
        // the require-exists 422 (RELATED_ATTRIBUTE_OWNER_MISSING) — a multi-hop
        // flattened attribute never auto-instantiates a missing hop. The pointer is the
        // attribute itself; the exception's relation is the FULL dot-path.
        $response = $this->handle(
            self::BASE_URI . '/books/4',
            'PATCH',
            ['data' => ['type' => 'books', 'id' => '4', 'attributes' => ['authorCountry' => 'Nowhere']]],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('RELATED_ATTRIBUTE_OWNER_MISSING', $error['code'] ?? null);
        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/authorCountry', $source['pointer'] ?? null);
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aMultiHopBackingChainDoesNotRenderAsRelationships(): void
    {
        // `author.country` is a hidden flatten chain: `author` (hidden on books) and
        // `country` (hidden on authors) are loaded but never rendered, and the chain is
        // NEVER expanded into `included` (neither `author` nor `country` appears as a
        // relationship of the book, and there is no included member).
        $document = $this->getDocument('/books/1');
        $book = $document['data'] ?? null;
        self::assertIsArray($book);

        $relationships = $book['relationships'] ?? [];
        self::assertIsArray($relationships);
        self::assertArrayNotHasKey('author', $relationships, 'the hidden chain root is not a relationship');
        self::assertArrayNotHasKey('country', $relationships, 'the second hop is not a relationship');
        self::assertArrayNotHasKey('included', $document, 'a hidden flatten chain is never expanded into included');
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param mixed $resource
     *
     * @return array<string, mixed>
     */
    protected function attributes(mixed $resource): array
    {
        self::assertIsArray($resource);
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
