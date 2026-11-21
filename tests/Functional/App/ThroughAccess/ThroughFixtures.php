<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\CrateEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\CrateMarkEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\LedgerEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\ParcelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\SafeEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\TagEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\VaultEntity;

/**
 * The rows both kernels serve. Crate 1 holds parcels 1 (ada, `apple`, tagged `red`) and
 * 2 (grace, `banana`, tagged `hidden`), crate 2 holds parcel 3 (ada, `cherry`, tagged
 * `hidden`) and crate 3 holds none. Crate 1 sits in vault `v1`, safe `s1`, ledger and
 * auditor `l1`, crate 2 in vault `v2`. Crates 1 and 2 are about parcels 2 and 3, and
 * crate 3 about vault `v1`; crate 1 is marked with parcel 1 and safe `s1`, crate 2 with
 * parcel 2.
 *
 * Parcels are scoped to their owner, tags marked secret to admins, vaults list only for
 * admins, safes read only for admins, and the `ledger` and `auditor` relations carry
 * their own read permissions.
 */
final class ThroughFixtures
{
    /**
     * @return array<string, list<object>>
     */
    public static function graph(): array
    {
        $v1 = new VaultEntity(1, 'v1');
        $v2 = new VaultEntity(2, 'v2');
        $s1 = new SafeEntity(1, 's1');
        $l1 = new LedgerEntity(1, 'l1');
        $red = new TagEntity(1, 'red');
        $hidden = new TagEntity(2, 'hidden', true);
        $one = new CrateEntity(1, 'alpha', $v1, $s1, $l1, $l1, 'parcels', '2');
        $two = new CrateEntity(2, 'beta', $v2, subjectType: 'parcels', subjectId: '3');
        $three = new CrateEntity(3, 'gamma', subjectType: 'vaults', subjectId: '1');

        return [
            'vaults' => [$v1, $v2],
            'safes' => [$s1],
            'ledgers' => [$l1],
            'tags' => [$red, $hidden],
            'crates' => [$one, $two, $three],
            'crateMarks' => [
                new CrateMarkEntity(1, $one, 'parcels', '1'),
                new CrateMarkEntity(2, $one, 'safes', '1'),
                new CrateMarkEntity(3, $two, 'parcels', '2'),
            ],
            'parcels' => [
                new ParcelEntity(1, 'ada', 'apple', $one, [$red]),
                new ParcelEntity(2, 'grace', 'banana', $one, [$hidden]),
                new ParcelEntity(3, 'ada', 'cherry', $two, [$hidden]),
            ],
        ];
    }
}
