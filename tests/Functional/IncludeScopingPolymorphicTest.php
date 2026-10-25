<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAdvertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAgency;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;
use PHPUnit\Framework\Attributes\Test;

/**
 * A polymorphic to-many whose member types include a scoped type renders its members
 * narrowed per member type (bundle ADR 0125). `mixed-advertisers` 1's `contacts` holds
 * advertiser 2, of the unscoped advertisers type, and the hidden agency 2, of the
 * scoped agencies type; it is the second type that is scoped.
 */
final class IncludeScopingPolymorphicTest extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    private const array COUNTS = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

    protected static function getKernelClass(): string
    {
        return IncludeScopingInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        ScopingFixtures::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ScopingFixtures::reset();
    }

    #[Test]
    public function linkageIncludedAndTheCountLeaveOutAMemberOfAScopedType(): void
    {
        $document = $this->get('/mixed-advertisers/1?include=contacts&withCount=contacts&fields[mixed-advertisers]=contacts', self::COUNTS);

        self::assertSame([['type' => 'advertisers', 'id' => '2']], $this->value($this->relationship($document, '1', 'contacts'), 'data'));
        self::assertSame(1, $this->value($this->relationship($document, '1', 'contacts'), 'meta', 'total'));
        self::assertSame(['2'], $this->includedIds($document, 'advertisers'));
        self::assertSame([], $this->includedIds($document, 'agencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    public function bothEndpointsServeFullPagesOfTheNarrowedSet(): void
    {
        $first = $this->get('/mixed-advertisers/1/contacts?withCount=_self_&page[number]=1&page[size]=1', self::COUNTS);
        self::assertSame(['2'], $this->ids($first['data'] ?? null));
        self::assertSame(1, $this->value($first, 'meta', 'total'));

        $second = $this->get('/mixed-advertisers/1/contacts?withCount=_self_&page[number]=2&page[size]=1', self::COUNTS);
        self::assertSame([], $this->ids($second['data'] ?? null));
        $this->assertNoHiddenRow($second);

        self::assertSame([['type' => 'advertisers', 'id' => '2']], $this->get('/mixed-advertisers/1/relationships/contacts')['data'] ?? null);
    }

    #[Test]
    public function aWriteNamingAHiddenMemberIsA404AndAReplaceKeepsItAttached(): void
    {
        $refused = $this->handle('/mixed-advertisers/1/relationships/contacts', 'PATCH', [
            'data' => [['type' => 'agencies', 'id' => '2']],
        ]);
        self::assertSame(404, $refused->getStatusCode(), (string) $refused->getContent());

        $replaced = $this->handle('/mixed-advertisers/1/relationships/contacts', 'PATCH', ['data' => []]);
        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getContent());
        self::assertSame([], $this->decode($replaced)['data'] ?? null);

        $stored = ScopingFixtures::inMemoryProvider('mixed-advertisers')->store()->find('1');
        self::assertInstanceOf(ScopingAdvertiser::class, $stored);
        self::assertSame(
            ['agencies:2'],
            \array_map(static fn(object $member): string => ($member instanceof ScopingAgency ? 'agencies:' : 'advertisers:') . ($member->id ?? ''), \array_values($stored->contacts)),
        );
    }

    #[Test]
    public function aPolymorphicRelationOverAProviderThatDeclaresNoScopeIsServedThroughItsFetchOne(): void
    {
        // The opaque provider declares no scope, so its rows are each checked through its
        // fetchOne(), which admits both agencies; the relation is served, not refused.
        $document = $this->get('/opaque-advertisers/1/relationships/mixedAgencies');

        self::assertSame([['type' => 'opaque-agencies', 'id' => '1'], ['type' => 'opaque-agencies', 'id' => '2']], $document['data'] ?? null);
    }
}
