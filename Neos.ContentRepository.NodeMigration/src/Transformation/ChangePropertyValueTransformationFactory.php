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
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetSerializedNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Change the value of a given property.
 *
 * This can apply two transformations:
 *
 * If newValue is set, the value will be set to this, with any occurrences of the currentValuePlaceholder replaced with
 * the current value of the property.
 *
 * If search and replace are given, that replacement will be done on the value (after applying the newValue if set).
 */
class ChangePropertyValueTransformationFactory implements TransformationFactoryInterface
{
    /**
     * @param array<string,string> $settings
     */
    public function build(
        array $settings,
        ContentRepository $contentRepository,
        PropertyConverter $propertyConverter,
    ): GlobalTransformationInterface|NodeAggregateBasedTransformationInterface|NodeBasedTransformationInterface {
        $newSerializedValue = '{current}';
        if (isset($settings['newSerializedValue'])) {
            $newSerializedValue = $settings['newSerializedValue'];
        }

        $search = '';
        if (isset($settings['search'])) {
            $search = $settings['search'];
        }

        $replace = '';
        if (isset($settings['replace'])) {
            $replace = $settings['replace'];
        }

        $currentValuePlaceholder = '{current}';
        if (isset($settings['currentValuePlaceholder'])) {
            $currentValuePlaceholder = $settings['currentValuePlaceholder'];
        }

        return new class (
            $settings['property'],
            $newSerializedValue,
            $search,
            $replace,
            $currentValuePlaceholder,
            $contentRepository,
            $propertyConverter,
        ) implements NodeBasedTransformationInterface {
            public function __construct(
                /**
                 * The name of the property to change.
                 */
                private readonly string $propertyName,
                /**
                 * New property value to be set.
                 *
                 * The value of the option "currentValuePlaceholder" (defaults to "{current}") will be
                 * used to include the current property value into the new value.
                 */
                private readonly string $newSerializedValue,
                /**
                 * Search string to replace in current property value.
                 */
                private readonly string $search,
                /**
                 * Replacement for the search string
                 */
                private readonly string $replace,
                /**
                 * The value of this option (defaults to "{current}") will be used to include the
                 * current property value into the new value.
                 */
                private readonly string $currentValuePlaceholder,
                private readonly ContentRepository $contentRepository,
                private readonly PropertyConverter $propertyConverter,
            ) {
            }

            public function execute(
                Node $node,
                DimensionSpacePointSet $coveredDimensionSpacePoints,
                WorkspaceName $workspaceNameForWriting,
                ContentStreamId $contentStreamForWriting
            ): void {
                $currentProperty = $node->properties->serialized()->getProperty($this->propertyName);
                if ($currentProperty !== null) {
                    $value = $currentProperty->value;
                    if (!is_string($value) && !is_array($value)) {
                        throw new \Exception(
                            'ChangePropertyValue can only be executed on properties'
                                . ' with serialized type string or array.',
                            1645391685
                        );
                    }
                    $newValueWithReplacedCurrentValue = str_replace(
                        $this->currentValuePlaceholder,
                        $value,
                        $this->newSerializedValue
                    );
                    $newValueWithReplacedSearch = str_replace(
                        $this->search,
                        $this->replace,
                        $newValueWithReplacedCurrentValue
                    );
                    $deserializedPropertyValue = $this->propertyConverter->deserializePropertyValue(SerializedPropertyValue::create($newValueWithReplacedSearch, $currentProperty->type));  // @phpstan-ignore neos.cr.internal

                    $this->contentRepository->handle(
                        SetNodeProperties::create(
                            $workspaceNameForWriting,
                            $node->aggregateId,
                            $node->originDimensionSpacePoint,
                            PropertyValuesToWrite::fromArray([
                                $this->propertyName => $deserializedPropertyValue,
                            ])
                        )
                    );
                }
            }
        };
    }
}
