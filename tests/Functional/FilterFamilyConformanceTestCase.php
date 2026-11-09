<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * List filters and filter families on both data layers, over the six items of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\ItemFixtures}.
 *
 * Items 1 to 4 sit on board 1 and items 5 and 6 on board 2. Names run apple, banana,
 * cherry, damson, elder, fig; colors red, yellow, red, purple, purple, green; sizes
 * small, large, large, small, small, large; labels `a,b`, `a`, `b`, `c,d`, `a,b`, `d`.
 */
#[Group('spec:fetching-filtering')]
abstract class FilterFamilyConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function aListFilterTakesTheArrayForm(): void
    {
        self::assertSame(['1', '3'], $this->ids('/ffItems?filter[name][]=apple&filter[name][]=cherry'));
    }

    #[Test]
    public function aDelimitedListFilterTakesTheCommaForm(): void
    {
        self::assertSame(['1', '3'], $this->ids('/ffItems?filter[name]=apple,cherry'));
        self::assertSame(['2', '6'], $this->ids('/ffItems?filter[name]=banana,%20fig'));
    }

    #[Test]
    public function anArrayOnlyListKeepsACommaInsideAMember(): void
    {
        self::assertSame(['1', '5'], $this->ids('/ffItems?filter[labels][]=a,b'));
        self::assertSame(['1', '5', '6'], $this->ids('/ffItems?filter[labels][]=a,b&filter[labels][]=d'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anArrayOnlyListGivenAStringIsA400(): void
    {
        $this->assertBadFilter($this->handle('/ffItems?filter[labels]=a,b'), 'FILTER_VALUE_INVALID', 'filter[labels]');
    }

    #[Test]
    #[Group('spec:errors')]
    public function aNamedListMemberIsA400(): void
    {
        $this->assertBadFilter($this->handle('/ffItems?filter[name][x]=apple'), 'FILTER_VALUE_INVALID', 'filter[name]');
    }

    #[Test]
    #[Group('spec:errors')]
    public function eachListMemberIsValidated(): void
    {
        $this->assertBadFilter($this->handle('/ffItems?filter[weight][]=1&filter[weight][]=heavy'), 'FILTER_VALUE_INVALID', 'filter[weight]');
        $this->assertBadFilter($this->handle('/ffItems?filter[weight]=1,heavy'), 'FILTER_VALUE_INVALID', 'filter[weight]');
        self::assertSame(['2', '4'], $this->ids('/ffItems?filter[weight][]=2&filter[weight][]=4'));
    }

    #[Test]
    public function aFamilyResolvesAScalarFilter(): void
    {
        self::assertSame(['1', '3'], $this->ids('/ffItems?filter[attr.color]=red'));
        self::assertSame(['3'], $this->ids('/ffItems?filter[attr.color]=red&filter[attr.size]=large'));
    }

    #[Test]
    public function aFamilyResolvesAListFilter(): void
    {
        self::assertSame(['1', '3', '6'], $this->ids('/ffItems?filter[any.color][]=red&filter[any.color][]=green'));
        self::assertSame(['1', '4', '5'], $this->ids('/ffItems?filter[any.size]=small'));
        self::assertSame(['1', '3', '4', '5'], $this->ids('/ffItems?filter[any.color]=red,purple'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function aFamilyKeyIsValidatedAgainstTheResolvedFilter(): void
    {
        $this->assertBadFilter($this->handle('/ffItems?filter[any.weight][]=1&filter[any.weight][]=heavy'), 'FILTER_VALUE_INVALID', 'filter[any.weight]');
        self::assertSame(['5', '6'], $this->ids('/ffItems?filter[any.weight]=5,6'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function aSuffixTheFamilyRejectsIsA400(): void
    {
        foreach (['attr.weight', 'attr.Color', 'any.label'] as $key) {
            $this->assertBadFilter($this->handle('/ffItems?filter[' . $key . ']=x'), 'FILTERING_UNRECOGNIZED', 'filter[' . $key . ']');
        }
    }

    #[Test]
    #[Group('spec:errors')]
    public function aBarePrefixIsA400(): void
    {
        $this->assertBadFilter($this->handle('/ffItems?filter[attr]=red'), 'FILTERING_UNRECOGNIZED', 'filter[attr]');
        $this->assertBadFilter($this->handle('/ffItems?filter[any]=red'), 'FILTERING_UNRECOGNIZED', 'filter[any]');
    }

    #[Test]
    public function anExactKeyWinsOverAFamily(): void
    {
        // The `attr` family would reject `shade`; the exact filter answers it.
        self::assertSame(['4', '5'], $this->ids('/ffItems?filter[attr.shade]=purple'));
    }

    #[Test]
    public function aRelatedEndpointTakesAFamilyAndAListFilter(): void
    {
        self::assertSame(['1', '3', '4'], $this->ids('/ffBoards/1/items?filter[any.color][]=red&filter[any.color][]=purple'));
        self::assertSame(['1'], $this->ids('/ffBoards/1/items?filter[labels][]=a,b'));
        self::assertSame(['2', '3'], $this->ids('/ffBoards/1/items?filter[attr.size]=large'));
    }

    #[Test]
    public function theRelationsExactFilterSitsBesideTheFamilyOfTheSameKey(): void
    {
        self::assertSame(['4'], $this->ids('/ffBoards/1/items?filter[any]=purple'));
        self::assertSame(['4'], $this->ids('/ffBoards/1/items?filter[any.color]=purple'));
    }

    #[Test]
    public function aRelationshipEndpointTakesAFamilyAndAListFilter(): void
    {
        self::assertSame(['2', '3'], $this->ids('/ffBoards/1/relationships/items?filter[attr.size]=large'));
        self::assertSame(['1', '4'], $this->ids('/ffBoards/1/relationships/items?filter[name]=apple,damson'));
        self::assertSame(['5'], $this->ids('/ffBoards/2/relationships/items?filter[any.size][]=small'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownFamilyKeyOnARelatedEndpointIsA400(): void
    {
        $this->assertBadFilter($this->handle('/ffBoards/1/items?filter[attr.weight]=1'), 'FILTERING_UNRECOGNIZED', 'filter[attr.weight]');
        $this->assertBadFilter($this->handle('/ffBoards/1/relationships/items?filter[labels]=a,b'), 'FILTER_VALUE_INVALID', 'filter[labels]');
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelatedQueryFilterTakesAFamily(): void
    {
        $document = $this->profileDocument('/ffBoards/1?include=items&relatedQuery[items][filter][attr.color]=red');

        self::assertSame(['1', '3'], $this->linkage($document));
        self::assertSame(['1', '3'], $this->includedIds($document));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelatedQueryFilterTakesAListFamily(): void
    {
        $document = $this->profileDocument('/ffBoards?include=items&relatedQuery[items][filter][any.color][]=purple&relatedQuery[items][filter][any.color][]=green');

        self::assertSame(['4', '5', '6'], $this->includedIds($document));
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:errors')]
    public function aRelatedQueryFilterRejectsAnUnknownFamilyKey(): void
    {
        $response = $this->handle('/ffBoards/1?include=items&relatedQuery[items][filter][attr.weight]=1', extraServer: $this->profileHeader());
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('FILTERING_UNRECOGNIZED', $this->firstError($response)['code'] ?? null);

        $response = $this->handle('/ffBoards/1?include=items&relatedQuery[items][filter][any.weight][]=heavy', extraServer: $this->profileHeader());
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('FILTER_VALUE_INVALID', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:actions')]
    public function aCollectionActionTakesAListFilterAndAFamily(): void
    {
        self::assertSame(['1', '3'], $this->actionIds('/ffItems/-actions/tally?filter[name][]=apple&filter[name][]=cherry'));
        self::assertSame(['1', '5'], $this->actionIds('/ffItems/-actions/tally?filter[labels][]=a,b'));
        self::assertSame(['3'], $this->actionIds('/ffItems/-actions/tally?filter[attr.color]=red&filter[any.size]=large'));
        self::assertSame(['4', '5'], $this->actionIds('/ffItems/-actions/tally?filter[attr.shade]=purple'));
    }

    #[Test]
    #[Group('spec:actions')]
    #[Group('spec:errors')]
    public function aCollectionActionRejectsWhatTheCollectionRejects(): void
    {
        $this->assertBadFilter($this->handle('/ffItems/-actions/tally?filter[labels]=a,b', 'POST'), 'FILTER_VALUE_INVALID', 'filter[labels]');
        $this->assertBadFilter($this->handle('/ffItems/-actions/tally?filter[attr.weight]=1', 'POST'), 'FILTERING_UNRECOGNIZED', 'filter[attr.weight]');
        $this->assertBadFilter($this->handle('/ffItems/-actions/tally?filter[any.weight]=heavy', 'POST'), 'FILTER_VALUE_INVALID', 'filter[any.weight]');
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionListingAFamilyTakesItsKeysOnly(): void
    {
        self::assertSame(['1', '3', '6'], $this->actionIds('/ffItems/-actions/pick?filter[any.color]=red,green'));
        $this->assertBadFilter($this->handle('/ffItems/-actions/pick?filter[name]=apple', 'POST'), 'FILTERING_UNRECOGNIZED', 'filter[name]');
        $this->assertBadFilter($this->handle('/ffItems/-actions/pick?filter[attr.color]=red', 'POST'), 'FILTERING_UNRECOGNIZED', 'filter[attr.color]');
    }

    /**
     * The ids of a response's primary data, sorted, since neither store orders them.
     *
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        return $this->sortedIds($data);
    }

    /**
     * @return list<string>
     */
    private function actionIds(string $path): array
    {
        $response = $this->handle($path, 'POST');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        $ids = $meta['ids'] ?? null;
        self::assertIsArray($ids);

        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', \array_values($ids));
        \sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileDocument(string $path): array
    {
        $response = $this->handle($path, extraServer: $this->profileHeader());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<string, string>
     */
    private function profileHeader(): array
    {
        return ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function linkage(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $items = $relationships['items'] ?? null;
        self::assertIsArray($items);
        $linkage = $items['data'] ?? null;
        self::assertIsArray($linkage);

        return $this->sortedIds($linkage);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        return $this->sortedIds($included);
    }

    /**
     * @param array<mixed> $resources
     *
     * @return list<string>
     */
    private function sortedIds(array $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            self::assertSame('ffItems', $resource['type'] ?? null);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }
        \sort($ids);

        return $ids;
    }

    private function assertBadFilter(Response $response, string $code, string $parameter): void
    {
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($response);
        self::assertSame($code, $error['code'] ?? null, (string) $response->getContent());
        self::assertSame(['parameter' => $parameter], $error['source'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function firstError(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        return $error;
    }
}
