<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\BinaryUuidTestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * {@see BinaryUuidConformanceTestCase} with no id encoder: the Doctrine layer
 * converts the wire id through the `uuid` type itself.
 */
#[Group('spec:crud')]
final class DoctrineBinaryUuidTest extends BinaryUuidConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return BinaryUuidTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Rendering an object id as its string is core's part; earlier cores render it empty.
        $model = new \stdClass();
        $model->id = Uuid::v4();
        if (!\is_string(Id::make()->build()->serializeWithoutRequest($model))) {
            self::markTestSkipped('The installed haddowg/json-api does not render a Stringable id as a string.');
        }
    }
}
