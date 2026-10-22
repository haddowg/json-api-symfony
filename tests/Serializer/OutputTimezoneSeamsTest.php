<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataProvider\PivotFields;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use haddowg\JsonApiBundle\Serializer\PivotSubstitutingResolver;
use haddowg\JsonApiBundle\Serializer\RequestScopedOutputTimezone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The bundle pieces that carry a server's output timezone outside a resource's own
 * attributes (bundle ADR 0123): the pivot-substituting resolver passes its inner
 * resolver's zone through, the pivot cast applies a zone the way core does for
 * attributes, and the per-operation holder clears between requests.
 */
final class OutputTimezoneSeamsTest extends TestCase
{
    #[Test]
    public function thePivotSubstitutingResolverPassesTheInnerZoneThrough(): void
    {
        $server = Server::make()->withOutputTimezone('UTC');

        self::assertSame('UTC', $this->substituting($server)->outputTimezone());
        self::assertNull($this->substituting(Server::make())->outputTimezone());
    }

    #[Test]
    public function thePivotCastMovesADateTimeIntoTheGivenZone(): void
    {
        $value = new \DateTimeImmutable('2024-04-01 12:00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2024-04-01T10:00:00+00:00', PivotFields::cast($value, DateTime::make('bookedAt')->build(), 'UTC'));
        self::assertSame('2024-04-01T12:00:00+02:00', PivotFields::cast($value, DateTime::make('bookedAt')->build()));
    }

    #[Test]
    public function thePivotCastKeepsAFieldsOwnZoneAndLeavesDateAndTimeAlone(): void
    {
        $value = new \DateTimeImmutable('2024-04-01 00:30:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame(
            '2024-03-31T18:30:00-04:00',
            PivotFields::cast($value, DateTime::make('confirmedAt')->outputTimezone('America/New_York')->build(), 'UTC'),
        );
        self::assertSame('2024-04-01', PivotFields::cast($value, Date::make('contractOn')->build(), 'UTC'));
        self::assertSame('00:30:00', PivotFields::cast($value, Time::make('doorsAt')->build(), 'UTC'));
    }

    #[Test]
    public function theHolderClearsOnReset(): void
    {
        $holder = new RequestScopedOutputTimezone();
        self::assertInstanceOf(ResetInterface::class, $holder);
        self::assertNull($holder->timezone());

        $holder->set('UTC');
        self::assertSame('UTC', $holder->timezone());

        $holder->reset();
        self::assertNull($holder->timezone());
    }

    private function substituting(Server $server): PivotSubstitutingResolver
    {
        $inner = new class implements \haddowg\JsonApi\Serializer\SerializerInterface {
            public function getType(mixed $object): string
            {
                return 'tracks';
            }

            public function getId(mixed $object): string
            {
                return '1';
            }

            public function getMeta(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getLinks(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): ?\haddowg\JsonApi\Schema\Link\ResourceLinks
            {
                return null;
            }

            public function getAttributes(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }

            public function getDefaultIncludedRelationships(mixed $object): array
            {
                return [];
            }

            public function getRelationships(mixed $object, \haddowg\JsonApi\Request\JsonApiRequestInterface $request): array
            {
                return [];
            }
        };

        return new PivotSubstitutingResolver($server, 'tracks', new PivotMetaSerializer($inner, []));
    }
}
