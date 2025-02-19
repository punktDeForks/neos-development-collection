<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Factory;

use Neos\ContentRepository\Core\CommandHandler\CommandBus;
use Neos\ContentRepository\Core\CommandHandler\CommandHandlingDependencies;
use Neos\ContentRepository\Core\CommandHandler\CommandSimulatorFactory;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\EventStore\EventPersister;
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\DimensionSpaceCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeAggregateCommandHandler;
use Neos\ContentRepository\Core\Feature\NodeDuplication\NodeDuplicationCommandHandler;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandHandler;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ProjectionsAndCatchUpHooks;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\Factory\AuthProvider\AuthProviderFactoryInterface;
use Neos\EventStore\EventStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Main factory to build a {@see ContentRepository} object.
 *
 * @api
 */
final class ContentRepositoryFactory
{
    private ProjectionFactoryDependencies $projectionFactoryDependencies;
    private ProjectionsAndCatchUpHooks $projectionsAndCatchUpHooks;

    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        EventStoreInterface $eventStore,
        NodeTypeManager $nodeTypeManager,
        ContentDimensionSourceInterface $contentDimensionSource,
        Serializer $propertySerializer,
        ProjectionsAndCatchUpHooksFactory $projectionsAndCatchUpHooksFactory,
        private readonly AuthProviderFactoryInterface $authProviderFactory,
        private readonly ClockInterface $clock,
        private readonly CommandHooksFactory $commandHooksFactory,
    ) {
        $contentDimensionZookeeper = new ContentDimensionZookeeper($contentDimensionSource);
        $interDimensionalVariationGraph = new InterDimensionalVariationGraph(
            $contentDimensionSource,
            $contentDimensionZookeeper
        );
        $this->projectionFactoryDependencies = new ProjectionFactoryDependencies(
            $contentRepositoryId,
            $eventStore,
            new EventNormalizer(),
            $nodeTypeManager,
            $contentDimensionSource,
            $contentDimensionZookeeper,
            $interDimensionalVariationGraph,
            new PropertyConverter($propertySerializer),
        );
        $this->projectionsAndCatchUpHooks = $projectionsAndCatchUpHooksFactory->build($this->projectionFactoryDependencies);
    }

    // guards against recursion and memory overflow
    private bool $isBuilding = false;

    // The following properties store "singleton" references of objects for this content repository
    private ?ContentRepository $contentRepository = null;
    private ?EventPersister $eventPersister = null;

    /**
     * Builds and returns the content repository. If it is already built, returns the same instance.
     *
     * @return ContentRepository
     * @api
     */
    public function getOrBuild(): ContentRepository
    {
        if ($this->contentRepository) {
            return $this->contentRepository;
        }
        if ($this->isBuilding) {
            throw new \RuntimeException(sprintf('Content repository "%s" was attempted to be build in recursion.', $this->contentRepositoryId->value), 1730552199);
        }
        $this->isBuilding = true;

        $contentGraphReadModel = $this->projectionsAndCatchUpHooks->contentGraphProjection->getState();
        $commandHandlingDependencies = new CommandHandlingDependencies($contentGraphReadModel);

        // we dont need full recursion in rebase - e.g apply workspace commands - and thus we can use this set for simulation
        $commandBusForRebaseableCommands = new CommandBus(
            $commandHandlingDependencies,
            new NodeAggregateCommandHandler(
                $this->projectionFactoryDependencies->nodeTypeManager,
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
                $this->projectionFactoryDependencies->propertyConverter,
            ),
            new DimensionSpaceCommandHandler(
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            ),
            new NodeDuplicationCommandHandler(
                $this->projectionFactoryDependencies->nodeTypeManager,
                $this->projectionFactoryDependencies->contentDimensionZookeeper,
                $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            )
        );

        $commandSimulatorFactory = new CommandSimulatorFactory(
            $this->projectionsAndCatchUpHooks->contentGraphProjection,
            $this->projectionFactoryDependencies->eventNormalizer,
            $commandBusForRebaseableCommands
        );

        $publicCommandBus = $commandBusForRebaseableCommands->withAdditionalHandlers(
            new WorkspaceCommandHandler(
                $commandSimulatorFactory,
                $this->projectionFactoryDependencies->eventStore,
                $this->projectionFactoryDependencies->eventNormalizer,
            )
        );
        $authProvider = $this->authProviderFactory->build($this->contentRepositoryId, $contentGraphReadModel);
        $commandHooks = $this->commandHooksFactory->build(CommandHooksFactoryDependencies::create(
            $this->contentRepositoryId,
            $this->projectionsAndCatchUpHooks->contentGraphProjection->getState(),
            $this->projectionFactoryDependencies->nodeTypeManager,
            $this->projectionFactoryDependencies->contentDimensionSource,
            $this->projectionFactoryDependencies->interDimensionalVariationGraph,
        ));
        $this->contentRepository = new ContentRepository(
            $this->contentRepositoryId,
            $publicCommandBus,
            $this->projectionFactoryDependencies->eventStore,
            $this->projectionsAndCatchUpHooks,
            $this->projectionFactoryDependencies->eventNormalizer,
            $this->buildEventPersister(),
            $this->projectionFactoryDependencies->nodeTypeManager,
            $this->projectionFactoryDependencies->interDimensionalVariationGraph,
            $this->projectionFactoryDependencies->contentDimensionSource,
            $authProvider,
            $this->clock,
            $contentGraphReadModel,
            $commandHooks,
        );
        $this->isBuilding = false;
        return $this->contentRepository;
    }

    /**
     * A service is a high-level "application part" which builds upon the CR internals.
     *
     * You don't usually need this yourself, but it is usually enough to simply use the {@see ContentRepository}
     * instance. If you want to extend the CR core and need to hook deeply into CR internals, this is what the
     * {@see ContentRepositoryServiceInterface} is for.
     *
     * @template T of ContentRepositoryServiceInterface
     * @param ContentRepositoryServiceFactoryInterface<T> $serviceFactory
     * @return T
     */
    public function buildService(
        ContentRepositoryServiceFactoryInterface $serviceFactory
    ): ContentRepositoryServiceInterface {

        $serviceFactoryDependencies = ContentRepositoryServiceFactoryDependencies::create(
            $this->projectionFactoryDependencies,
            $this->getOrBuild(),
            $this->buildEventPersister(),
            $this->projectionsAndCatchUpHooks,
        );
        return $serviceFactory->build($serviceFactoryDependencies);
    }

    private function buildEventPersister(): EventPersister
    {
        if (!$this->eventPersister) {
            $this->eventPersister = new EventPersister(
                $this->projectionFactoryDependencies->eventStore,
                $this->projectionFactoryDependencies->eventNormalizer,
            );
        }
        return $this->eventPersister;
    }
}
