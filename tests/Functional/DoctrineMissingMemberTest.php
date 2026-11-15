<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * Adding or replacing an inverse to-many's members loads each one to set its owner, so a
 * member no row holds is a missing resource, not a server error, and nothing changes.
 */
final class DoctrineMissingMemberTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    #[Test]
    public function anInverseToManyWriteNamingAMissingMemberIsNotFound(): void
    {
        $before = $this->decode($this->handle('/articles/1/relationships/comments'))['data'] ?? null;

        foreach (['POST', 'PATCH'] as $method) {
            $response = $this->handle('/articles/1/relationships/comments', $method, ['data' => [['type' => 'comments', 'id' => '999']]]);
            self::assertSame(404, $response->getStatusCode(), $method . ': ' . $response->getContent());
            $errors = $this->decode($response)['errors'] ?? null;
            self::assertIsArray($errors);
            self::assertSame('RESOURCE_NOT_FOUND', \is_array($errors[0] ?? null) ? ($errors[0]['code'] ?? null) : null);
        }

        self::assertSame($before, $this->decode($this->handle('/articles/1/relationships/comments'))['data'] ?? null);
    }

}
