<?php
declare(strict_types=1);
namespace Neos\EventSourcing\Projection;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\EntityManagerInterface;
use Neos\EventSourcing\EventListener\EventListenerInvoker;
use Neos\EventSourcing\EventListener\EventListenerLocator;
use Neos\EventSourcing\EventListener\Exception\EventCouldNotBeAppliedException;
use Neos\EventSourcing\EventStore\EventStoreManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ClassReflection;
use Neos\Flow\Reflection\Exception\ClassLoadingForReflectionFailedException;
use Neos\Flow\Reflection\ReflectionService;

/**
 * Central authority for managing projections
 *
 * @api
 * @Flow\Scope("singleton")
 */
class ProjectionManager
{
    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var EventListenerLocator
     */
    protected $eventListenerLocator;

    /**
     * @Flow\Inject
     * @var EventStoreManager
     */
    protected $eventStoreManager;

    /**
     * @var array in the format ['<projectionIdentifier>' => '<projectorClassName>', ...]
     */
    private $projections = [];

    /**
     * Register event listeners based on annotations
     * @throws ClassLoadingForReflectionFailedException
     */
    protected function initializeObject(): void
    {
        $this->projections = self::detectProjectors($this->objectManager);
    }

    /**
     * Return all detected projections
     *
     * @return Projection[]
     * @api
     */
    public function getProjections(): array
    {
        return array_map([$this, 'getProjection'], array_keys($this->projections));
    }

    /**
     * Returns information about a specific projection in form of a Projection DTO
     *
     * @param string $projectionIdentifier The short or full projection identifier
     * @return Projection
     * @api
     */
    public function getProjection(string $projectionIdentifier): Projection
    {
        $fullProjectionIdentifier = $this->normalizeProjectionIdentifier($projectionIdentifier);
        $projectorClassName = $this->projections[$fullProjectionIdentifier];
        $eventClassNames = $this->eventListenerLocator->getEventClassNamesByListenerClassName($projectorClassName);
        return new Projection($fullProjectionIdentifier, $projectorClassName, $eventClassNames);
    }

    /**
     * Tells if the specified projection is currently empty
     *
     * @param string $projectionIdentifier
     * @return bool
     */
    public function isProjectionEmpty(string $projectionIdentifier): bool
    {
        $fullProjectionIdentifier = $this->normalizeProjectionIdentifier($projectionIdentifier);
        $projectorClassName = $this->projections[$fullProjectionIdentifier];
        $projector = $this->objectManager->get($projectorClassName);
        return $projector->isEmpty();
    }

    /**
     * Replay events of the specified projection
     *
     * @param string $projectionIdentifier unambiguous identifier of the projection to replay
     * @param \Closure $progressCallback If set, this callback is invoked for every applied event during replay with the arguments $sequenceNumber and $eventStreamVersion
     * @param int $transactionBatchSize
     * @return void
     * @api
     * @throws EventCouldNotBeAppliedException
     */
    public function replay(string $projectionIdentifier, \Closure $progressCallback = null, int $transactionBatchSize = 1000): void
    {
        $projection = $this->getProjection($projectionIdentifier);

        $eventStore = $this->eventStoreManager->getEventStoreForEventListener($projection->getProjectorClassName());

        /** @var ProjectorInterface $projector */
        $projector = $this->objectManager->get($projection->getProjectorClassName());
        $projector->reset();

        $connection = $this->objectManager->get(EntityManagerInterface::class)->getConnection();
        $eventListenerInvoker = new EventListenerInvoker($eventStore, $projector, $connection);
        if ($progressCallback !== null) {
            $eventListenerInvoker->onProgress($progressCallback);
        }
        $eventListenerInvoker
            ->withTransactionBatchSize($transactionBatchSize)
            ->replay();
    }

    /**
     * Takes a short projection identifier and returns the "full" identifier if valid
     *
     * @param string $projectionIdentifier in the form "<package.key>:<projection>", "<key>:<projection>" or "<projection">"
     * @return string
     */
    private function normalizeProjectionIdentifier(string $projectionIdentifier): string
    {
        $matchingIdentifiers = [];
        foreach (array_keys($this->projections) as $fullProjectionIdentifier) {
            if ($this->projectionIdentifiersMatch($projectionIdentifier, $fullProjectionIdentifier)) {
                $matchingIdentifiers[] = $fullProjectionIdentifier;
            }
        }
        if ($matchingIdentifiers === []) {
            throw new \InvalidArgumentException(sprintf('No projection could be found that matches the projection identifier "%s"', $projectionIdentifier), 1476368605);
        }
        if (count($matchingIdentifiers) !== 1) {
            throw new \InvalidArgumentException(sprintf('More than one projection matches the projection identifier "%s":%s%s', $projectionIdentifier, chr(10), implode(', ', $matchingIdentifiers)), 1476368615);
        }
        return $matchingIdentifiers[0];
    }

    /**
     * Determines whether the given $shortIdentifier in the form "<package.key>:<projection>", "<key>:<projection>" or "<projection">"
     * matches the $fullIdentifier in the form "<package.key>:<projection>"
     *
     * @param string $shortIdentifier
     * @param string $fullIdentifier The full projection identifier
     * @return bool
     * @see normalizeProjectionIdentifier()
     */
    private function projectionIdentifiersMatch(string $shortIdentifier, string $fullIdentifier): bool
    {
        $shortIdentifier = strtolower($shortIdentifier);
        if ($shortIdentifier === $fullIdentifier) {
            return true;
        }
        $shortIdentifierParts = explode(':', $shortIdentifier);
        $fullIdentifierParts = explode(':', $fullIdentifier);

        $shortIdentifierPartsCount = count($shortIdentifierParts);
        if ($shortIdentifierPartsCount === 1) {
            return $shortIdentifier === $fullIdentifierParts[1];
        }
        if ($shortIdentifierPartsCount !== 2) {
            throw new \InvalidArgumentException(sprintf('Invalid projection identifier "%s", identifiers must have the format "<projection>" or "<package-key>:<projection>".', $shortIdentifier), 1476367741);
        }
        return
            $shortIdentifierParts[1] === $fullIdentifierParts[1]
            && substr($fullIdentifierParts[0], -(strlen($shortIdentifierParts[0]) + 1)) === '.' . $shortIdentifierParts[0];
    }

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array
     * @Flow\CompileStatic
     * @throws ClassLoadingForReflectionFailedException
     */
    protected static function detectProjectors($objectManager): array
    {
        /** @var ReflectionService $reflectionService */
        $reflectionService = $objectManager->get(ReflectionService::class);
        $projections = [];
        foreach ($reflectionService->getAllImplementationClassNamesForInterface(ProjectorInterface::class) as $projectorClassName) {
            $projectionName = (new ClassReflection($projectorClassName))->getShortName();
            if (substr($projectionName, -9) === 'Projector') {
                $projectionName = substr($projectionName, 0, -9);
            }
            $projectionName = strtolower($projectionName);
            $packageKey = strtolower($objectManager->getPackageKeyByObjectName($projectorClassName));
            $projectionIdentifier = $packageKey . ':' . $projectionName;
            if (isset($projections[$projectionIdentifier])) {
                throw new \RuntimeException(sprintf('The projection identifier "%s" is ambiguous, please rename one of the classes "%s" or "%s"', $projectionIdentifier, $projections[$projectionIdentifier], $projectorClassName), 1476198478);
            }
            $projections[$projectionIdentifier] = $projectorClassName;
        }
        ksort($projections);
        return $projections;
    }
}
