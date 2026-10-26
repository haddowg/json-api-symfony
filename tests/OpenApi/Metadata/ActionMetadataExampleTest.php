<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\OpenApi\Metadata\ActionMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionMetadataExampleTest extends TestCase
{
    #[Test]
    public function anActionWithoutAnExampleKeepsTheGeneratedOne(): void
    {
        self::assertNull((new ActionMetadata($this->descriptor(null)))->example());
    }

    #[Test]
    public function falseSwitchesTheActionExampleOff(): void
    {
        self::assertFalse((new ActionMetadata($this->descriptor(false)))->example());
    }

    #[Test]
    public function aDeclaredExampleIsRebuiltFromItsScalarForm(): void
    {
        $metadata = new ActionMetadata($this->descriptor(['request' => false, 'responses' => [200 => ['meta' => ['ok' => true]]]]));

        self::assertEquals(new OperationExample(request: false, responses: [200 => ['meta' => ['ok' => true]]]), $metadata->example());
    }

    #[Test]
    public function theDescriptorsSafetyAndExtensionsAreDeclared(): void
    {
        $metadata = new ActionMetadata(new ActionDescriptor(
            type: 'widgets',
            path: 'purge',
            methods: ['POST'],
            scope: ActionScope::Resource,
            input: ActionInput::None,
            inputType: 'widgets',
            outputType: 'widgets',
            responds: [['kind' => 'nocontent']],
            security: null,
            handlerServiceId: 'app.purge',
            server: 'default',
            destructive: true,
            idempotent: true,
            extensions: ['x-workflows' => ['workflowId' => 'purgeWidget']],
        ));

        self::assertNull($metadata->readOnly());
        self::assertTrue($metadata->destructive());
        self::assertTrue($metadata->idempotent());
        self::assertSame(['x-workflows' => ['workflowId' => 'purgeWidget']], $metadata->extensions());
    }

    /**
     * @param array{request: mixed, responses: array<int|string, mixed>}|false|null $example
     */
    private function descriptor(array|false|null $example): ActionDescriptor
    {
        return new ActionDescriptor(
            type: 'widgets',
            path: 'publish',
            methods: ['POST'],
            scope: ActionScope::Resource,
            input: ActionInput::None,
            inputType: 'widgets',
            outputType: 'widgets',
            responds: [['kind' => 'meta']],
            security: null,
            handlerServiceId: 'app.publish',
            server: 'default',
            example: $example,
        );
    }
}
