<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

/**
 * Group names a client filters by, each standing for the stored names it covers.
 */
final class ThrGroups
{
    private const array TEMPLATES = [
        'onboarding' => ['Welcome', 'Reminder'],
        'feedback' => ['Survey'],
        'retired' => [],
    ];

    private const array SEASONS = [
        'early' => ['Spring sale'],
        'mid' => ['Summer launch'],
        'late' => ['Autumn', 'Winter'],
        'none' => [],
    ];

    /**
     * @param list<mixed> $groups
     *
     * @return list<string>
     */
    public static function templateNames(array $groups): array
    {
        return self::covered(self::TEMPLATES, $groups);
    }

    /**
     * @param list<mixed> $seasons
     *
     * @return list<string>
     */
    public static function campaignNames(array $seasons): array
    {
        return self::covered(self::SEASONS, $seasons);
    }

    /**
     * @param array<string, list<string>> $map
     * @param list<mixed>                 $groups
     *
     * @return list<string>
     */
    private static function covered(array $map, array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            foreach (\is_string($group) ? $map[$group] ?? [] : [] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
