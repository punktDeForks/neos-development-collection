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
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Remove the property from nodes
 */
class RemovePropertyTransformationFactory implements TransformationFactoryInterface
{
    /**
     * @param array<string,string> $settings
     */
    public function build(
        array $settings,
        ContentRepository $contentRepository,
        PropertyConverter $propertyConverter,
    ): GlobalTransformationInterface|NodeAggregateBasedTransformationInterface|NodeBasedTransformationInterface {
        $propertyName = $settings['property'];
        return new class (
            $propertyName,
            $contentRepository
        ) implements NodeBasedTransformationInterface {
            public function __construct(
                /**
                 * the name of the property to be removed.
                 */
                private readonly string $propertyName,
                private readonly ContentRepository $contentRepository
            ) {
            }
            public function execute(
                Node $node,
                DimensionSpacePointSet $coveredDimensionSpacePoints,
                WorkspaceName $workspaceNameForWriting,
                ContentStreamId $contentStreamForWriting
            ): void {
                if ($node->hasProperty($this->propertyName)) {
                    $this->contentRepository->handle(
                        SetNodeProperties::create(
                            $workspaceNameForWriting,
                            $node->aggregateId,
                            $node->originDimensionSpacePoint,
                            PropertyValuesToWrite::fromArray([
                                $this->propertyName => null,
                            ]),
                        )
                    );
                }
            }
        };
    }
}
