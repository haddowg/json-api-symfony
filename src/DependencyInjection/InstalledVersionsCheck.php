<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection;

use Composer\InstalledVersions;

/**
 * Notices a compiled container that an upgrade of this bundle or of haddowg/json-api has
 * left behind (bundle ADR 0143). Symfony checks a non-debug container for freshness only
 * when it first builds it, so after an upgrade a cached container keeps running the old
 * service wiring. The container records the versions it was compiled against, and the
 * bundle compares them with Composer's at boot.
 *
 * Both sides come from Composer's static installed-packages data, so the check reads no
 * files beyond what Composer has already loaded.
 */
final class InstalledVersionsCheck
{
    /**
     * The container parameter holding the versions the container was compiled against.
     */
    public const string PARAMETER = 'haddowg_json_api.installed_versions';

    /**
     * @var list<string>
     */
    public const array PACKAGES = ['haddowg/json-api-symfony', 'haddowg/json-api'];

    /**
     * The installed version and reference of each of {@see PACKAGES}, null for one that
     * is not installed, or null altogether when Composer's runtime API is unavailable.
     *
     * @return array<string, array{version: string, reference: ?string}|null>|null
     */
    public static function installed(): ?array
    {
        if (!\class_exists(InstalledVersions::class)) {
            return null;
        }

        $versions = [];
        foreach (self::PACKAGES as $package) {
            $version = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
            $versions[$package] = $version !== null
                ? ['version' => $version, 'reference' => InstalledVersions::getReference($package)]
                : null;
        }

        return $versions;
    }

    /**
     * Throws when the versions a container was compiled against differ from the installed
     * ones. The versions are compared always, and the references only when both are known
     * and the version is a release: a development version such as dev-main or a path
     * repository may move its reference without the container being rebuilt.
     *
     * A container with no recorded versions at all was compiled by a bundle version from
     * before this check, so it is stale too. Nothing is checked when Composer's runtime API
     * was unavailable on either side, for a package either side does not know, or while
     * the command being run is cache:clear, which has to boot the stale container to
     * replace it.
     *
     * @param mixed                                                         $compiled  the recorded parameter: null when Composer's runtime API was unavailable at compile, false when the container has none
     * @param array<string, array{version: string, reference: ?string}|null>|null $installed what {@see installed()} returns
     * @param list<mixed>                                                   $argv      the command line, to recognise cache:clear
     *
     * @throws \LogicException when the container was compiled against other versions
     */
    public static function assertFresh(mixed $compiled, ?array $installed, string $environment, array $argv = []): void
    {
        if ($compiled === null || $installed === null || self::clearsTheCache($argv)) {
            return;
        }

        if (!\is_array($compiled)) {
            throw self::stale($environment, 'a version of haddowg/json-api-symfony that did not record them', self::describe($installed));
        }

        foreach ($installed as $package => $now) {
            $then = $compiled[$package] ?? null;
            if ($now === null || !\is_array($then) || !\is_string($then['version'] ?? null)) {
                continue;
            }

            $thenReference = \is_string($then['reference'] ?? null) ? $then['reference'] : null;
            $changed = $then['version'] !== $now['version']
                || ($thenReference !== null && $now['reference'] !== null && !self::isDevelopment($now['version']) && $thenReference !== $now['reference']);

            if ($changed) {
                /** @var array<string, array{version: string, reference: ?string}|null> $recorded */
                $recorded = \array_map(
                    static fn(mixed $entry): ?array => \is_array($entry) && \is_string($entry['version'] ?? null)
                        ? ['version' => $entry['version'], 'reference' => \is_string($entry['reference'] ?? null) ? $entry['reference'] : null]
                        : null,
                    $compiled,
                );

                throw self::stale($environment, self::describe($recorded), self::describe($installed));
            }
        }
    }

    private static function stale(string $environment, string $compiled, string $installed): \LogicException
    {
        return new \LogicException(\sprintf(
            'The "%1$s" container was compiled against %2$s, but %3$s is installed. It is a cached '
            . 'container from before an upgrade and still runs the old service wiring. Clear the cache '
            . 'for this environment, for example with "bin/console cache:clear --env=%1$s" or by deleting '
            . 'var/cache/%1$s.',
            $environment,
            $compiled,
            $installed,
        ));
    }

    /**
     * @param array<array-key, array{version: string, reference: ?string}|null> $versions
     */
    private static function describe(array $versions): string
    {
        $parts = [];
        foreach ($versions as $package => $entry) {
            if ($entry === null) {
                continue;
            }
            $parts[] = \sprintf(
                '%s %s%s',
                $package,
                $entry['version'],
                $entry['reference'] !== null ? '@' . \substr($entry['reference'], 0, 12) : '',
            );
        }

        return $parts !== [] ? \implode(', ', $parts) : 'no recorded versions';
    }

    private static function isDevelopment(string $version): bool
    {
        return \str_starts_with($version, 'dev-') || \str_ends_with($version, '-dev');
    }

    /**
     * Whether `$argv` runs cache:clear, under any abbreviation the console accepts.
     *
     * @param list<mixed> $argv
     */
    private static function clearsTheCache(array $argv): bool
    {
        foreach (\array_slice($argv, 1) as $argument) {
            if (!\is_string($argument) || $argument === '' || $argument[0] === '-') {
                continue;
            }

            $parts = \explode(':', $argument);
            if (\count($parts) === 2 && $parts[0] !== '' && $parts[1] !== ''
                && \str_starts_with('cache', $parts[0]) && \str_starts_with('clear', $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
