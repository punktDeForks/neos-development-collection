<?php

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\DimensionSpacePointsRepository;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\NodeFactory;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\ProjectionContentGraph;
use Neos\ContentRepository\Core\ContentGraphFinder;
use Neos\ContentRepository\Core\Factory\ProjectionFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjection;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * Use this class as ProjectionFactory in your configuration to construct a content graph
 *
 * @implements ProjectionFactoryInterface<ContentGraphProjection>
 *
 * @api
 */
final class DoctrineDbalContentGraphProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly Connection $dbal,
    ) {
    }

    public function build(
        ProjectionFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): ContentGraphProjection {
        $tableNames = ContentGraphTableNames::create(
            $projectionFactoryDependencies->contentRepositoryId
        );

        $dimensionSpacePointsRepository = new DimensionSpacePointsRepository($this->dbal, $tableNames);

        $nodeFactory = new NodeFactory(
            $projectionFactoryDependencies->contentRepositoryId,
            $projectionFactoryDependencies->nodeTypeManager,
            $projectionFactoryDependencies->propertyConverter,
            $dimensionSpacePointsRepository
        );

        $contentGraphFactory = new ContentGraphFactory(
            $this->dbal,
            $nodeFactory,
            $projectionFactoryDependencies->contentRepositoryId,
            $projectionFactoryDependencies->nodeTypeManager,
            $tableNames
        );

        return new ContentGraphProjection(
            new DoctrineDbalContentGraphProjection(
                $this->dbal,
                new ProjectionContentGraph(
                    $this->dbal,
                    $tableNames
                ),
                $tableNames,
                $dimensionSpacePointsRepository,
                new ContentGraphFinder($contentGraphFactory)
            )
        );
    }
}
