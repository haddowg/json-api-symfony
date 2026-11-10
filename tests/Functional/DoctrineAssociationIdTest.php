<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\AssociationId\AssocCampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\AssociationId\AssocConfigEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\AssociationId\AssociationIdTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\AssociationId\AssocRunEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `configs` is keyed by a OneToOne association: its id is its campaign's id. Every
 * read through it, as the related type of a run or as the parent of its runs, answers
 * with the campaign id as the linkage id. Config 50 is scoped out by an extension, so
 * the run linkage that holds it goes through the id-IN probes. Doctrine-only: the in-memory provider has no
 * mapped identifier to reduce.
 */
#[Group('spec:crud')]
final class DoctrineAssociationIdTest extends JsonApiFunctionalTestCase
{
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /**
     * @var array<string, string> run name => wire id
     */
    private array $runs = [];

    protected static function getKernelClass(): string
    {
        return AssociationIdTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $configs = [];
        foreach ([10 => 'alpha', 20 => 'beta', 30 => 'gamma', 40 => 'delta', 50 => 'hidden'] as $id => $label) {
            $campaign = new AssocCampaignEntity($id, 'Campaign ' . $id);
            $config = new AssocConfigEntity($campaign);
            $config->label = $label;
            $entityManager->persist($campaign);
            $entityManager->persist($config);
            $configs[$id] = $config;
        }

        $seeded = [
            'first' => $this->seedRun('first', $configs[20], [$configs[10], $configs[20], $configs[50]]),
            'second' => $this->seedRun('second', null, [$configs[30], $configs[10]]),
            'third' => $this->seedRun('third', $configs[10], []),
        ];
        $configs[10]->owner = $seeded['second'];
        $configs[30]->owner = $seeded['second'];
        $configs[20]->owner = $seeded['first'];
        $configs[50]->owner = $seeded['first'];

        $entityManager->flush();
        foreach ($seeded as $name => $run) {
            $this->runs[$name] = (string) $run->id;
        }
        $entityManager->clear();
    }

