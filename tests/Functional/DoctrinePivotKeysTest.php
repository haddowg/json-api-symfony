<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PivotKeysTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PkCampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PkDefinitionEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PkPlaceholderTargetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PkTemplateEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys\PkTemplatePlaceholderEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * `campaign-templates` is keyed by a OneToOne association to its campaign and
 * `placeholder-definitions` by a binary UUID. They meet through a pivot entity with a
 * binary UUID id and a collection, both set by its constructor. Every pivot read runs
 * in both directions, so each side is the related type of one of them. Doctrine-only:
 * the in-memory provider holds no pivot entity.
 */
#[Group('spec:crud')]
final class DoctrinePivotKeysTest extends JsonApiFunctionalTestCase
{
    private const string FIRST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000001';

    private const string LAST_NAME = '01932f5e-1a2b-7c3d-8e4f-000000000002';

    private const string COMPANY = '01932f5e-1a2b-7c3d-8e4f-000000000003';

    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /**
     * @var array<string, string> target name => id
     */
    private array $targetIds = [];

    protected static function getKernelClass(): string
    {
        return PivotKeysTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $templates = [];
        foreach ([10 => 'alpha', 20 => 'beta', 30 => 'gamma'] as $id => $name) {
            $campaign = new PkCampaignEntity($id, 'Campaign ' . $id);
            $template = new PkTemplateEntity($campaign);
            $template->name = $name;
            $entityManager->persist($campaign);
            $entityManager->persist($template);
            $templates[$id] = $template;
        }

        $definitions = [];
        foreach ([self::FIRST_NAME => 'first-name', self::LAST_NAME => 'last-name', self::COMPANY => 'company'] as $id => $name) {
            $definition = new PkDefinitionEntity(Uuid::fromString($id));
            $definition->name = $name;
            $entityManager->persist($definition);
            $definitions[$id] = $definition;
        }

        $header = new PkPlaceholderTargetEntity('header');
        $footer = new PkPlaceholderTargetEntity('footer');
        $entityManager->persist($header);
        $entityManager->persist($footer);
        $this->targetIds = ['header' => $header->id->toRfc4122(), 'footer' => $footer->id->toRfc4122()];

        // first-name is shared: targeted on template 10, untargeted on template 20.
        $this->link($templates[10], $definitions[self::FIRST_NAME], 'Ann', [$header]);
        $this->link($templates[10], $definitions[self::LAST_NAME], null);
        $this->link($templates[20], $definitions[self::FIRST_NAME], 'Bob');
        $this->link($templates[20], $definitions[self::COMPANY], 'Acme', [$footer, $header]);

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function theRelatedEndpointReadsTemplatesThroughThePivot(): void
    {
        $rows = $this->rows($this->decode($this->get('/placeholder-definitions/' . self::FIRST_NAME . '/templates?sort=name')));

        self::assertSame(['10', '20'], \array_column($rows, 'id'));
        self::assertSame(['Ann', 'Bob'], \array_map(fn(array $row): mixed => $this->pivotOf($row)['defaultOverride'] ?? null, $rows));
    }

    #[Test]
    public function theRelatedEndpointSortsFiltersAndPagesByThePivot(): void
    {
        $base = '/placeholder-definitions/' . self::FIRST_NAME . '/templates';

        self::assertSame(['20', '10'], \array_column($this->rows($this->decode($this->get($base . '?sort=-defaultOverride'))), 'id'));
        self::assertSame(['20'], \array_column($this->rows($this->decode($this->get($base . '?filter[defaultOverride]=Bob'))), 'id'));

        $paged = $this->decode($this->get($base . '?sort=name&page[size]=1&page[number]=2&withCount=_self_', self::COUNTS_ACCEPT));
        self::assertSame(['20'], \array_column($this->rows($paged), 'id'));
        self::assertSame(2, $this->pageTotal($paged));
    }

    #[Test]
    public function theRelationshipEndpointRendersTemplateLinkage(): void
    {
        $document = $this->decode($this->get('/placeholder-definitions/' . self::FIRST_NAME . '/relationships/templates?sort=-defaultOverride'));

        self::assertSame([$this->template('20'), $this->template('10')], \array_map(
            static fn(array $row): array => ['type' => $row['type'] ?? null, 'id' => $row['id'] ?? null],
            $this->rows($document),
        ));
    }

    #[Test]
    public function aDefinitionRendersItsTemplateLinkage(): void
    {
        $data = $this->dataOf($this->get('/placeholder-definitions/' . self::FIRST_NAME));
        self::assertEqualsCanonicalizing([$this->template('10'), $this->template('20')], $this->identifiers($this->linkage($data, 'templates')));

        $rows = $this->rows($this->decode($this->get('/placeholder-definitions?sort=name')));
        self::assertSame(['company', 'first-name', 'last-name'], \array_map(fn(array $row): mixed => $this->attribute($row, 'name'), $rows));
        self::assertSame([$this->template('20')], $this->identifiers($this->linkage($rows[0], 'templates')));
        self::assertEqualsCanonicalizing([$this->template('10'), $this->template('20')], $this->identifiers($this->linkage($rows[1], 'templates')));
        self::assertSame([$this->template('10')], $this->identifiers($this->linkage($rows[2], 'templates')));
    }

    #[Test]
    public function anIncludeBatchesAndWindowsTheTemplates(): void
    {
        $document = $this->decode($this->get('/placeholder-definitions?sort=name&include=templates'));
        $included = $this->includedKeys($document);
        \sort($included);
        self::assertSame(['campaign-templates:10', 'campaign-templates:20'], $included);

        $windowed = $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&include=templates&relatedQuery[templates][sort]=-name', self::QUERIES_ACCEPT)));
        self::assertSame([$this->template('20'), $this->template('10')], $this->identifiers($this->linkage($windowed[1], 'templates')));

        $byPivot = $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&include=templates&relatedQuery[templates][sort]=defaultOverride', self::QUERIES_ACCEPT)));
        self::assertSame([$this->template('10'), $this->template('20')], $this->identifiers($this->linkage($byPivot[1], 'templates')));
    }

    #[Test]
    public function withCountCountsTemplatesPerDefinition(): void
    {
        $rows = $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&withCount=templates', self::COUNTS_ACCEPT)));

        self::assertSame([1, 2, 1], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'templates')['total'] ?? null, $rows));
    }

    #[Test]
    public function theForwardDirectionReadsDefinitionsThroughThePivot(): void
    {
        $rows = $this->rows($this->decode($this->get('/campaign-templates/20/placeholders?sort=name')));
        self::assertSame([self::COMPANY, self::FIRST_NAME], \array_column($rows, 'id'));

        $templates = $this->rows($this->decode($this->get('/campaign-templates?sort=name&include=placeholders&withCount=placeholders', self::COUNTS_ACCEPT)));
        self::assertSame([2, 2, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'placeholders')['total'] ?? null, $templates));
    }

    #[Test]
    public function addingAMemberBuildsThePivotRowThroughItsConstructor(): void
    {
        $response = $this->handle('/campaign-templates/30/relationships/placeholders', 'POST', ['data' => [
            ['type' => 'placeholder-definitions', 'id' => self::COMPANY, 'meta' => ['pivot' => ['defaultOverride' => 'Initech']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $response = $this->handle('/placeholder-definitions/' . self::LAST_NAME . '/relationships/templates', 'POST', ['data' => [
            ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'Smith']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        $rows = $this->rows($this->decode($this->get('/campaign-templates/30/placeholders?sort=name')));
        self::assertSame([self::COMPANY, self::LAST_NAME], \array_column($rows, 'id'));
        self::assertSame(['Initech', 'Smith'], \array_map(fn(array $row): mixed => $this->pivotOf($row)['defaultOverride'] ?? null, $rows));

        foreach ($this->pivotRowsOf(30) as $row) {
            self::assertInstanceOf(UuidV7::class, $row->id, 'the constructor mints a UUIDv7 id');
            self::assertCount(0, $row->audiences);
        }
    }

    #[Test]
    public function theInverseSideReplacesAndRemovesMembers(): void
    {
        $base = '/placeholder-definitions/' . self::FIRST_NAME . '/relationships/templates';

        $response = $this->handle($base, 'PATCH', ['data' => [
            ['type' => 'campaign-templates', 'id' => '20', 'meta' => ['pivot' => ['defaultOverride' => 'Bobby']]],
            ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'Cat']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        $rows = $this->rows($this->decode($this->get('/placeholder-definitions/' . self::FIRST_NAME . '/templates?sort=name')));
        self::assertSame(['20', '30'], \array_column($rows, 'id'));
        self::assertSame(['Bobby', 'Cat'], \array_map(fn(array $row): mixed => $this->pivotOf($row)['defaultOverride'] ?? null, $rows));

        $response = $this->handle($base, 'DELETE', ['data' => [['type' => 'campaign-templates', 'id' => '20']]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $this->entityManager()->clear();
        self::assertSame(['30'], \array_column($this->rows($this->decode($this->get('/placeholder-definitions/' . self::FIRST_NAME . '/templates'))), 'id'));
        self::assertCount(1, $this->pivotRowsOf(20));
    }

    #[Test]
    public function theResourceBuildsAPivotRowItsConstructorNeedsArgumentsFor(): void
    {
        $response = $this->handle('/campaign-templates/10/relationships/pinned', 'POST', ['data' => [
            ['type' => 'placeholder-definitions', 'id' => self::COMPANY, 'meta' => ['pivot' => ['note' => 'top']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $template = $entityManager->find(PkTemplateEntity::class, 10);
        self::assertInstanceOf(PkTemplateEntity::class, $template);
        $rows = \array_values($template->pinnedRows->toArray());
        self::assertCount(1, $rows);
        self::assertSame('api:default', $rows[0]->pinnedBy);
        self::assertSame('top', $rows[0]->note);
        self::assertSame(self::COMPANY, $rows[0]->definition->id->toRfc4122());
        self::assertInstanceOf(UuidV7::class, $rows[0]->id);
    }

    #[Test]
    public function aCreateBuildsItsPivotRowsThroughTheConstructor(): void
    {
        $response = $this->handle('/placeholder-definitions', 'POST', ['data' => [
            'type' => 'placeholder-definitions',
            'attributes' => ['name' => 'job-title'],
            'relationships' => ['templates' => ['data' => [
                ['type' => 'campaign-templates', 'id' => '30', 'meta' => ['pivot' => ['defaultOverride' => 'CTO']]],
            ]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->dataOf($response)['id'] ?? null;
        self::assertIsString($id);

        $rows = $this->rows($this->decode($this->get('/placeholder-definitions/' . $id . '/templates')));
        self::assertSame(['30'], \array_column($rows, 'id'));
        self::assertSame('CTO', $this->pivotOf($rows[0])['defaultOverride'] ?? null);
        self::assertCount(1, $this->pivotRowsOf(30));
    }

    #[Test]
    public function aTemplateIsFilteredThroughItsPivotRowsToADefinition(): void
    {
        $ids = fn(string $query): array => \array_column($this->rows($this->decode($this->get('/campaign-templates?sort=name&' . $query))), 'id');

        self::assertSame(['10', '20'], $ids('filter[definition]=' . self::FIRST_NAME));
        self::assertSame(['10', '20'], $ids('filter[definition]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['20'], $ids('filter[definitionIs]=' . self::COMPANY));
        self::assertSame(['10'], $ids('filter[definitionKey]=' . self::LAST_NAME));
        self::assertSame([], $ids('filter[definitionKey]=not-a-uuid'));
        self::assertSame([], $ids('filter[definitionIs]=01932f5e-1a2b-7c3d-8e4f-0000000000ff'));

        $row = $this->pivotRowsOf(20)[0]->id->toRfc4122();
        self::assertSame(['20'], $ids('filter[row]=' . $row));
        self::assertSame(['20'], $ids('filter[rowIs]=' . $row));
        self::assertSame([], $ids('filter[row]=not-a-uuid'));
    }

    #[Test]
    public function aTemplateIsFilteredThroughItsPivotRelation(): void
    {
        $ids = fn(string $query): array => \array_column($this->rows($this->decode($this->get('/campaign-templates?sort=name&' . $query))), 'id');

        self::assertSame(['10'], $ids('filter[placeholder]=' . self::LAST_NAME));
        self::assertSame(['10', '20'], $ids('filter[placeholder]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['20'], $ids('filter[placeholderName]=company'));
        self::assertSame(['10', '20'], $ids('filter[hasPlaceholders]=1'));
    }

    #[Test]
    public function aDefinitionIsFilteredThroughItsTemplates(): void
    {
        $names = fn(string $query): array => \array_map(
            fn(array $row): mixed => $this->attribute($row, 'name'),
            $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&' . $query))),
        );

        self::assertSame(['company', 'first-name'], $names('filter[template]=20'));
        self::assertSame(['first-name', 'last-name'], $names('filter[usedBy]=10'));
        self::assertSame(['company', 'first-name', 'last-name'], $names('filter[usedBy]=10,20'));
    }

    #[Test]
    public function aDefinitionIsFilteredByItsBinaryId(): void
    {
        $names = fn(string $query): array => \array_map(
            fn(array $row): mixed => $this->attribute($row, 'name'),
            $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&' . $query))),
        );

        self::assertSame(['company', 'last-name'], $names('filter[id]=' . self::LAST_NAME . ',' . self::COMPANY));
        self::assertSame(['first-name'], $names('filter[idIs]=' . self::FIRST_NAME));
        self::assertSame([], $names('filter[idIs]=not-a-uuid'));
    }

    #[Test]
    public function aPivotFilterNarrowsARelatedQueryWindowAndItsCount(): void
    {
        $rows = $this->rows($this->decode($this->get('/campaign-templates?sort=name&include=placeholders&withCount=placeholders&relatedQuery[placeholders][filter][defaultOverride]=Bob', self::QUERIES_ACCEPT . ',' . self::COUNTS_ACCEPT)));
        self::assertSame([[], [['type' => 'placeholder-definitions', 'id' => self::FIRST_NAME]], []], \array_map(fn(array $row): array => $this->identifiers($this->linkage($row, 'placeholders')), $rows));
        self::assertSame([0, 1, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'placeholders')['total'] ?? null, $rows));

        $definitions = $this->rows($this->decode($this->get('/placeholder-definitions?sort=name&include=templates&relatedQuery[templates][filter][defaultOverride]=Ann&relatedQuery[templates][sort]=-defaultOverride', self::QUERIES_ACCEPT)));
        self::assertSame([$this->template('10')], $this->identifiers($this->linkage($definitions[1], 'templates')));
        self::assertSame([], $this->identifiers($this->linkage($definitions[0], 'templates')));
    }

    #[Test]
    public function aTypeTheFilterNamesDecodesAnEntityTwoTypesServe(): void
    {
        $this->bootVariant('named');

        $ids = fn(string $uri): array => \array_column($this->rows($this->decode($this->get($uri))), 'id');
        self::assertSame(['10', '20'], $ids('/campaign-templates?sort=name&filter[definition]=' . self::FIRST_NAME));
        self::assertSame(['def-' . self::COMPANY], $ids('/labelled-definitions?filter[id]=def-' . self::COMPANY));
        self::assertSame([self::COMPANY], $ids('/placeholder-definitions?filter[id]=' . self::COMPANY));
    }

    #[Test]
    public function typesSharingAnEncoderLeaveAnUnnamedPathAllowed(): void
    {
        $this->bootVariant('shared');

        self::assertSame(['20'], \array_column($this->rows($this->decode($this->get('/bare-templates?filter[definition]=' . self::COMPANY))), 'id'));
    }

    #[Test]
    public function anUnnamedPathToAnEntityTypesDecodeDifferentlyFailsTheWarmUp(): void
    {
        static::ensureKernelShutdown();
        PivotKeysTestKernel::$variant = 'clash';

        try {
            $kernel = new PivotKeysTestKernel('test', false);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('The JSON:API resource for type "bare-templates" on server "default" declares a filter that cannot run: filter "definition" ends its path "placeholderRows.definition" on ' . PkDefinitionEntity::class . ', which the types "placeholder-definitions", "labelled-definitions" serve with different id encoders');
            KernelTestCase::bootKernel(['debug' => false]);
        } finally {
            PivotKeysTestKernel::$variant = '';
            static::ensureKernelShutdown();
        }
    }

    private function bootVariant(string $variant): void
    {
        static::ensureKernelShutdown();
        PivotKeysTestKernel::$variant = $variant;
        try {
            $kernel = new PivotKeysTestKernel('test', false);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
            static::bootKernel(['debug' => false]);
            $this->afterBoot();
        } finally {
            PivotKeysTestKernel::$variant = '';
        }
    }

    #[Test]
    public function aPivotRowFilterReadsTheRowOfThisTemplate(): void
    {
        $related = fn(int $template, string $query): array => \array_column($this->rows($this->decode($this->get('/campaign-templates/' . $template . '/placeholders?sort=name&' . $query))), 'id');

        // first-name is shared: used on template 10, unused on template 20.
        self::assertSame([self::LAST_NAME], $related(10, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $related(20, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $related(10, 'filter[used]=1'));
        self::assertSame([self::COMPANY], $related(20, 'filter[used]=1'));
        self::assertSame([self::COMPANY], $related(20, 'filter[targetName]=footer'));
        self::assertSame([self::FIRST_NAME], $related(10, 'filter[target]=' . $this->targetIds['header']));
        self::assertSame([self::COMPANY], $related(20, 'filter[target]=' . $this->targetIds['header'] . ',' . $this->targetIds['footer']));
        self::assertSame([self::FIRST_NAME], $related(10, 'filter[targetedAtHeader]=1'));
        self::assertSame([], $related(20, 'filter[unused]=1&filter[defaultOverride]=Acme'));
    }

    #[Test]
    public function aPivotRowFilterAppliesOnTheRelationshipEndpoint(): void
    {
        $linkage = fn(int $template, string $query): array => \array_column($this->rows($this->decode($this->get('/campaign-templates/' . $template . '/relationships/placeholders?' . $query))), 'id');

        self::assertSame([self::LAST_NAME], $linkage(10, 'filter[unused]=1'));
        self::assertSame([self::FIRST_NAME], $linkage(20, 'filter[unused]=1'));
        self::assertSame([self::COMPANY], $linkage(20, 'filter[targetName]=footer'));
    }

    #[Test]
    public function aPivotRowFilterCountsAndPagesThePivotRead(): void
    {
        $document = $this->decode($this->get('/campaign-templates/20/placeholders?filter[used]=1&page[size]=1&withCount=_self_', self::COUNTS_ACCEPT));

        self::assertSame([self::COMPANY], \array_column($this->rows($document), 'id'));
        self::assertSame(1, $this->pageTotal($document));
    }

    #[Test]
    public function aPivotRowFilterNarrowsARelatedQueryWindowAndItsCount(): void
    {
        $accept = self::QUERIES_ACCEPT . ',' . self::COUNTS_ACCEPT;
        $linkage = fn(array $rows): array => \array_map(fn(array $row): array => \array_column($this->identifiers($this->linkage($row, 'placeholders')), 'id'), $rows);

        $rows = $this->rows($this->decode($this->get('/campaign-templates?sort=name&include=placeholders&withCount=placeholders&relatedQuery[placeholders][filter][unused]=1', $accept)));
        self::assertSame([[self::LAST_NAME], [self::FIRST_NAME], []], $linkage($rows));
        self::assertSame([1, 1, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'placeholders')['total'] ?? null, $rows));

        $paged = $this->rows($this->decode($this->get('/campaign-templates?sort=name&include=placeholders&relatedQuery[placeholders][filter][targetName]=header&relatedQuery[placeholders][sort]=-name', $accept)));
        self::assertSame([[self::FIRST_NAME], [self::COMPANY], []], $linkage($paged));

        $counted = $this->rows($this->decode($this->get('/campaign-templates?sort=name&withCount=placeholders&relatedQuery[placeholders][filter][target]=' . $this->targetIds['header'], $accept)));
        self::assertSame([1, 1, 0], \array_map(fn(array $row): mixed => $this->relationshipMeta($row, 'placeholders')['total'] ?? null, $counted));
    }

    #[Test]
    public function aPivotRowFilterIsUnrecognisedOnTheRelatedTypesOwnCollection(): void
    {
        self::assertSame(400, $this->handle('/placeholder-definitions?filter[unused]=1')->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentListsPivotRowFiltersOnlyWherePivotFiltersApply(): void
    {
        $document = $this->decode($this->get('/docs.json', 'application/json'));
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $filters = function (string $path) use ($paths): array {
            $item = $paths[$path] ?? null;
            self::assertIsArray($item, $path);
            $operation = $item['get'] ?? null;
            self::assertIsArray($operation, $path);
            $parameters = $operation['parameters'] ?? [];
            self::assertIsArray($parameters);

            return \array_values(\array_filter(\array_map(static fn(mixed $parameter): mixed => \is_array($parameter) ? $parameter['name'] ?? null : null, $parameters), static fn(mixed $name): bool => \is_string($name) && \str_starts_with($name, 'filter[')));
        };

        foreach (['/campaign-templates/{id}/placeholders', '/campaign-templates/{id}/relationships/placeholders'] as $path) {
            foreach (['filter[unused]', 'filter[used]', 'filter[targetName]', 'filter[target]', 'filter[targetedAtHeader]'] as $name) {
                self::assertContains($name, $filters($path), $path);
            }
        }
        self::assertNotContains('filter[unused]', $filters('/campaign-templates'));
        self::assertNotContains('filter[unused]', $filters('/placeholder-definitions'));
        self::assertNotContains('filter[unused]', $filters('/placeholder-definitions/{id}/templates'));
    }

    /**
     * @return list<PkTemplatePlaceholderEntity>
     */
    private function pivotRowsOf(int $campaign): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $template = $entityManager->find(PkTemplateEntity::class, $campaign);
        self::assertInstanceOf(PkTemplateEntity::class, $template);

        return \array_values($template->placeholderRows->toArray());
    }

    /**
     * @param list<PkPlaceholderTargetEntity> $targets
     */
    private function link(PkTemplateEntity $template, PkDefinitionEntity $definition, ?string $override, array $targets = []): void
    {
        $row = new PkTemplatePlaceholderEntity();
        foreach ($targets as $target) {
            $row->targets->add($target);
        }
        $row->template = $template;
        $row->definition = $definition;
        $row->defaultOverride = $override;
        $template->placeholderRows->add($row);
        $this->entityManager()->persist($row);
    }

    /**
     * @return array{type: string, id: string}
     */
    private function template(string $id): array
    {
        return ['type' => 'campaign-templates', 'id' => $id];
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(mixed $linkage): array
    {
        self::assertIsArray($linkage);

        return \array_values(\array_map(static fn(mixed $row): array => [
            'type' => \is_array($row) ? $row['type'] ?? null : null,
            'id' => \is_array($row) ? $row['id'] ?? null : null,
        ], $linkage));
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
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function pivotOf(array $row): array
    {
        $meta = $row['meta'] ?? null;
        self::assertIsArray($meta);
        $pivot = $meta['pivot'] ?? null;
        self::assertIsArray($pivot);

        /** @var array<string, mixed> $pivot */
        return $pivot;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function attribute(array $row, string $name): mixed
    {
        $attributes = $row['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function pageTotal(array $document): mixed
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        return $page['total'] ?? null;
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
