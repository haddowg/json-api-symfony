<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\FieldSelectionFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BasePanelResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The field-selection conformance witness, run against both providers: a
 * `fields[TYPE]=<attribute>.<key>` member renders only that key of an attribute declaring
 * selectable keys, wherever the type renders, and the response applies and advertises
 * the Field Selection profile only when it narrows something.
 */
abstract class FieldSelectionConformanceTestCase extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;

    private const string NARROWED = 'application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"';

    protected function setUp(): void
    {
        BasePanelResource::$summaryRequests = [];

        parent::setUp();
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aKeyMemberRendersOnlyThatKey(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=name,values.cap,values.countries');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['name' => 'Seed', 'values' => ['cap' => '10', 'countries' => 'GB,FR']], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aCollectionRendersTheSelectionForEveryResource(): void
    {
        $response = $this->handle('/panels?fields[panels]=values.region');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data[0]);
        self::assertSame(['values' => ['region' => 'EU']], $data[0]['attributes'] ?? null);
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function listingTheWholeAttributeBesideAKeyRendersAllOfIt(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=values,values.cap');

        self::assertSame(['values' => FieldSelectionFactory::SEED_VALUES], $this->attributesOf($response));
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:extensions-and-profiles')]
    public function noFieldsetLeavesTheResponseUnchanged(): void
    {
        $response = $this->handle('/panels/1');

        self::assertSame(FieldSelectionFactory::SEED_VALUES, $this->attributesOf($response)['values'] ?? null);
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertArrayNotHasKey('profile', $this->jsonApiOf($response));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function aFieldsetNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=name,values');

        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertArrayNotHasKey('profile', $this->jsonApiOf($response));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function aNarrowedResponseAdvertisesTheProfileWithoutTheClientAskingForIt(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=values.cap');

        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
        self::assertSame([FieldSelectionProfile::URI], $this->jsonApiOf($response)['profile'] ?? null);
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function anExtractHookReceivesTheSelectedKeys(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=summary.count');

        self::assertSame(['summary' => ['count' => 2]], $this->attributesOf($response));
        self::assertSame([['count']], BasePanelResource::$summaryRequests);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function anExtractHookReceivesNullWhenTheAttributeIsTakenWhole(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=summary');

        self::assertSame(['summary' => ['count' => 2, 'width' => 40]], $this->attributesOf($response));
        self::assertSame([null], BasePanelResource::$summaryRequests);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aMapReadByAModelTypedHookIsSelected(): void
    {
        self::assertSame(['layout' => ['theme' => 'grid']], $this->attributesOf($this->handle('/panels/1?fields[panels]=layout.theme')));
        self::assertSame(['layout' => ['region' => 'EU', 'theme' => 'grid']], $this->attributesOf($this->handle('/panels/1?fields[panels]=layout')));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        // `layout` requires both its keys in the schema, so the narrowed value only
        // validates once the testing kit narrows the attribute's schema too.
        $response = $this->handle('/panels/1?fields[panels]=name,layout.theme,values.cap');
        self::assertSame(['theme' => 'grid'], $this->attributesOf($response)['layout'] ?? null);
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single, fieldsets: ['panels' => 'name,layout.theme,values.cap']);

        $collection = $this->handle('/panels?fields[panels]=layout.region');
        $this->assertResponseMatchesGeneratedSchema($collection, 'panels', SchemaDocumentKind::Collection, fieldsets: ['panels' => 'layout.region']);

        $whole = $this->handle('/panels/1?fields[panels]=layout,layout.theme');
        $this->assertResponseMatchesGeneratedSchema($whole, 'panels', SchemaDocumentKind::Single, fieldsets: ['panels' => 'layout,layout.theme']);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aRepeatingGroupIsSelectedAsOneKey(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=groups.rows');

        self::assertSame(['groups' => ['rows' => [['a' => 1], ['a' => 2]]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function aSelectionAppliesToIncludedResources(): void
    {
        $response = $this->handle('/panels/1?include=curator&fields[panels]=name,curator&fields[curators]=stats.rating');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertIsArray($included[0]);
        self::assertSame(['stats' => ['rating' => 5]], $included[0]['attributes'] ?? null);
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aSelectionAppliesToARelatedEndpoint(): void
    {
        $response = $this->handle('/panels/1/curator?fields[curators]=stats.reviews');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['stats' => ['reviews' => 12]], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedKeyMemberRendersOnlyItsPath(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=address.geo.lat');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['address' => ['geo' => ['lat' => 51.5]]], $this->attributesOf($response));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedPathSelectsNothingElseOfItsAncestors(): void
    {
        $response = $this->handle('/panels/1?fields[panels]=name,address.postcode,address.geo.lng');

        self::assertSame(['name' => 'Seed', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lng' => -0.1]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aKeyListedWholeBesideAPathIntoItRendersWholeAtEveryDepth(): void
    {
        self::assertSame(
            ['address' => ['geo' => FieldSelectionFactory::SEED_ADDRESS['geo']]],
            $this->attributesOf($this->handle('/panels/1?fields[panels]=address.geo.lat,address.geo')),
        );

        $whole = $this->handle('/panels/1?fields[panels]=address,address.geo.lat');
        self::assertSame(['address' => FieldSelectionFactory::SEED_ADDRESS], $this->attributesOf($whole));
        self::assertSame('application/vnd.api+json', $whole->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:includes')]
    public function aNestedSelectionAppliesToIncludedResources(): void
    {
        $response = $this->handle('/panels/1?include=curator&fields[panels]=curator&fields[curators]=office.geo.lat');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertIsArray($included[0]);
        self::assertSame(['office' => ['geo' => ['lat' => 48.8]]], $included[0]['attributes'] ?? null);
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function aNestedSelectionAppliesToARelatedEndpoint(): void
    {
        $response = $this->handle('/panels/1/curator?fields[curators]=office.city,office.geo.lng');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['office' => ['city' => 'Paris', 'geo' => ['lng' => 2.3]]], $this->attributesOf($response));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function aNestedNarrowedResponseMatchesTheGeneratedSchema(): void
    {
        $fields = 'name,address.postcode,address.geo.lat';
        $response = $this->handle('/panels/1?fields[panels]=' . $fields);

        self::assertSame(['name' => 'Seed', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5]]], $this->attributesOf($response));
        $this->assertResponseMatchesGeneratedSchema($response, 'panels', SchemaDocumentKind::Single, fieldsets: ['panels' => $fields]);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('openapi')]
    public function theFieldsParameterDocumentsEachNestedPath(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        \assert($factory instanceof DocumentFactory);
        $paths = \json_encode($factory->forServer()->toArray()['paths'] ?? [], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        // Encoded as JSON, so each pattern's backslash is doubled.
        self::assertStringContainsString('"pattern":"^address\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringContainsString('"pattern":"^address\\\\.geo\\\\.(?:[a-z]+)$"', $paths);
        self::assertStringNotContainsString('^address\\\\.history\\\\.', $paths);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aNestedKeyTheObjectRejectsIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=address.geo.Alt', 'address.geo.Alt');
        $this->assertUnrecognized('/panels/1/curator?fields[curators]=office.geo.alt', 'office.geo.alt');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aPathBelowAKeyThatIsNotSelectableIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=address.postcode.x', 'address.postcode.x');
        $this->assertUnrecognized('/panels/1?fields[panels]=address.history.street', 'address.history.street');
        $this->assertUnrecognized('/panels/1?fields[panels]=values.cap.x', 'values.cap.x');
        $this->assertUnrecognized('/panels/1?fields[panels]=address..lat', 'address..lat');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    public function aSelectionAppliesToAtomicResults(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New', 'values' => ['cap' => '7', 'region' => 'NA']]],
        ]], 'fields[panels]=values.cap');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        self::assertIsArray($results[0]);
        $data = $results[0]['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['values' => ['cap' => '7']], $data['attributes'] ?? null);
        self::assertStringContainsString('profile="' . FieldSelectionProfile::URI . '"', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    public function anAtomicBatchNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New', 'values' => ['cap' => '7']]],
        ]], 'fields[panels]=name');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        self::assertIsArray($results[0]);
        $data = $results[0]['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['name' => 'New'], $data['attributes'] ?? null);
        self::assertStringNotContainsString('profile=', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:crud')]
    public function aWriteStoresTheWholeValueAndAnswersWithTheSelection(): void
    {
        $values = ['cap' => '20', 'countries' => 'DE', 'region' => 'EU'];
        $response = $this->handle('/panels/1?fields[panels]=values.cap', 'PATCH', ['data' => [
            'type' => 'panels',
            'id' => '1',
            'attributes' => ['values' => $values],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['values' => ['cap' => '20']], $this->attributesOf($response));

        $this->detachPersistedState();
        self::assertSame($values, $this->attributesOf($this->handle('/panels/1'))['values'] ?? null);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aKeyThePatternRejectsIsTheUnrecognizedFieldsetMember400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=values.Cap', 'values.Cap');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aKeyThePredicateRejectsIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?include=curator&fields[curators]=stats.secret', 'stats.secret');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDottedMemberOnAnAttributeWithoutSelectableKeysIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=settings.theme', 'settings.theme');
        $this->assertUnrecognized('/panels/1?fields[panels]=name.first', 'name.first');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDottedMemberOnARelationshipIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=curator.name', 'curator.name');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:errors')]
    public function aDeeperPathOrAnEmptyKeyIsThe400(): void
    {
        $this->assertUnrecognized('/panels/1?fields[panels]=groups.rows.a', 'groups.rows.a');
        $this->assertUnrecognized('/panels/1?fields[panels]=values.', 'values.');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    #[Group('spec:atomic')]
    #[Group('spec:errors')]
    public function anAtomicBatchWithARejectedKeyIsThe400(): void
    {
        $response = $this->atomic([[
            'op' => 'add',
            'data' => ['type' => 'panels', 'attributes' => ['name' => 'New']],
        ]], 'fields[panels]=values.Bad');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $errors[0]['code'] ?? null);
    }

    /**
     * Clears whatever the data layer keeps between requests, so the next read loads from
     * the store.
     */
    protected function detachPersistedState(): void {}

    private function assertUnrecognized(string $path, string $member): void
    {
        $response = $this->handle($path);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $errors[0]['code'] ?? null);
        self::assertSame(['parameter' => 'fields'], $errors[0]['source'] ?? null);
        self::assertIsString($errors[0]['detail'] ?? null);
        self::assertStringContainsString($member, $errors[0]['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getContent());
        $attributes = $data['attributes'] ?? [];
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApiOf(Response $response): array
    {
        $jsonApi = $this->decode($response)['jsonapi'] ?? [];
        self::assertIsArray($jsonApi);

        /** @var array<string, mixed> $jsonApi */
        return $jsonApi;
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations, string $query): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create(
            '/operations?' . $query,
            'POST',
            server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext],
            content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
