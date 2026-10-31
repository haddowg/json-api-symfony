<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\BothServersRegistriesTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ServerKeyedRegistriesConformance} and {@see ServerKeyedIdPatternConformance} on
 * the Doctrine kernel, plus the id decoding only the Doctrine layer does: `codedWidgets`
 * is served by a different class on each server, whose ids read `a-<key>` on the
 * default server and `b-<key>` on the named one (bundle ADR 0034).
 */
final class DoctrinePerServerRegistriesTest extends JsonApiFunctionalTestCase
{
    use ServerKeyedIdPatternConformance;
    use ServerKeyedRegistriesConformance;

    protected static function getKernelClass(): string
    {
        return BothServersRegistriesTestKernel::class;
    }

    #[Test]
    public function eachServerFindsRowsByItsOwnIdForm(): void
    {
        self::assertSame('a-1', $this->idAt('/codedWidgets/a-1'));
        self::assertSame(404, $this->handle('/codedWidgets/b-1')->getStatusCode());

        self::assertSame('b-1', $this->idAt('/v5/codedWidgets/b-1'));
        self::assertSame(404, $this->handle('/v5/codedWidgets/a-1')->getStatusCode());
    }

    #[Test]
    public function eachServerListsRowsUnderItsOwnIdForm(): void
    {
        self::assertSame('a-1', $this->idAt('/codedWidgets'));
        self::assertSame('b-1', $this->idAt('/v5/codedWidgets'));
    }

    /**
     * The id of the resource at `$path`, or of the first one in the collection there.
     */
    private function idAt(string $path): mixed
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $resource = \array_is_list($data) ? ($data[0] ?? null) : $data;
        self::assertIsArray($resource);

        return $resource['id'] ?? null;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $entityManager->persist(new OpenWidgetEntity(1, 'one'));
        $entityManager->flush();
        $entityManager->clear();
    }
}
