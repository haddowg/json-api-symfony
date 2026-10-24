<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource\Advertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource\Agency;
use haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource\Campaign;
use haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource\ExpectResourceTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource\Memo;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser::expectResource()} picks the
 * type by the model's declared entity class, nearest class first, rather than the
 * first serializer whose class-blind `getType()` answers.
 */
final class ExpectResourceTypeResolutionTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ExpectResourceTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new Advertiser(1, 'Globex'));
        $entityManager->persist(new Agency(2, 'Acme', 'EMEA'));
        $entityManager->persist(new Campaign(3, 'Launch', 500));
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function a_subclass_resolves_to_the_type_mapped_to_its_own_class(): void
    {
        $expected = $this->browser()->expectResource(new Agency(2, 'Acme', 'EMEA'));

        self::assertSame('agencies', $expected['type']);
        $this->browser()
            ->get('/agencies/2')
            ->assertFetchedOneExact($expected);
    }

    #[Test]
    public function the_parent_class_resolves_to_its_own_type(): void
    {
        $expected = $this->browser()->expectResource(new Advertiser(1, 'Globex'));

        self::assertSame('advertisers', $expected['type']);
        $this->browser()
            ->get('/advertisers/1')
            ->assertFetchedOneExact($expected);
    }

    #[Test]
    public function an_anonymous_subclass_resolves_through_its_nearest_mapped_parent(): void
    {
        $proxy = new class (2, 'Acme', 'EMEA') extends Advertiser {
            public function __construct(?int $id, string $name, public string $region)
            {
                parent::__construct($id, $name);
            }
        };

        self::assertSame('advertisers', $this->browser()->expectResource($proxy)['type']);
    }

    #[Test]
    public function an_uninitialized_doctrine_reference_resolves_to_its_entitys_type(): void
    {
        $reference = $this->entityManager()->getReference(Agency::class, 2);
        self::assertNotNull($reference);

        self::assertSame('agencies', $this->browser()->expectResource($reference)['type']);
    }

    #[Test]
    public function an_explicit_type_wins_over_the_mapping(): void
    {
        $expected = $this->browser()->expectResource(new Agency(2, 'Acme', 'EMEA'), type: 'advertisers');

        self::assertSame('advertisers', $expected['type']);
        $attributes = $expected['attributes'];
        self::assertIsArray($attributes);
        self::assertArrayNotHasKey('region', $attributes);
    }

    #[Test]
    public function a_class_behind_several_types_needs_an_explicit_type(): void
    {
        $campaign = new Campaign(3, 'Launch', 500);

        $summary = $this->browser()->expectResource($campaign, type: 'campaign-summaries');
        self::assertSame('campaign-summaries', $summary['type']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('backs several JSON:API types (campaigns, campaign-summaries)');
        $this->browser()->expectResource($campaign);
    }

    #[Test]
    public function an_unmapped_model_falls_back_to_the_serializer_scan(): void
    {
        $expected = $this->browser()->expectResource(new Memo(4, 'Remember'));

        self::assertSame('memos', $expected['type']);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
