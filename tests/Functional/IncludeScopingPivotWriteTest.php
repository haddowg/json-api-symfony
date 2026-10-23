<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingPlacement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes to a pivot `belongsToMany` (through {@see ScopingPlacement}) resolve their
 * linkage through the related endpoint's scope (bundle ADR 0126). Agency 2 is hidden:
 * advertiser 1 is placed with agencies 1 ("Lead") and 2, advertiser 2 with agencies 2
 * and 3 ("Support"), and both placements with agency 2 carry the role "Secret Role".
 */
final class IncludeScopingPivotWriteTest extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;
    use IncludeScopingDoctrineWrites;

    protected static function getKernelClass(): string
    {
        return IncludeScopingDoctrineTestKernel::class;
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function attachingAHiddenIdWithPivotDataIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        foreach (['POST', 'PATCH'] as $method) {
            $hidden = $this->place($mount, $prefix, 2, $method, '2', 'Overwritten');
            $missing = $this->place($mount, $prefix, 2, $method, '99', 'Overwritten');

            self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
            self::assertSame((string) $missing->getContent(), (string) $hidden->getContent());
        }

        self::assertSame([2 => 'Secret Role', 3 => 'Support'], $this->placements(2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aHiddenMembersPivotValidatesLikeAMissingMembers(string $mount, string $prefix): void
    {
        // `role` is required for a new member; the hidden agency 2 must not count as present.
        $send = fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'advertisers/2/relationships/placedAgencies', 'POST', [
            'data' => [['type' => $prefix . 'agencies', 'id' => $id]],
        ]);
        $hidden = $send('2');
        $missing = $send('99');

        self::assertSame(422, $missing->getStatusCode(), (string) $missing->getContent());
        self::assertSame($missing->getStatusCode(), $hidden->getStatusCode(), (string) $hidden->getContent());
        self::assertSame((string) $missing->getContent(), (string) $hidden->getContent());
        self::assertSame([2 => 'Secret Role', 3 => 'Support'], $this->placements(2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function detachingAHiddenIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $hidden = $this->handle($mount . '/' . $prefix . 'advertisers/1/relationships/placedAgencies', 'DELETE', [
            'data' => [['type' => $prefix . 'agencies', 'id' => '2']],
        ]);
        $missing = $this->handle($mount . '/' . $prefix . 'advertisers/1/relationships/placedAgencies', 'DELETE', [
            'data' => [['type' => $prefix . 'agencies', 'id' => '99']],
        ]);

        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
        self::assertSame((string) $missing->getContent(), (string) $hidden->getContent());
        self::assertSame([1 => 'Lead', 2 => 'Secret Role'], $this->placements(1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aReplaceLeavesHiddenPivotRowsInPlace(string $mount, string $prefix): void
    {
        $response = $this->place($mount, $prefix, 1, 'PATCH', '3', 'Support');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3'], $this->ids($this->decode($response)['data'] ?? null));
        self::assertSame([2 => 'Secret Role', 3 => 'Support'], $this->placements(1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aVisibleIdAttachesWithItsPivotData(string $mount, string $prefix): void
    {
        $response = $this->place($mount, $prefix, 2, 'POST', '1', 'Partner');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '3'], $this->ids($this->decode($response)['data'] ?? null));
        self::assertSame([1 => 'Partner', 2 => 'Secret Role', 3 => 'Support'], $this->placements(2));
    }

    private function place(string $mount, string $prefix, int $advertiser, string $method, string $agency, string $role): Response
    {
        return $this->handle($mount . '/' . $prefix . 'advertisers/' . $advertiser . '/relationships/placedAgencies', $method, [
            'data' => [['type' => $prefix . 'agencies', 'id' => $agency, 'meta' => ['pivot' => ['role' => $role]]]],
        ]);
    }

    /**
     * The stored role of each of `$advertiser`'s placements, by agency id.
     *
     * @return array<int, string>
     */
    private function placements(int $advertiser): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $roles = [];
        foreach ($entityManager->getRepository(ScopingPlacement::class)->findBy(['advertiser' => $advertiser]) as $placement) {
            $roles[$placement->agency->id] = $placement->role;
        }
        \ksort($roles);

        return $roles;
    }
}
