<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationDefinition;
use haddowg\JsonApi\Catalog\SecurityEvaluatorInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\Catalog\SecurityVerdict;
use haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate\CatalogGateKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The catalogue judges a related or relationship read by every gate its endpoint
 * applies: the relation's read permission, else the parent's read gate and, for a
 * to-many, the collection read gate of each type it relates to. Each verdict is checked
 * against the answer the endpoint gives the same user.
 */
#[Group('scopes')]
final class CatalogRelatedReadGateTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return CatalogGateKernel::class;
    }

    /**
     * @param list<string> $roles
     */
    #[Test]
    #[TestWith(['fetchRelated.spokes', '/hubs/1/spokes', ['ROLE_USER'], SecurityVerdict::Denied, 403], 'fetchRelated of a to-many a user may not list')]
    #[TestWith(['fetchRelated.spokes', '/hubs/1/spokes', ['ROLE_ADMIN', 'ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many an admin may list')]
    #[TestWith(['fetchRelated.rims', '/hubs/1/rims', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many over an ungated type')]
    #[TestWith(['fetchRelated.parts', '/hubs/1/parts', ['ROLE_USER'], SecurityVerdict::Denied, 403], 'fetchRelated of a polymorphic to-many with one refusing type')]
    #[TestWith(['fetchRelated.parts', '/hubs/1/parts', ['ROLE_ADMIN', 'ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelated of a polymorphic to-many every type admits')]
    #[TestWith(['fetchRelated.sharedSpokes', '/hubs/1/sharedSpokes', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelated of a to-many with its own read permission')]
    #[TestWith(['fetchRelated.leadSpoke', '/hubs/1/leadSpoke', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelated of a to-one')]
    #[TestWith(['fetchRelationship.spokes', '/hubs/1/relationships/spokes', ['ROLE_USER'], SecurityVerdict::Denied, 403], 'fetchRelationship of a to-many a user may not list')]
    #[TestWith(['fetchRelationship.spokes', '/hubs/1/relationships/spokes', ['ROLE_ADMIN', 'ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many an admin may list')]
    #[TestWith(['fetchRelationship.rims', '/hubs/1/relationships/rims', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many over an ungated type')]
    #[TestWith(['fetchRelationship.parts', '/hubs/1/relationships/parts', ['ROLE_USER'], SecurityVerdict::Denied, 403], 'fetchRelationship of a polymorphic to-many with one refusing type')]
    #[TestWith(['fetchRelationship.parts', '/hubs/1/relationships/parts', ['ROLE_ADMIN', 'ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelationship of a polymorphic to-many every type admits')]
    #[TestWith(['fetchRelationship.sharedSpokes', '/hubs/1/relationships/sharedSpokes', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-many with its own read permission')]
    #[TestWith(['fetchRelationship.leadSpoke', '/hubs/1/relationships/leadSpoke', ['ROLE_USER'], SecurityVerdict::Allowed, 200], 'fetchRelationship of a to-one')]
    public function theVerdictMatchesWhatTheEndpointAnswers(string $operation, string $path, array $roles, SecurityVerdict $verdict, int $status): void
    {
        $user = new InMemoryUser(\in_array('ROLE_ADMIN', $roles, true) ? 'admin' : 'ada', null, $roles);

        self::assertSame($verdict, $this->evaluator()->evaluate($this->operation('hubs', $operation), $user));

        $response = $this->handle($path, 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user->getUserIdentifier()]);
        self::assertSame($status, $response->getStatusCode(), $path . ': ' . $response->getContent());
    }

    #[Test]
    public function aToManyCombinesTheParentReadWithEachRelatedCollectionGate(): void
    {
        $spokes = $this->operation('hubs', 'fetchRelated.spokes');
        self::assertSame("(is_granted('ROLE_USER')) and (is_granted('ROLE_ADMIN'))", $spokes->security);
        self::assertSame(SecurityLevel::Type, $spokes->securityLevel);

        $shared = $this->operation('hubs', 'fetchRelationship.sharedSpokes');
        self::assertSame("is_granted('ROLE_USER')", $shared->security);

        $lead = $this->operation('hubs', 'fetchRelated.leadSpoke');
        self::assertSame("is_granted('ROLE_USER')", $lead->security);
        self::assertSame(SecurityLevel::Type, $lead->securityLevel);
    }

    #[Test]
    public function aParentReadThatReadsTheHubLeavesTheVerdictToTheHub(): void
    {
        $spokes = $this->operation('ownedHubs', 'fetchRelated.spokes');
        self::assertSame("(object.owner == user.getUserIdentifier()) and (is_granted('ROLE_ADMIN'))", $spokes->security);
        self::assertSame(SecurityLevel::Entity, $spokes->securityLevel);
        self::assertSame(SecurityVerdict::Depends, $this->evaluator()->evaluate($spokes, new InMemoryUser('ada', null, ['ROLE_USER'])));
        $response = $this->handle('/ownedHubs/1/spokes', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ada']);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function aFirewalledParentReadJoinsAsAnAuthenticatedCaller(): void
    {
        $spokes = $this->operation('firewalledHubs', 'fetchRelated.spokes');
        self::assertSame("(is_authenticated()) and (is_granted('ROLE_ADMIN'))", $spokes->security);
        self::assertSame(SecurityLevel::Type, $spokes->securityLevel);
        self::assertTrue($this->operation('firewalledHubs', 'fetchRelationship.rims')->security);

        $evaluator = $this->evaluator();
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($this->operation('firewalledHubs', 'fetchRelationship.rims'), new InMemoryUser('ada', null, ['ROLE_USER'])));
        self::assertSame(SecurityVerdict::Allowed, $evaluator->evaluate($spokes, new InMemoryUser('admin', null, ['ROLE_ADMIN', 'ROLE_USER'])));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($spokes, new InMemoryUser('ada', null, ['ROLE_USER'])));
        self::assertSame(SecurityVerdict::Denied, $evaluator->evaluate($spokes));

        self::assertSame(200, $this->handle('/firewalledHubs/1/spokes', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer admin'])->getStatusCode());
        self::assertSame(403, $this->handle('/firewalledHubs/1/spokes', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ada'])->getStatusCode());
    }

    #[Test]
    public function anIncludedToManyAUserMayNotListIsStillWithheldWhole(): void
    {
        $response = $this->handle('/hubs/1?include=spokes,rims,leadSpoke', 'GET', null, ['HTTP_AUTHORIZATION' => 'Bearer ada']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertIsArray($relationships['spokes'] ?? null);
        self::assertArrayNotHasKey('data', $relationships['spokes']);
        self::assertIsArray($relationships['rims'] ?? null);
        self::assertSame([['type' => 'rims', 'id' => '1']], $relationships['rims']['data'] ?? null);
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        $types = \array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['type'] ?? null) && \is_string($resource['id'] ?? null) ? $resource['type'] . '/' . $resource['id'] : '', $included);
        \sort($types);
        self::assertSame(['rims/1', 'spokes/1'], $types);
    }

    private function evaluator(): SecurityEvaluatorInterface
    {
        $evaluator = static::getContainer()->get(SecurityEvaluatorInterface::class);
        self::assertInstanceOf(SecurityEvaluatorInterface::class, $evaluator);

        return $evaluator;
    }

    private function operation(string $type, string $name): OperationDefinition
    {
        $catalog = static::getContainer()->get(OperationCatalog::class);
        self::assertInstanceOf(OperationCatalog::class, $catalog);
        foreach ($catalog->forServer('default') as $operation) {
            if ($operation->type === $type && $operation->key->name() === $name) {
                return $operation;
            }
        }

        self::fail(\sprintf('No operation %s on %s.', $name, $type));
    }
}
