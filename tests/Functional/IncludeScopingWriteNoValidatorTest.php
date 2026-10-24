<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingNoValidatorDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingPlacement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * {@see IncludeScopingWriteConformanceTestCase} where a Doctrine query extension hides
 * the rows and symfony/validator is absent, plus the pivot relation's linkage type.
 */
final class IncludeScopingWriteNoValidatorTest extends IncludeScopingWriteConformanceTestCase
{
    use IncludeScopingDoctrineWrites;

    protected static function getKernelClass(): string
    {
        return IncludeScopingNoValidatorDoctrineTestKernel::class;
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aPivotMemberUnderATypeTheRelationDoesNotAcceptIsRefused(string $mount, string $prefix): void
    {
        foreach (['POST', 'PATCH'] as $method) {
            $response = $this->handle($mount . '/' . $prefix . 'advertisers/2/relationships/placedAgencies', $method, [
                'data' => [['type' => 'zz', 'id' => '2', 'meta' => ['pivot' => ['role' => 'Overwritten']]]],
            ]);

            self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('RESOURCE_TYPE_UNACCEPTABLE', $this->value($this->decode($response), 'errors', '0', 'code'));
        }

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $roles = [];
        foreach ($entityManager->getRepository(ScopingPlacement::class)->findBy(['advertiser' => 2]) as $placement) {
            $roles[$placement->agency->id] = $placement->role;
        }
        \ksort($roles);

        self::assertSame([2 => 'Secret Role', 3 => 'Support'], $roles);
    }
}