    #[Test]
    public function aRunRendersItsConfigLinkage(): void
    {
        $data = $this->dataOf($this->get('/runs/' . $this->runs['first']));

        self::assertSame($this->config('20'), $this->linkage($data, 'primary'));
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('20')], $this->linkage($data, 'configs'));
    }

    #[Test]
    public function aRunCollectionRendersEveryLinkage(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?sort=name')));

        self::assertSame([$this->runs['first'], $this->runs['second'], $this->runs['third']], \array_column($rows, 'id'));
        self::assertSame($this->config('20'), $this->linkage($rows[0], 'primary'));
        self::assertNull($this->linkage($rows[1], 'primary'));
        self::assertSame($this->config('10'), $this->linkage($rows[2], 'primary'));
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('20')], $this->linkage($rows[0], 'configs'));
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('30')], $this->linkage($rows[1], 'configs'));
        self::assertSame([], $this->linkage($rows[2], 'configs'));
    }

    #[Test]
    public function anIncludeResolvesTheConfigs(): void
    {
        $document = $this->decode($this->get('/runs?sort=name&include=configs,primary'));

        $included = $this->includedKeys($document);
        \sort($included);
        self::assertSame(['configs:10', 'configs:20', 'configs:30'], $included);
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('30')], $this->linkage($this->rows($document)[1], 'configs'));
    }

    #[Test]
    public function relatedQueryWindowsTheConfigsOfEachRun(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?sort=name&include=configs&relatedQuery[configs][sort]=-label', self::QUERIES_ACCEPT)));

        self::assertSame([$this->config('20'), $this->config('10')], $this->linkage($rows[0], 'configs'));
        self::assertSame([$this->config('30'), $this->config('10')], $this->linkage($rows[1], 'configs'));
    }

    #[Test]
    public function anInverseToManyResolvesTheOwnedConfigs(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?sort=name&include=ownedConfigs')));
        self::assertSame([$this->config('20')], $this->linkage($rows[0], 'ownedConfigs'));
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('30')], $this->linkage($rows[1], 'ownedConfigs'));
        self::assertSame([], $this->linkage($rows[2], 'ownedConfigs'));

        $windowed = $this->rows($this->decode($this->get('/runs?sort=name&include=ownedConfigs&relatedQuery[ownedConfigs][sort]=-label', self::QUERIES_ACCEPT)));
        self::assertSame([$this->config('30'), $this->config('10')], $this->linkage($windowed[1], 'ownedConfigs'));

        $related = $this->rows($this->decode($this->get('/runs/' . $this->runs['second'] . '/ownedConfigs?sort=label')));
        self::assertSame(['10', '30'], \array_column($related, 'id'));

        $counted = $this->rows($this->decode($this->get('/runs?sort=name&withCount=ownedConfigs', self::COUNTS_ACCEPT)));
        self::assertSame([1, 2, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'ownedConfigs')['total'] ?? null, $counted));
    }

    #[Test]
    public function theOwnedConfigsPageByCursor(): void
    {
        $second = '/runs/' . $this->runs['second'] . '/ownedConfigs';

        $first = $this->decode($this->get($second . '?sort=-label&page[size]=1'));
        self::assertSame(['30'], \array_column($this->rows($first), 'id'));
        self::assertSame(['10'], \array_column($this->rows($this->decode($this->get($this->nextOf($first)))), 'id'));

        $unsorted = $this->decode($this->get($second . '?page[size]=1'));
        self::assertSame(['10'], \array_column($this->rows($unsorted), 'id'));
        self::assertSame(['30'], \array_column($this->rows($this->decode($this->get($this->nextOf($unsorted)))), 'id'));
    }

    #[Test]
    public function anUnscopedIncludeWindowsNatively(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?sort=name&include=openConfigs,openOwnedConfigs&relatedQuery[openConfigs][sort]=-label&relatedQuery[openOwnedConfigs][sort]=-label', self::QUERIES_ACCEPT)));

        self::assertSame([$this->openConfig('50'), $this->openConfig('20'), $this->openConfig('10')], $this->linkage($rows[0], 'openConfigs'));
        self::assertSame([$this->openConfig('30'), $this->openConfig('10')], $this->linkage($rows[1], 'openConfigs'));
        self::assertSame([$this->openConfig('50'), $this->openConfig('20')], $this->linkage($rows[0], 'openOwnedConfigs'));
        self::assertSame([$this->openConfig('30'), $this->openConfig('10')], $this->linkage($rows[1], 'openOwnedConfigs'));

        $unsorted = $this->rows($this->decode($this->get('/runs?sort=name&include=openConfigs,openOwnedConfigs')));
        self::assertSame([$this->openConfig('10'), $this->openConfig('20'), $this->openConfig('50')], $this->linkage($unsorted[0], 'openConfigs'));
        self::assertSame([$this->openConfig('20'), $this->openConfig('50')], $this->linkage($unsorted[0], 'openOwnedConfigs'));
    }

    #[Test]
    public function aRunIsFilteredThroughItsConfigs(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?filter[configLabel]=gamma')));

        self::assertSame([$this->runs['second']], \array_column($rows, 'id'));
    }

    #[Test]
    public function aRunIsFilteredByTheCampaignIdsOfItsConfigs(): void
    {
        $ids = fn(string $query): array => \array_column($this->rows($this->decode($this->get('/runs?sort=name&' . $query))), 'id');

        self::assertSame([$this->runs['first'], $this->runs['second']], $ids('filter[config]=20,30'));
        self::assertSame([$this->runs['first'], $this->runs['third']], $ids('filter[primaryIn]=10,20'));
        self::assertSame([$this->runs['third']], $ids('filter[primaryIs]=10'));
        self::assertSame(400, $this->handle('/runs?filter[config]=10,campaign')->getStatusCode());
    }

    #[Test]
    public function theRelatedEndpointsResolveTheConfigs(): void
    {
        $configs = $this->rows($this->decode($this->get('/runs/' . $this->runs['first'] . '/configs?sort=label')));
        self::assertSame(['10', '20'], \array_column($configs, 'id'));

        $primary = $this->dataOf($this->get('/runs/' . $this->runs['first'] . '/primary'));
        self::assertSame('20', $primary['id'] ?? null);
    }

    #[Test]
    public function theRelationshipEndpointsRenderConfigLinkage(): void
    {
        $first = '/runs/' . $this->runs['first'];

        self::assertSame($this->config('20'), $this->decode($this->get($first . '/relationships/primary'))['data'] ?? null);
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('20')], $this->rows($this->decode($this->get($first . '/relationships/configs'))));
    }

    #[Test]
    public function theToManyRelationshipEndpointFiltersSortsAndPages(): void
    {
        $first = '/runs/' . $this->runs['first'];

        self::assertSame([$this->config('20'), $this->config('10')], $this->rows($this->decode($this->get($first . '/relationships/configs?sort=-label'))));
        self::assertSame([$this->config('20')], $this->rows($this->decode($this->get($first . '/relationships/configs?filter[label]=beta'))));

        $paged = $this->decode($this->get($first . '/relationships/configs?sort=label&page[size]=1&page[number]=2'));
        self::assertSame([$this->config('20')], $this->rows($paged));
    }

    #[Test]
    public function withCountCountsConfigsPerRun(): void
    {
        $rows = $this->rows($this->decode($this->get('/runs?sort=name&withCount=configs', self::COUNTS_ACCEPT)));

        self::assertSame([2, 2, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'configs')['total'] ?? null, $rows));
    }

    #[Test]
    public function aConfigIsFetchedByItsCampaignId(): void
    {
        $data = $this->dataOf($this->get('/configs/10'));

        self::assertSame('10', $data['id'] ?? null);
        self::assertEqualsCanonicalizing([$this->runRef('first'), $this->runRef('second')], $this->linkage($data, 'runs'));

        self::assertSame(404, $this->handle('/configs/99')->getStatusCode());
        self::assertSame(404, $this->handle('/configs/50')->getStatusCode());
    }

    #[Test]
    public function theConfigCollectionSortsAndFilters(): void
    {
        $rows = $this->rows($this->decode($this->get('/configs?sort=label')));
        self::assertSame(['10', '20', '40', '30'], \array_column($rows, 'id'));
        self::assertSame([], $this->linkage($rows[2], 'runs'));
        self::assertSame([$this->runRef('second')], $this->linkage($rows[3], 'runs'));

        $filtered = $this->rows($this->decode($this->get('/configs?filter[label]=gamma')));
        self::assertSame(['30'], \array_column($filtered, 'id'));

        $through = $this->rows($this->decode($this->get('/configs?sort=label&filter[runName]=second')));
        self::assertSame(['10', '30'], \array_column($through, 'id'));

        $byId = $this->rows($this->decode($this->get('/configs?sort=label&filter[id]=30,40')));
        self::assertSame(['40', '30'], \array_column($byId, 'id'));

        $paged = $this->rows($this->decode($this->get('/configs?sort=-label&page[size]=2&page[number]=2')));
        self::assertSame(['20', '10'], \array_column($paged, 'id'));
    }

    #[Test]
    public function aConfigIsTheParentOfItsRuns(): void
    {
        $runs = $this->rows($this->decode($this->get('/configs/10/runs?sort=-name')));
        self::assertSame([$this->runs['second'], $this->runs['first']], \array_column($runs, 'id'));

        $linkage = $this->rows($this->decode($this->get('/configs/10/relationships/runs?sort=name&page[size]=1')));
        self::assertSame([$this->runRef('first')], $linkage);

        $document = $this->decode($this->get('/configs?sort=label&include=runs'));
        $included = $this->includedKeys($document);
        \sort($included);
        $expected = ['runs:' . $this->runs['first'], 'runs:' . $this->runs['second']];
        \sort($expected);
        self::assertSame($expected, $included);

        $windowed = $this->rows($this->decode($this->get('/configs?sort=label&include=runs&relatedQuery[runs][sort]=-name', self::QUERIES_ACCEPT)));
        self::assertSame([$this->runRef('second'), $this->runRef('first')], $this->linkage($windowed[0], 'runs'));

        $counted = $this->rows($this->decode($this->get('/configs?sort=label&withCount=runs', self::COUNTS_ACCEPT)));
        self::assertSame([2, 1, 0, 1], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'runs')['total'] ?? null, $counted));
    }

    #[Test]
    public function aRunIsWrittenWithConfigLinkage(): void
    {
        $response = $this->handle('/runs', 'POST', ['data' => [
            'type' => 'runs',
            'attributes' => ['name' => 'fourth'],
            'relationships' => [
                'primary' => ['data' => $this->config('30')],
                'configs' => ['data' => [$this->config('30'), $this->config('40')]],
            ],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->dataOf($response)['id'] ?? null;
        self::assertIsString($id);

        $this->entityManager()->clear();
        $data = $this->dataOf($this->get('/runs/' . $id));
        self::assertSame($this->config('30'), $this->linkage($data, 'primary'));
        self::assertEqualsCanonicalizing([$this->config('30'), $this->config('40')], $this->linkage($data, 'configs'));

        $unknown = $this->handle('/runs', 'POST', ['data' => [
            'type' => 'runs',
            'attributes' => ['name' => 'fifth'],
            'relationships' => ['configs' => ['data' => [$this->config('99')]]],
        ]]);
        self::assertSame(404, $unknown->getStatusCode(), (string) $unknown->getContent());

        $hidden = $this->handle('/runs', 'POST', ['data' => [
            'type' => 'runs',
            'attributes' => ['name' => 'sixth'],
            'relationships' => ['configs' => ['data' => [$this->config('50')]]],
        ]]);
        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
    }

    #[Test]
    public function theRelationshipEndpointsWriteConfigLinkage(): void
    {
        $second = '/runs/' . $this->runs['second'];

        $response = $this->handle($second . '/relationships/primary', 'PATCH', ['data' => $this->config('40')]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle($second . '/relationships/configs', 'POST', ['data' => [$this->config('20')]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle($second . '/relationships/configs', 'DELETE', ['data' => [$this->config('30')]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        $data = $this->dataOf($this->get($second));
        self::assertSame($this->config('40'), $this->linkage($data, 'primary'));
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('20')], $this->linkage($data, 'configs'));

        $response = $this->handle($second . '/relationships/configs', 'PATCH', ['data' => [$this->config('40')]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        self::assertSame([$this->config('40')], $this->linkage($this->dataOf($this->get($second)), 'configs'));

        $third = '/runs/' . $this->runs['third'];
        $response = $this->handle($third . '/relationships/ownedConfigs', 'PATCH', ['data' => [$this->config('40'), $this->config('10')]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        self::assertEqualsCanonicalizing([$this->config('10'), $this->config('40')], $this->linkage($this->dataOf($this->get($third)), 'ownedConfigs'));
        self::assertSame([$this->config('30')], $this->linkage($this->dataOf($this->get($second)), 'ownedConfigs'));
    }

    #[Test]
    public function aConfigIsUpdatedAndDeletedByItsCampaignId(): void
    {
        $response = $this->handle('/configs/40', 'PATCH', ['data' => [
            'type' => 'configs',
            'id' => '40',
            'attributes' => ['label' => 'epsilon'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('40', $this->dataOf($response)['id'] ?? null);

        self::assertSame(204, $this->handle('/configs/40', 'DELETE')->getStatusCode());
        self::assertSame(404, $this->handle('/configs/40')->getStatusCode());
    }

    /**
     * @return array{type: string, id: string}
     */
    private function config(string $id): array
    {
        return ['type' => 'configs', 'id' => $id];
    }

    /**
     * @return array{type: string, id: string}
     */
    private function openConfig(string $id): array
    {
        return ['type' => 'open-configs', 'id' => $id];
    }

    /**
     * @return array{type: string, id: string}
     */
    private function runRef(string $name): array
    {
        return ['type' => 'runs', 'id' => $this->runs[$name]];
    }

    /**
     * @param list<AssocConfigEntity> $configs
     */
    private function seedRun(string $name, ?AssocConfigEntity $primary, array $configs): AssocRunEntity
    {
        $run = new AssocRunEntity();
        $run->name = $name;
        $run->primary = $primary;
        foreach ($configs as $config) {
            $run->configs->add($config);
        }
        $this->entityManager()->persist($run);

        return $run;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function nextOf(array $document): string
    {
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        $next = $links['next'] ?? null;
        self::assertIsArray($next);
        $href = $next['href'] ?? null;
        self::assertIsString($href);

        return \urldecode(\substr($href, \strlen('https://example.test')));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function get(string $path, string $accept = 'application/vnd.api+json'): Response
    {
        $response = $this->handle($path, 'GET', null, ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $document): array
    {
        $rows = $document['data'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function linkage(array $resource, string $relation): mixed
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        self::assertArrayHasKey('data', $object, \sprintf('the %s relationship renders data', $relation));

        return $object['data'];
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipMeta(array $resource, string $relation): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        $meta = $object['meta'] ?? [];
        self::assertIsArray($meta);

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedKeys(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $keys = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $keys[] = \sprintf('%s:%s', \is_string($resource['type'] ?? null) ? $resource['type'] : '', \is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }

        return $keys;
    }
}
