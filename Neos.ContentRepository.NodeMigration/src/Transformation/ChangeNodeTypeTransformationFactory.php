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

namespace Neos\ContentRepository\NodeMigration\Transformation;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Command\ChangeNodeAggregateType;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Dto\NodeAggregateTypeChangeChildConstraintConflictResolutionStrategy;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/** @codingStandardsIgnoreStart */
/** @codingStandardsIgnoreEnd */

/**
 * Change the node type.
 */
class ChangeNodeTypeTransformationFactory implements TransformationFactoryInterface
{
    /**
     * @param array<string,mixed> $settings
     */
    public function build(
        array $settings,
        ContentRepository $contentRepository,
        PropertyConverter $propertyConverter,
    ): GlobalTransformationInterface|NodeAggregateBasedTransformationInterface|NodeBasedTransformationInterface {
        // by default, we won't delete anything.
        $nodeAggregateTypeChangeChildConstraintConflictResolutionStrategy
            = NodeAggregateTypeChangeChildConstraintConflictResolutionStrategy::STRATEGY_HAPPY_PATH;
        if (isset($settings['forceDeleteNonMatchingChildren']) && $settings['forceDeleteNonMatchingChildren']) {
            $nodeAggregateTypeChangeChildConstraintConflictResolutionStrategy
                = NodeAggregateTypeChangeChildConstraintConflictResolutionStrategy::STRATEGY_DELETE;
        }

        return new class (
            $settings['newType'],
            $nodeAggregateTypeChangeChildConstraintConflictResolutionStrategy,
            $contentRepository
        ) implements NodeAggregateBasedTransformationInterface {
            public function __construct(
                /**
                 * The new Node Type to use as a string
                 */
                private readonly string $newType,
                private readonly NodeAggregateTypeChangeChildConstraintConflictResolutionStrategy $strategy,
                private readonly ContentRepository $contentRepository
            ) {
            }

            public function execute(
                NodeAggregate $nodeAggregate,
                WorkspaceName $workspaceNameForWriting,
                ContentStreamId $contentStreamForWriting
            ): void {
                $this->contentRepository->handle(ChangeNodeAggregateType::create(
                    $workspaceNameForWriting,
                    $nodeAggregate->nodeAggregateId,
                    NodeTypeName::fromString($this->newType),
                    $this->strategy,
                ));
            }
        };
    }
}
