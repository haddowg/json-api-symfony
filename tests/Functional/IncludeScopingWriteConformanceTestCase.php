<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Linkage a write sends is resolved through the related endpoint's scope (bundle ADR
 * 0126): an id the scope hides is rejected exactly like one that does not exist, and a
 * replace leaves the hidden members alone. Agency 2 and member 2 are hidden; agency 99
 * and member 99 do not exist.
 *
 * The writes go through the public twins of the read fixtures: advertiser 1's
 * `partners` hold agencies 1 and 2, advertiser 2's agency 3; each member's `team` is
 * its agency (member 1's is agency 1); agency 1's `manager` is the hidden member 2,
 * agency 3's member 3. `mixed-advertisers` is a polymorphic view of the same advertisers.
 */
abstract class IncludeScopingWriteConformanceTestCase extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    /**
     * The ids advertiser `$id`'s `partners` hold in storage, sorted.
     *
     * @return list<int>
     */
    abstract protected function storedPartnerIds(string $prefix, int $id): array;

    abstract protected function storedTeamId(string $prefix, int $member): ?int;

    abstract protected function storedManagerId(string $prefix, int $agency): ?int;

    abstract protected function storedMemberName(string $prefix, int $member): string;

    abstract protected function storedMemberCount(string $prefix): int;

    abstract protected function storedAdvertiserName(string $prefix, int $id): string;

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function addingAHiddenIdToAToManyIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'advertisers/2/relationships/partners', 'POST', [
                'data' => [['type' => $prefix . 'agencies', 'id' => $id]],
            ]),
            '2',
            '99',
        );
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function replacingAToManyWithAHiddenIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'advertisers/2/relationships/partners', 'PATCH', [
                'data' => [['type' => $prefix . 'agencies', 'id' => '3'], ['type' => $prefix . 'agencies', 'id' => $id]],
            ]),
            '2',
            '99',
        );
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function removingAHiddenIdFromAToManyIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'advertisers/1/relationships/partners', 'DELETE', [
                'data' => [['type' => $prefix . 'agencies', 'id' => $id]],
            ]),
            '2',
            '99',
        );
        self::assertSame([1, 2], $this->storedPartnerIds($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function settingABelongsToToAHiddenIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'members/1/relationships/team', 'PATCH', [
                'data' => ['type' => $prefix . 'agencies', 'id' => $id],
            ]),
            '2',
            '99',
        );
        self::assertSame(1, $this->storedTeamId($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function settingAHasOneToAHiddenIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'agencies/3/relationships/manager', 'PATCH', [
                'data' => ['type' => $prefix . 'members', 'id' => $id],
            ]),
            '2',
            '99',
        );
        self::assertSame(3, $this->storedManagerId($prefix, 3));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function settingAMorphToToAHiddenIdOfEitherTypeIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        foreach (['agencies', 'members'] as $type) {
            $this->assertRejectedLikeMissing(
                fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'members/1/relationships/side', 'PATCH', [
                    'data' => ['type' => $prefix . $type, 'id' => $id],
                ]),
                '2',
                '99',
            );
        }
        self::assertSame(1, $this->storedTeamId($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aMorphToManyInAnUpdateBodyWithAHiddenIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        foreach (['agencies', 'members'] as $type) {
            $this->assertRejectedLikeMissing(
                fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'mixed-advertisers/2', 'PATCH', [
                    'data' => [
                        'type' => $prefix . 'mixed-advertisers',
                        'id' => '2',
                        'relationships' => ['mixedPartners' => ['data' => [['type' => $prefix . $type, 'id' => $id]]]],
                    ],
                ]),
                '2',
                '99',
            );
        }
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aMorphToManyRelationshipEndpointIsRefusedBeforeItWrites(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'mixed-advertisers/2/relationships/mixedPartners', 'POST', [
            'data' => [['type' => $prefix . 'agencies', 'id' => '1']],
        ]);

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aCreateWithAHiddenLinkageIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'members', 'POST', [
                'data' => [
                    'type' => $prefix . 'members',
                    'attributes' => ['name' => 'New'],
                    'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => $id]]],
                ],
            ]),
            '2',
            '99',
        );
        self::assertSame(4, $this->storedMemberCount($prefix));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anUpdateWithAHiddenLinkageIdIsRejectedLikeAMissingOne(string $mount, string $prefix): void
    {
        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'members/1', 'PATCH', [
                'data' => [
                    'type' => $prefix . 'members',
                    'id' => '1',
                    'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => $id]]],
                ],
            ]),
            '2',
            '99',
        );
        self::assertSame(1, $this->storedTeamId($prefix, 1));

        $this->assertRejectedLikeMissing(
            fn(string $id): Response => $this->handle($mount . '/' . $prefix . 'advertisers/2', 'PATCH', [
                'data' => [
                    'type' => $prefix . 'advertisers',
                    'id' => '2',
                    'relationships' => ['partners' => ['data' => [['type' => $prefix . 'agencies', 'id' => '1'], ['type' => $prefix . 'agencies', 'id' => $id]]]],
                ],
            ]),
            '2',
            '99',
        );
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aRelationshipEndpointReplaceLeavesHiddenMembersInPlace(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'advertisers/1/relationships/partners', 'PATCH', [
            'data' => [['type' => $prefix . 'agencies', 'id' => '3']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3'], $this->ids($this->decode($response)['data'] ?? null));
        self::assertSame([2, 3], $this->storedPartnerIds($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anUpdateBodyReplaceLeavesHiddenMembersInPlace(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'advertisers/1', 'PATCH', [
            'data' => ['type' => $prefix . 'advertisers', 'id' => '1', 'relationships' => ['partners' => ['data' => []]]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoHiddenRow($this->decode($response));
        self::assertSame([2], $this->storedPartnerIds($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aRelationshipEndpointResponseRendersOnlyVisibleMembers(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'advertisers/1/relationships/partners', 'POST', [
            'data' => [['type' => $prefix . 'agencies', 'id' => '3']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '3'], $this->ids($this->decode($response)['data'] ?? null));
        self::assertSame([1, 2, 3], $this->storedPartnerIds($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aToOneHoldingAHiddenTargetCanBeReplaced(string $mount, string $prefix): void
    {
        // A to-one has one slot: a client that may write it replaces whatever it holds.
        $response = $this->handle($mount . '/' . $prefix . 'agencies/1/relationships/manager', 'PATCH', [
            'data' => ['type' => $prefix . 'members', 'id' => '1'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('1', $this->value($this->decode($response), 'data', 'id'));
        self::assertSame(1, $this->storedManagerId($prefix, 1));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function visibleIdsStillWrite(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix;

        $response = $this->handle($base . 'members/1/relationships/team', 'PATCH', ['data' => ['type' => $prefix . 'agencies', 'id' => '3']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(3, $this->storedTeamId($prefix, 1));

        $response = $this->handle($base . 'members/3/relationships/side', 'PATCH', ['data' => ['type' => $prefix . 'agencies', 'id' => '1']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->storedTeamId($prefix, 3));

        $response = $this->handle($base . 'agencies/3/relationships/manager', 'PATCH', ['data' => ['type' => $prefix . 'members', 'id' => '1']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->storedManagerId($prefix, 3));

        $response = $this->handle($base . 'advertisers/2/relationships/partners', 'POST', ['data' => [['type' => $prefix . 'agencies', 'id' => '1']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([1, 3], $this->storedPartnerIds($prefix, 2));

        $response = $this->handle($base . 'advertisers/2/relationships/partners', 'DELETE', ['data' => [['type' => $prefix . 'agencies', 'id' => '3']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([1], $this->storedPartnerIds($prefix, 2));

        $response = $this->handle($base . 'members', 'POST', [
            'data' => [
                'type' => $prefix . 'members',
                'attributes' => ['name' => 'New'],
                'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => '3']]],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(5, $this->storedMemberCount($prefix));
        self::assertSame(3, $this->storedTeamId($prefix, 5));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aCreateCannotWriteThroughAHiddenOnHop(string $mount, string $prefix): void
    {
        // Agency 1 is visible; its manager, member 2, is not.
        $response = $this->handle($mount . '/' . $prefix . 'members', 'POST', [
            'data' => [
                'type' => $prefix . 'members',
                'attributes' => ['name' => 'New', 'teamManagerName' => 'Renamed'],
                'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => '1']]],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('RELATED_ATTRIBUTE_OWNER_MISSING', $this->errorCode($response));
        self::assertSame('Hidden Member', $this->storedMemberName($prefix, 2));
        self::assertSame(4, $this->storedMemberCount($prefix));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aCreateWritesThroughAVisibleOnHop(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'members', 'POST', [
            'data' => [
                'type' => $prefix . 'members',
                'attributes' => ['name' => 'New', 'teamManagerName' => 'Renamed'],
                'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => '3']]],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Renamed', $this->storedMemberName($prefix, 3));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anAtomicBatchReferencingAHiddenIdRollsBack(string $mount, string $prefix): void
    {
        $response = $this->atomic($mount, [
            [
                'op' => 'update',
                'ref' => ['type' => $prefix . 'advertisers', 'id' => '2'],
                'data' => ['type' => $prefix . 'advertisers', 'id' => '2', 'attributes' => ['name' => 'Changed']],
            ],
            [
                'op' => 'add',
                'ref' => ['type' => $prefix . 'advertisers', 'id' => '2', 'relationship' => 'partners'],
                'data' => [['type' => $prefix . 'agencies', 'id' => '2']],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('RESOURCE_NOT_FOUND', $this->errorCode($response));
        self::assertSame('Advertiser Two', $this->storedAdvertiserName($prefix, 2));
        self::assertSame([3], $this->storedPartnerIds($prefix, 2));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anAtomicBatchLinkingALocalIdToAHiddenIdRollsBack(string $mount, string $prefix): void
    {
        $response = $this->atomic($mount, [
            [
                'op' => 'add',
                'data' => [
                    'type' => $prefix . 'members',
                    'lid' => 'new-member',
                    'attributes' => ['name' => 'New'],
                    'relationships' => ['team' => ['data' => ['type' => $prefix . 'agencies', 'id' => '3']]],
                ],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => $prefix . 'members', 'lid' => 'new-member', 'relationship' => 'side'],
                'data' => ['type' => $prefix . 'members', 'id' => '2'],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('RESOURCE_NOT_FOUND', $this->errorCode($response));
        self::assertSame(4, $this->storedMemberCount($prefix));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anAtomicBatchOfVisibleIdsCommits(string $mount, string $prefix): void
    {
        $response = $this->atomic($mount, [
            [
                'op' => 'add',
                'data' => [
                    'type' => $prefix . 'members',
                    'lid' => 'new-member',
                    'attributes' => ['name' => 'New'],
                ],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => $prefix . 'members', 'lid' => 'new-member', 'relationship' => 'team'],
                'data' => ['type' => $prefix . 'agencies', 'id' => '1'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $this->storedTeamId($prefix, 5));
    }

    /**
     * Sends the same write with a hidden id and with a missing one, and asserts both
     * fail with the same `404` document.
     *
     * @param \Closure(string): Response $send
     */
    private function assertRejectedLikeMissing(\Closure $send, string $hiddenId, string $missingId): void
    {
        $hidden = $send($hiddenId);
        $missing = $send($missingId);

        self::assertSame(404, $hidden->getStatusCode(), (string) $hidden->getContent());
        self::assertSame('RESOURCE_NOT_FOUND', $this->errorCode($hidden));
        self::assertSame($missing->getStatusCode(), $hidden->getStatusCode());
        self::assertSame((string) $missing->getContent(), (string) $hidden->getContent());
    }

    private function errorCode(Response $response): ?string
    {
        $code = $this->value($this->decode($response), 'errors', '0', 'code');

        return \is_string($code) ? $code : null;
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(string $mount, array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create(
            $mount . '/operations',
            'POST',
            server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext],
            content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
