<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A fixed membership on an identified `MorphToMany` written by `fillUsing()`: the
 * current members are its stored pairs, compared by type and wire id, so the same id
 * under another type is a different member.
 */
abstract class FixedMembershipMorphConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array USER_ONE = ['agencies:1', 'advertisers:1', 'publishers:1', 'agencies:2', 'advertisers:2', 'agencies:3'];

    /**
     * @return list<string> the user's stored companies as `type:id`, in stored order
     */
    abstract protected function storedCompanies(int $user): array;

    /**
     * Whether agency 2 is hidden from the agencies' provider.
     */
    protected function hides(): bool
    {
        return false;
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementListingTheVisibleMembersKeepsTheHiddenOnes(): void
    {
        // Without agency 2: every member when it is hidden, one short when it is not.
        $response = $this->handle('/users/1/relationships/companies', 'PATCH', ['data' => $this->identifiers(\array_values(\array_diff(self::USER_ONE, ['agencies:2'])))]);

        if ($this->hides()) {
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        } else {
            $this->assertProhibited($response);
        }
        $this->assertUnchanged();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatReordersThePairsIsAllowed(): void
    {
        $response = $this->handle('/users/1/relationships/companies', 'PATCH', ['data' => $this->identifiers(\array_reverse($this->visible()))]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertUnchanged();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementNamingAnIdUnderAnotherTypeIsForbidden(): void
    {
        // User 3 holds agencies:1 and publishers:1; advertisers:1 shares the id, not the member.
        $response = $this->handle('/users/3/relationships/companies', 'PATCH', ['data' => $this->identifiers(['agencies:1', 'advertisers:1'])]);

        $this->assertProhibited($response);
        self::assertSame(['agencies:1', 'publishers:1'], $this->storedCompanies(3));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatDropsAPairIsForbidden(): void
    {
        $response = $this->handle('/users/1', 'PATCH', ['data' => [
            'type' => 'users',
            'id' => '1',
            'relationships' => ['companies' => ['data' => $this->identifiers(\array_slice($this->visible(), 1))]],
        ]]);

        $this->assertProhibited($response);
        $this->assertUnchanged();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function addingIsClosed(): void
    {
        $response = $this->handle('/users/1/relationships/companies', 'POST', ['data' => [['type' => 'publishers', 'id' => '2']]]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ADDITION_PROHIBITED', self::dig($this->decode($response), 'errors', 0, 'code'));
        $this->assertUnchanged();
    }

    /**
     * @return list<string> user 1's members a client can see
     */
    private function visible(): array
    {
        return $this->hides() ? \array_values(\array_diff(self::USER_ONE, ['agencies:2'])) : self::USER_ONE;
    }

    private function assertProhibited(Response $response): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $error = self::dig($this->decode($response), 'errors', 0);
        self::assertIsArray($error);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
        self::assertSame('/data/relationships/companies', self::dig($error, 'source', 'pointer'));
    }

    private function assertUnchanged(): void
    {
        $stored = $this->storedCompanies(1);
        $expected = self::USER_ONE;
        \sort($stored);
        \sort($expected);
        self::assertSame($expected, $stored);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array{type: string, id: string}>
     */
    private function identifiers(array $keys): array
    {
        return \array_map(static function (string $key): array {
            [$type, $id] = \explode(':', $key);

            return ['type' => $type, 'id' => $id];
        }, $keys);
    }

    /**
     * The value at `$keys` inside `$value`, or `null` when a step is missing.
     */
    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
