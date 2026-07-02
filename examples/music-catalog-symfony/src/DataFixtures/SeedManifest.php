<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures;

/**
 * The single source of truth for the example's seed *facts* — the identities the
 * firewall authenticates, who owns the seeded playlist, and the server-default page
 * cap. Those facts were previously duplicated as literals across three places (the
 * `security.yaml` user provider, the seed fixtures, and the functional tests) over a
 * build-time-rebuilt Docker image, so a change had to be kept in lockstep by hand.
 *
 * Everything that needs a seed fact now reads it from here:
 *  - `config/packages/security.yaml` builds its in-memory user provider from
 *    {@see USERS} via the `!php/const` YAML tag;
 *  - `config/packages/json_api.yaml` sets `pagination.max_per_page` from
 *    {@see MAX_PER_PAGE};
 *  - {@see Seed} / {@see DemoSeed} seed the owned playlist ({@see OWNED_PLAYLIST_ID})
 *    to its owner ({@see OWNER});
 *  - the functional suites read the same constants instead of re-declaring literals.
 *
 * Change a fact once, here, and the runtime config, the fixtures and the tests all
 * move together.
 */
final class SeedManifest
{
    /** The playlist-owner identity — owns the seeded "Morning Mix" and reaches the admin server. */
    public const string OWNER = 'ada@example.com';

    /** A non-owner identity — a `ROLE_USER` the ownership Voter refuses. */
    public const string NON_OWNER = 'mallory@example.com';

    /** The admin identity — the only role allowed to delete a playlist. */
    public const string ADMIN = 'admin';

    /** The hand-set UUID of the seeded "Morning Mix" playlist ({@see OWNER}'s). */
    public const string OWNED_PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    /**
     * The server default paginator's `page[size]`/`page[limit]` cap (config's
     * `json_api.pagination.max_per_page`). Set below core's default of 100 so the
     * clamp is witnessable; the demo catalogue is sized so a `page[size]` at the cap
     * shows everything in one page.
     */
    public const int MAX_PER_PAGE = 50;

    /**
     * The in-memory firewall's users, shaped for Symfony's `memory` user provider
     * (`config/packages/security.yaml` reads this via `!php/const`). The username is
     * both the user identifier the `PlaylistOwnerVoter` compares against the playlist
     * owner's email AND the Bearer token a client presents (the token IS the
     * identifier in this example — a real app maps an opaque token). All three mirror
     * the authorization suite: the admin (delete), the owner (update), and a
     * non-owner (refused).
     *
     * @var array<string, array{password: string, roles: list<string>}>
     */
    public const array USERS = [
        self::ADMIN => ['password' => 'pass', 'roles' => ['ROLE_ADMIN']],
        self::OWNER => ['password' => 'pass', 'roles' => ['ROLE_USER']],
        self::NON_OWNER => ['password' => 'pass', 'roles' => ['ROLE_USER']],
    ];
}
