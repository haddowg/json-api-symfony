<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * Three owners of every id kind, two posts and two videos whose ids collide, and four
 * notes: note 1 and 2 belong to each kind's first owner and note 3 to its second; notes 1
 * and 3 are about post 1, note 2 about video 1 and note 4 about video 2. Note 1 also
 * targets the first uuid owner through a polymorphic pair stored in a `uuid` column.
 */
final class IdMatrixSeed
{
    /**
     * Each kind's three keys as its entity holds them.
     *
     * @return array{int: array{int, int, int}, str: array{string, string, string}, uuid: array{Uuid, Uuid, Uuid}, ulid: array{Ulid, Ulid, Ulid}, guid: array{string, string, string}, code: array{Code, Code, Code}, enc: array{Uuid, Uuid, Uuid}, prefix: array{Uuid, Uuid, Uuid}}
     */
    public static function keys(): array
    {
        $uuids = static fn(string $last): array => [
            Uuid::fromString('0193d1c2-7a4e-7c55-9a8e-5f0c3b2d1e' . $last . '1'),
            Uuid::fromString('0193d1c2-7a4e-7c55-9a8e-5f0c3b2d1e' . $last . '2'),
            Uuid::fromString('0193d1c2-7a4e-7c55-9a8e-5f0c3b2d1e' . $last . '3'),
        ];

        return [
            'int' => [1, 2, 3],
            'str' => ['alpha', 'beta', 'gamma'],
            'uuid' => $uuids('0'),
            'ulid' => [Ulid::fromString('01JD8X4M3T5Q7R9V2W4Y6Z8A01'), Ulid::fromString('01JD8X4M3T5Q7R9V2W4Y6Z8A02'), Ulid::fromString('01JD8X4M3T5Q7R9V2W4Y6Z8A03')],
            'guid' => ['5f0c3b2d-1e00-4a4e-9c55-7a4e00000001', '5f0c3b2d-1e00-4a4e-9c55-7a4e00000002', '5f0c3b2d-1e00-4a4e-9c55-7a4e00000003'],
            'code' => [new Code(11), new Code(12), new Code(13)],
            'enc' => $uuids('1'),
            'prefix' => $uuids('2'),
        ];
    }

    /**
     * The wire id of `$kind`'s owner at `$index`.
     */
    public static function wireId(string $kind, int $index): string
    {
        $key = self::keys()[$kind][$index];
        $string = $key instanceof Ulid ? $key->toBase32() : ($key instanceof Uuid ? $key->toRfc4122() : (string) $key);

        return $kind === 'prefix' ? 'own_' . $string : $string;
    }

    /**
     * The wire id of a seeded or created row of any type.
     */
    public static function wireIdOf(object $row): string
    {
        $id = \property_exists($row, 'id') ? $row->id : null;
        $string = match (true) {
            $id instanceof Ulid => $id->toBase32(),
            $id instanceof Uuid => $id->toRfc4122(),
            \is_scalar($id) || $id instanceof \Stringable => (string) $id,
            default => '',
        };

        return $row instanceof ImPrefixOwner ? 'own_' . $string : $string;
    }

    /**
     * @return array<class-string, list<object>>
     */
    public static function build(): array
    {
        $keys = self::keys();
        $owners = [
            ImIntOwner::class => \array_map(static fn(int $key): ImIntOwner => new ImIntOwner($key, 'Int ' . $key), $keys['int']),
            ImStrOwner::class => \array_map(static fn(string $key): ImStrOwner => new ImStrOwner($key, 'Str ' . $key), $keys['str']),
            ImUuidOwner::class => \array_map(static fn(Uuid $key): ImUuidOwner => new ImUuidOwner($key, 'Uuid ' . $key), $keys['uuid']),
            ImUlidOwner::class => \array_map(static fn(Ulid $key): ImUlidOwner => new ImUlidOwner($key, 'Ulid ' . $key), $keys['ulid']),
            ImGuidOwner::class => \array_map(static fn(string $key): ImGuidOwner => new ImGuidOwner($key, 'Guid ' . $key), $keys['guid']),
            ImCodeOwner::class => \array_map(static fn(Code $key): ImCodeOwner => new ImCodeOwner($key, 'Code ' . $key), $keys['code']),
            ImEncOwner::class => \array_map(static fn(Uuid $key): ImEncOwner => new ImEncOwner($key, 'Enc ' . $key), $keys['enc']),
            ImPrefixOwner::class => \array_map(static fn(Uuid $key): ImPrefixOwner => new ImPrefixOwner($key, 'Prefix ' . $key), $keys['prefix']),
        ];

        $note = static fn(int $id, string $title, ?int $owner, string $subjectType, int $subjectId): ImNote => new ImNote(
            $id,
            $title,
            $owner === null ? null : $keys['int'][$owner],
            $owner === null ? null : $keys['str'][$owner],
            $owner === null ? null : $keys['uuid'][$owner],
            $owner === null ? null : $keys['ulid'][$owner],
            $owner === null ? null : $keys['guid'][$owner],
            $owner === null ? null : $keys['code'][$owner],
            $owner === null ? null : $keys['enc'][$owner],
            $owner === null ? null : $keys['prefix'][$owner],
            $subjectType,
            $subjectId,
        );
        $notes = [$note(1, 'One', 0, 'im-posts', 1), $note(2, 'Two', 0, 'im-videos', 1), $note(3, 'Three', 1, 'im-posts', 1), $note(4, 'Four', null, 'im-videos', 2)];
        $notes[0]->targetType = 'im-uuid-owners';
        $notes[0]->targetId = $keys['uuid'][0];
        // A text pair holding a guid and a uuid in capitals, and a prefixed owner in both stored forms.
        $notes[1]->refType = 'im-guid-owners';
        $notes[1]->refId = \strtoupper($keys['guid'][0]);
        $notes[2]->refType = 'im-uuid-owners';
        $notes[2]->refId = \strtoupper($keys['uuid'][1]->toRfc4122());
        $notes[0]->refType = 'im-prefix-owners';
        $notes[0]->refId = $keys['prefix'][0]->toRfc4122();
        $notes[3]->refType = 'im-prefix-owners';
        $notes[3]->refId = 'own_' . $keys['prefix'][0]->toRfc4122();

        $posts = [new ImPost(1, 'Post one'), new ImPost(2, 'Post two')];
        $videos = [new ImVideo(1, 'Video one'), new ImVideo(2, 'Video two')];

        [$first, $second] = $owners[ImUuidOwner::class];
        $links = [
            new ImLink(1, $first, 'im-uuid-owners', $keys['uuid'][1]),
            new ImLink(2, $first, 'im-uuid-owners', $keys['uuid'][2]),
            new ImLink(3, $second, 'im-uuid-owners', $keys['uuid'][0]),
        ];
        $first->links = new ArrayCollection([$links[0], $links[1]]);
        $second->links = new ArrayCollection([$links[2]]);

        return [ImNote::class => $notes, ImPost::class => $posts, ImVideo::class => $videos, ImLink::class => $links, ...$owners];
    }
}
