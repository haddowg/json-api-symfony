<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\Doctrine\RunEntity;

/**
 * Runs 1 to 3. Runs 1 and 2 have progress, run 3 has none. Run 1 has advertisers 1
 * and 2, run 2 advertiser 3, run 3 none. Run 1 has note 1, run 2 notes 2 and 3.
 */
final class RunFixtures
{
    /**
     * @return list<RunEntity>
     */
    public static function runs(): array
    {
        return [new RunEntity(1, 'First'), new RunEntity(2, 'Second'), new RunEntity(3, 'Third')];
    }

    /**
     * @return list<RunProgress>
     */
    public static function progress(): array
    {
        return [new RunProgress('p1', 1, 40), new RunProgress('p2', 2, 100)];
    }

    /**
     * @return list<RunAdvertiser>
     */
    public static function advertisers(): array
    {
        return [new RunAdvertiser(1, 1, 'Acme'), new RunAdvertiser(2, 1, 'Globex'), new RunAdvertiser(3, 2, 'Initech')];
    }

    /**
     * @return list<RunNote>
     */
    public static function notes(): array
    {
        return [new RunNote(1, 1, 'Started'), new RunNote(2, 2, 'Paused'), new RunNote(3, 2, 'Resumed')];
    }
}
