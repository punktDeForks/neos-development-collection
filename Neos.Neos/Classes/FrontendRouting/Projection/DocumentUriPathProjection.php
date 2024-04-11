<?php

declare(strict_types=1);

namespace Neos\Neos\FrontendRouting\Projection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Types\Types;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\Event\DimensionShineThroughWasAdded;
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\Event\DimensionSpacePointWasMoved;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\CoverageNodeMoveMapping;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\ParentNodeMoveDestination;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\SucceedingSiblingNodeMoveDestination;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Event\NodeAggregateTypeWasChanged;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeGeneralizationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodePeerVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeSpecializationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Event\RootNodeAggregateDimensionsWereUpdated;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Core\Infrastructure\DbalCheckpointStorage;
use Neos\ContentRepository\Core\Infrastructure\DbalSchemaDiff;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\CheckpointStorageStatusType;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Projection\WithMarkStaleInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\Exception\NodeNotFoundException;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * @implements ProjectionInterface<DocumentUriPathFinder>
 */
final class DocumentUriPathProjection implements ProjectionInterface, WithMarkStaleInterface
{
    public const COLUMN_TYPES_DOCUMENT_URIS = [
        'shortcutTarget' => Types::JSON,
    ];

    private DbalCheckpointStorage $checkpointStorage;
    private ?DocumentUriPathFinder $stateAccessor = null;

    /**
     * @var array<string, array<string, bool>>
     */
    private array $nodeTypeImplementsRuntimeCache = [];

    public function __construct(
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly Connection $dbal,
        private readonly string $tableNamePrefix,
    ) {
        $this->checkpointStorage = new DbalCheckpointStorage(
            $this->dbal,
            $this->tableNamePrefix . '_checkpoint',
            self::class
        );
    }

    public function setUp(): void
    {
        foreach ($this->determineRequiredSqlStatements() as $statement) {
            $this->dbal->executeStatement($statement);
        }
        $this->checkpointStorage->setUp();
    }

    public function status(): ProjectionStatus
    {
        $checkpointStorageStatus = $this->checkpointStorage->status();
        if ($checkpointStorageStatus->type === CheckpointStorageStatusType::ERROR) {
            return ProjectionStatus::error($checkpointStorageStatus->details);
        }
        if ($checkpointStorageStatus->type === CheckpointStorageStatusType::SETUP_REQUIRED) {
            return ProjectionStatus::setupRequired($checkpointStorageStatus->details);
        }
        try {
            $this->dbal->connect();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to connect to database: %s', $e->getMessage()));
        }
        try {
            $requiredSqlStatements = $this->determineRequiredSqlStatements();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to determine required SQL statements: %s', $e->getMessage()));
        }
        if ($requiredSqlStatements !== []) {
            return ProjectionStatus::setupRequired(sprintf('The following SQL statement%s required: %s', count($requiredSqlStatements) !== 1 ? 's are' : ' is', implode(chr(10), $requiredSqlStatements)));
        }
        return ProjectionStatus::ok();
    }

    /**
     * @return array<string>
     */
    private function determineRequiredSqlStatements(): array
    {
        $schemaManager = $this->dbal->getSchemaManager();
        if (!$schemaManager instanceof AbstractSchemaManager) {
            throw new \RuntimeException('Failed to retrieve Schema Manager', 1625653914);
        }
        $schema = (new DocumentUriPathSchemaBuilder($this->tableNamePrefix))->buildSchema($schemaManager);
        return DbalSchemaDiff::determineRequiredSqlStatements($this->dbal, $schema);
    }


    public function reset(): PromiseInterface
    {
        $this->truncateDatabaseTables();
        $this->checkpointStorage->acquireLock();
        $this->checkpointStorage->updateAndReleaseLock(SequenceNumber::none());
        $this->stateAccessor = null;
        return resolve(null);
    }

    private function truncateDatabaseTables(): void
    {
        try {
            $this->dbal->exec('TRUNCATE ' . $this->tableNamePrefix . '_uri');
            $this->dbal->exec('TRUNCATE ' . $this->tableNamePrefix . '_livecontentstreams');
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to truncate tables: %s', $e->getMessage()), 1599655382, $e);
        }
    }

    public function apply(EventInterface $event, EventEnvelope $eventEnvelope): PromiseInterface
    {
        match ($event::class) {
            RootWorkspaceWasCreated::class => $this->whenRootWorkspaceWasCreated($event),
            RootNodeAggregateWithNodeWasCreated::class => $this->whenRootNodeAggregateWithNodeWasCreated($event),
            RootNodeAggregateDimensionsWereUpdated::class => $this->whenRootNodeAggregateDimensionsWereUpdated($event),
            NodeAggregateWithNodeWasCreated::class => $this->whenNodeAggregateWithNodeWasCreated($event),
            NodeAggregateTypeWasChanged::class => $this->whenNodeAggregateTypeWasChanged($event),
            NodePeerVariantWasCreated::class => $this->whenNodePeerVariantWasCreated($event),
            NodeGeneralizationVariantWasCreated::class => $this->whenNodeGeneralizationVariantWasCreated($event),
            NodeSpecializationVariantWasCreated::class => $this->whenNodeSpecializationVariantWasCreated($event),
            SubtreeWasTagged::class => $this->whenSubtreeWasTagged($event),
            SubtreeWasUntagged::class => $this->whenSubtreeWasUntagged($event),
            NodeAggregateWasRemoved::class => $this->whenNodeAggregateWasRemoved($event),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($event, $eventEnvelope),
            NodeAggregateWasMoved::class => $this->whenNodeAggregateWasMoved($event),
            DimensionSpacePointWasMoved::class => $this->whenDimensionSpacePointWasMoved($event),
            DimensionShineThroughWasAdded::class => $this->whenDimensionShineThroughWasAdded($event),
            default => null,
        };
        return resolve(null);
    }

    public function getCheckpointStorage(): DbalCheckpointStorage
    {
        return $this->checkpointStorage;
    }

    public function getState(): DocumentUriPathFinder
    {
        if (!$this->stateAccessor) {
            $this->stateAccessor = new DocumentUriPathFinder($this->dbal, $this->tableNamePrefix);

            // !!! Bugfix #4253: during projection replay/update, it is crucial to have caches disabled.
            $this->stateAccessor->disableCache();
        }
        return $this->stateAccessor;
    }

    private function whenRootWorkspaceWasCreated(RootWorkspaceWasCreated $event): void
    {
        try {
            $this->dbal->insert($this->tableNamePrefix . '_livecontentstreams', [
                'contentStreamId' => $event->newContentStreamId->value,
                'workspaceName' => $event->workspaceName->value,
            ]);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf(
                'Failed to insert root content stream id of the root workspace "%s": %s',
                $event->workspaceName->value,
                $e->getMessage()
            ), 1599646608, $e);
        }
    }

    private function whenRootNodeAggregateWithNodeWasCreated(RootNodeAggregateWithNodeWasCreated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        foreach ($event->coveredDimensionSpacePoints as $dimensionSpacePoint) {
            $this->insertNode([
                'uriPath' => '',
                'nodeAggregateIdPath' => $event->nodeAggregateId->value,
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'nodeTypeName' => $event->nodeTypeName->value,
            ]);
        }
    }

    private function whenRootNodeAggregateDimensionsWereUpdated(RootNodeAggregateDimensionsWereUpdated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }

        // Just to figure out current NodeTypeName. This is the same for the aggregate in all dimensionSpacePoints.
        $pointHashes = $event->coveredDimensionSpacePoints->getPointHashes();
        $anyPointHash = reset($pointHashes);
        // There is always at least one dimension space point covered, even in a zero-dimensional cr.
        // Zero-dimensional means DimensionSpacePoint::fromArray([])->hash
        assert(is_string($anyPointHash));

        $nodeInSomeDimension = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
            $event->nodeAggregateId,
            $anyPointHash
        ));

        if ($nodeInSomeDimension === null) {
            return;
        }

        $this->dbal->delete(
            $this->tableNamePrefix . '_uri',
            [
                'nodeAggregateId' => $event->nodeAggregateId->value
            ]
        );

        foreach ($event->coveredDimensionSpacePoints as $dimensionSpacePoint) {
            $this->insertNode([
                'uriPath' => '',
                'nodeAggregateIdPath' => $event->nodeAggregateId->value,
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'nodeTypeName' => $nodeInSomeDimension->getNodeTypeName()->value,
            ]);
        }
    }

    private function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        if (!$this->isDocumentNodeType($event->nodeTypeName)) {
            return;
        }

        $propertyValues = $event->initialPropertyValues->getPlainValues();
        $uriPathSegment = $propertyValues['uriPathSegment'] ?? '';

        $shortcutTarget = null;
        if ($this->isShortcutNodeType($event->nodeTypeName)) {
            $shortcutTarget = [
                'mode' => $propertyValues['targetMode'] ?? 'firstChildNode',
                'target' => $propertyValues['target'] ?? null,
            ];
        }

        foreach ($event->succeedingSiblingsForCoverage->toDimensionSpacePointSet() as $dimensionSpacePoint) {
            $parentNode = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->parentNodeAggregateId,
                $dimensionSpacePoint->hash
            ));
            if ($parentNode === null) {
                // this should not happen
                continue;
            }
            /** @var DocumentNodeInfo|null $precedingNode */
            $precedingNode = null;

            $succeedingSiblingNodeAggregateId = $event->succeedingSiblingsForCoverage->getSucceedingSiblingIdForDimensionSpacePoint($dimensionSpacePoint);
            if ($succeedingSiblingNodeAggregateId === null) {
                $precedingNode = $this->tryGetNode(fn () => $this->getState()->getLastChildNode(
                    $parentNode->getNodeAggregateId(),
                    $dimensionSpacePoint->hash
                ));
                if ($precedingNode !== null) {
                    // make the new node the new succeeding node of the previously last child
                    // (= insert at the end of all children)
                    $this->updateNode($precedingNode, [
                        'succeedingNodeAggregateId' => $event->nodeAggregateId->value
                    ]);
                }
            } else {
                $precedingNode = $this->tryGetNode(fn () => $this->getState()->getPrecedingNode(
                    $succeedingSiblingNodeAggregateId,
                    $parentNode->getNodeAggregateId(),
                    $dimensionSpacePoint->hash
                ));
                if ($precedingNode !== null) {
                    // make the new node the new succeeding node of the previously preceding node
                    // of the specified succeeding node (= re-wire <preceding>-<succeeding> to <preceding>-<new node>)
                    $this->updateNode($precedingNode, [
                        'succeedingNodeAggregateId' => $event->nodeAggregateId->value
                    ]);
                }
                $this->updateNodeByIdAndDimensionSpacePointHash(
                    $succeedingSiblingNodeAggregateId,
                    $dimensionSpacePoint->hash,
                    ['precedingNodeAggregateId' => $event->nodeAggregateId->value]
                );
            }

            $nodeAggregateIdPath = $parentNode->getNodeAggregateIdPath()
                . '/' . $event->nodeAggregateId->value;
            if ($parentNode->isRoot() && $event->nodeName !== null) {
                $uriPath = '';
                $siteNodeName = SiteNodeName::fromNodeName($event->nodeName);
            } else {
                $uriPath = $parentNode->getUriPath() === ''
                    ? $uriPathSegment
                    : $parentNode->getUriPath() . '/' . $uriPathSegment;
                $siteNodeName = $parentNode->getSiteNodeName();
            }
            $this->insertNode([
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'uriPath' => $uriPath,
                'nodeAggregateIdPath' => $nodeAggregateIdPath,
                'siteNodeName' => $siteNodeName->value,
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'originDimensionSpacePointHash' => $event->originDimensionSpacePoint->hash,
                'parentNodeAggregateId' => $parentNode->getNodeAggregateId()->value,
                'precedingNodeAggregateId' => $precedingNode?->getNodeAggregateId()->value,
                'succeedingNodeAggregateId' => $succeedingSiblingNodeAggregateId?->value,
                'shortcutTarget' => $shortcutTarget,
                'nodeTypeName' => $event->nodeTypeName->value,
                'disabled' => $parentNode->getDisableLevel(),
            ]);
        }
    }

    private function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        if ($this->isShortcutNodeType($event->newNodeTypeName)) {
            // The node has been turned into a shortcut node, but since the shortcut mode is not yet set
            // we'll set it to "firstChildNode" in order to prevent an invalid mode
            $this->updateNodeQuery('SET shortcuttarget = COALESCE(shortcuttarget,\'{"mode":"firstChildNode","target":null}\'), nodeTypeName=:nodeTypeName
                WHERE nodeAggregateId = :nodeAggregateId', [
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'nodeTypeName' => $event->newNodeTypeName->value,
            ]);
        } elseif ($this->isDocumentNodeType($event->newNodeTypeName)) {
            $this->updateNodeQuery('SET shortcuttarget = NULL, nodeTypeName=:nodeTypeName
                WHERE nodeAggregateId = :nodeAggregateId', [
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'nodeTypeName' => $event->newNodeTypeName->value,
            ]);
        }
    }

    private function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        $this->copyVariants(
            $event->nodeAggregateId,
            $event->sourceOrigin,
            $event->peerOrigin,
            $event->peerSucceedingSiblings->toDimensionSpacePointSet()
        );
    }

    private function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        $this->copyVariants(
            $event->nodeAggregateId,
            $event->sourceOrigin,
            $event->generalizationOrigin,
            $event->variantSucceedingSiblings->toDimensionSpacePointSet()
        );
    }

    private function whenNodeSpecializationVariantWasCreated(NodeSpecializationVariantWasCreated $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        $this->copyVariants(
            $event->nodeAggregateId,
            $event->sourceOrigin,
            $event->specializationOrigin,
            $event->specializationSiblings->toDimensionSpacePointSet()
        );
    }

    private function copyVariants(
        NodeAggregateId $nodeAggregateId,
        OriginDimensionSpacePoint $sourceOrigin,
        OriginDimensionSpacePoint $targetOrigin,
        DimensionSpacePointSet $coveredSpacePoints
    ): void {
        $sourceNode = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
            $nodeAggregateId,
            $sourceOrigin->hash
        ));
        if ($sourceNode === null) {
            // Probably not a document node
            return;
        }
        foreach ($coveredSpacePoints as $coveredSpacePoint) {
            // Especially when importing a site it can happen that variants are created in a "non-deterministic" order,
            // so we need to first make sure a target variant doesn't exist:
            $this->deleteNodeByIdAndDimensionSpacePointHash($nodeAggregateId, $coveredSpacePoint->hash);

            $this->insertNode(
                $sourceNode
                ->withDimensionSpacePoint($coveredSpacePoint)
                ->withOriginDimensionSpacePoint($targetOrigin)
                ->toArray()
            );
        }
    }

    private function whenSubtreeWasTagged(SubtreeWasTagged $event): void
    {
        if ($event->tag->value !== 'disabled' || !$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        foreach ($event->affectedDimensionSpacePoints as $dimensionSpacePoint) {
            $node = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $dimensionSpacePoint->hash
            ));
            if ($node === null) {
                // Probably not a document node
                continue;
            }
            # node is already explicitly disabled
            if ($this->isNodeExplicitlyDisabled($node)) {
                return;
            }
            $this->updateNodeQuery('SET disabled = disabled + 1
                    WHERE dimensionSpacePointHash = :dimensionSpacePointHash
                        AND (
                            nodeAggregateId = :nodeAggregateId
                            OR nodeAggregateIdPath LIKE :childNodeAggregateIdPathPrefix
                        )', [
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'childNodeAggregateIdPathPrefix' => $node->getNodeAggregateIdPath() . '/%',
            ]);
        }
    }

    private function whenSubtreeWasUntagged(SubtreeWasUntagged $event): void
    {
        if ($event->tag->value !== 'disabled' || !$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        foreach ($event->affectedDimensionSpacePoints as $dimensionSpacePoint) {
            $node = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $dimensionSpacePoint->hash
            ));
            if ($node === null) {
                // Probably not a document node
                continue;
            }
            # node is not explicitly disabled, so we must not re-enable it
            if (!$this->isNodeExplicitlyDisabled($node)) {
                return;
            }
            $this->updateNodeQuery('SET disabled = disabled - 1
                WHERE dimensionSpacePointHash = :dimensionSpacePointHash
                    AND (
                        nodeAggregateId = :nodeAggregateId
                        OR nodeAggregateIdPath LIKE :childNodeAggregateIdPathPrefix
                    )', [
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'nodeAggregateId' => $node->getNodeAggregateId()->value,
                'childNodeAggregateIdPathPrefix' => $node->getNodeAggregateIdPath() . '/%',
            ]);
        }
    }

    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
            $node = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $dimensionSpacePoint->hash
            ));
            if ($node === null) {
                // Probably not a document node
                continue;
            }

            $this->disconnectNodeFromSiblings($node);

            $this->deleteNodeQuery('WHERE dimensionSpacePointHash = :dimensionSpacePointHash
                    AND (
                        nodeAggregateId = :nodeAggregateId
                        OR nodeAggregateIdPath LIKE :childNodeAggregateIdPathPrefix
                    )', [
                'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                'nodeAggregateId' => $node->getNodeAggregateId()->value,
                'childNodeAggregateIdPathPrefix' => $node->getNodeAggregateIdPath() . '/%',
            ]);
            $this->getState()->purgeCacheFor($node);
        }
    }

    private function whenNodePropertiesWereSet(NodePropertiesWereSet $event, EventEnvelope $eventEnvelope): void
    {
        if (!$this->getState()->isLiveContentStream($event->contentStreamId)) {
            return;
        }
        $newPropertyValues = $event->propertyValues->getPlainValues();
        if (
            !isset($newPropertyValues['uriPathSegment'])
            && !isset($newPropertyValues['targetMode'])
            && !isset($newPropertyValues['target'])
        ) {
            return;
        }

        foreach ($event->affectedDimensionSpacePoints as $affectedDimensionSpacePoint) {
            $node = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                $event->nodeAggregateId,
                $affectedDimensionSpacePoint->hash
            ));

            if (
                $node === null
                || $this->isSiteNodeType($node->getNodeTypeName())
            ) {
                // probably not a document node
                continue;
            }
            if ((isset($newPropertyValues['targetMode']) || isset($newPropertyValues['target'])) && $node->isShortcut()) {
                $shortcutTarget = $node->getShortcutTarget();
                $shortcutTarget = [
                    'mode' => $newPropertyValues['targetMode'] ?? $shortcutTarget['mode'],
                    'target' => $newPropertyValues['target'] ?? $shortcutTarget['target'],
                ];
                $this->updateNodeByIdAndDimensionSpacePointHash(
                    $event->nodeAggregateId,
                    $affectedDimensionSpacePoint->hash,
                    ['shortcutTarget' => $shortcutTarget]
                );
            }

            if (!isset($newPropertyValues['uriPathSegment'])) {
                continue;
            }
            $oldUriPath = $node->getUriPath();
            $uriPathSegments = explode('/', $oldUriPath);
            $uriPathSegments[array_key_last($uriPathSegments)] = $newPropertyValues['uriPathSegment'];
            $newUriPath = implode('/', $uriPathSegments);

            $this->updateNodeQuery(
                'SET uriPath = CONCAT(:newUriPath, SUBSTRING(uriPath, LENGTH(:oldUriPath) + 1))
                WHERE dimensionSpacePointHash = :dimensionSpacePointHash
                    AND (
                        nodeAggregateId = :nodeAggregateId
                        OR nodeAggregateIdPath LIKE :childNodeAggregateIdPathPrefix
                    )',
                [
                    'newUriPath' => $newUriPath,
                    'oldUriPath' => $oldUriPath,
                    'dimensionSpacePointHash' => $affectedDimensionSpacePoint->hash,
                    'nodeAggregateId' => $node->getNodeAggregateId()->value,
                    'childNodeAggregateIdPathPrefix' => $node->getNodeAggregateIdPath() . '/%',
                ]
            );
            $this->getState()->purgeCacheFor($node);
        }
    }

    private function whenNodeAggregateWasMoved(NodeAggregateWasMoved $event): void
    {
        if (!$this->getState()->isLiveContentStream($event->getContentStreamId())) {
            return;
        }

        foreach ($event->nodeMoveMappings as $moveMapping) {
            /* @var \Neos\ContentRepository\Core\Feature\NodeMove\Dto\OriginNodeMoveMapping $moveMapping */
            foreach ($moveMapping->newLocations as $newLocation) {
                /* @var $newLocation CoverageNodeMoveMapping */
                $node = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
                    $event->nodeAggregateId,
                    $newLocation->coveredDimensionSpacePoint->hash
                ));
                if (!$node) {
                    // node probably no document node, skip
                    continue;
                }

                match ($newLocation->destination::class) {
                    SucceedingSiblingNodeMoveDestination::class => $this->moveNode(
                        /** @var SucceedingSiblingNodeMoveDestination $newLocation->destination */
                        $node,
                        $newLocation->destination->parentNodeAggregateId,
                        $newLocation->destination->nodeAggregateId
                    ),
                    ParentNodeMoveDestination::class => $this->moveNode(
                        /** @var ParentNodeMoveDestination $newLocation->destination */
                        $node,
                        $newLocation->destination->nodeAggregateId,
                        null
                    ),
                };

                $this->getState()->purgeCacheFor($node);
            }
        }
    }

    private function moveNode(
        DocumentNodeInfo $node,
        NodeAggregateId $newParentNodeAggregateId,
        ?NodeAggregateId $newSucceedingNodeAggregateId
    ): void {
        $this->disconnectNodeFromSiblings($node);

        $this->connectNodeWithSiblings($node, $newParentNodeAggregateId, $newSucceedingNodeAggregateId);

        if ($newParentNodeAggregateId->equals($node->getParentNodeAggregateId())) {
            return;
        }
        $newParentNode = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
            $newParentNodeAggregateId,
            $node->getDimensionSpacePointHash()
        ));
        if ($newParentNode === null) {
            // This happens if the parent node does not exist in the moved variant.
            // Can happen if the content dimension configuration was updated, and dimension migrations were not run.
            return;
        }

        $disabledDelta = $newParentNode->getDisableLevel() - $node->getDisableLevel();
        if ($this->isNodeExplicitlyDisabled($node)) {
            $disabledDelta++;
        }

        $this->updateNodeQuery(
            /** @codingStandardsIgnoreStart */
            'SET
                nodeAggregateIdPath = TRIM(TRAILING "/" FROM CONCAT(:newParentNodeAggregateIdPath, "/", TRIM(LEADING "/" FROM SUBSTRING(nodeAggregateIdPath, :sourceNodeAggregateIdPathOffset)))),
                uriPath = TRIM("/" FROM CONCAT(:newParentUriPath, "/", TRIM(LEADING "/" FROM SUBSTRING(uriPath, :sourceUriPathOffset)))),
                disabled = disabled + ' . $disabledDelta . '
            WHERE
                dimensionSpacePointHash = :dimensionSpacePointHash
                    AND (nodeAggregateId = :nodeAggregateId
                    OR nodeAggregateIdPath LIKE :childNodeAggregateIdPathPrefix)
            ',
            /** @codingStandardsIgnoreEnd */
            [
                'nodeAggregateId' => $node->getNodeAggregateId()->value,
                'newParentNodeAggregateIdPath' => $newParentNode->getNodeAggregateIdPath(),
                'sourceNodeAggregateIdPathOffset'
                    => (int)strrpos($node->getNodeAggregateIdPath(), '/') + 1,
                'newParentUriPath' => $newParentNode->getUriPath(),
                // we have to distinguish two cases here:
                // - standard case: we want to move the nodes with URI /foo/bar into /target
                //   -> we want to strip the common prefix of the node (and all descendants)
                //      and then prepend the suffix with the new parent. Example:
                //
                //   /foo/bar     -> /target (+ /bar) => /target/bar
                //   /foo/bar/baz => /target (+ /bar/baz) => /target/bar/baz
                //
                //
                // - move directly underneath ROOT node of CR.
                //   the 1st level underneath the root node (in Neos) is the Site node, which needs to have
                //   an empty uriPath.
                //
                //   This is why we set the offset to the complete length, to create an empty string for the moved node
                //   in the SQL query above. Example:
                //
                //   /foo/bar     -> / (+ /) => /
                //   /foo/bar/baz => / (+ /baz) => /baz
                //
                'sourceUriPathOffset' => $newParentNode->isRoot() ? strlen($node->getUriPath()) + 1 : ((int)strrpos($node->getUriPath(), '/') + 1),
                'dimensionSpacePointHash' => $node->getDimensionSpacePointHash(),
                'childNodeAggregateIdPathPrefix' => $node->getNodeAggregateIdPath() . '/%',
            ]
        );
    }

    private function isNodeExplicitlyDisabled(DocumentNodeInfo $node): bool
    {
        if (!$node->isDisabled()) {
            return false;
        }
        $parentNode = $this->tryGetNode(fn () => $this->getState()->getByIdAndDimensionSpacePointHash(
            $node->getParentNodeAggregateId(),
            $node->getDimensionSpacePointHash()
        ));
        $parentDisabledLevel = $parentNode !== null ? $parentNode->getDisableLevel() : 0;
        return $node->getDisableLevel() - $parentDisabledLevel !== 0;
    }

    private function isSiteNodeType(NodeTypeName $nodeTypeName): bool
    {
        return $this->isNodeTypeOfType($nodeTypeName, NodeTypeNameFactory::forSite());
    }

    private function isDocumentNodeType(NodeTypeName $nodeTypeName): bool
    {
        return $this->isNodeTypeOfType($nodeTypeName, NodeTypeNameFactory::forDocument());
    }

    private function isShortcutNodeType(NodeTypeName $nodeTypeName): bool
    {
        return $this->isNodeTypeOfType($nodeTypeName, NodeTypeNameFactory::forShortcut());
    }

    private function isNodeTypeOfType(NodeTypeName $nodeTypeName, NodeTypeName $superNodeTypeName): bool
    {
        if (!array_key_exists($superNodeTypeName->value, $this->nodeTypeImplementsRuntimeCache)) {
            $this->nodeTypeImplementsRuntimeCache[$superNodeTypeName->value] = [];
        }
        if (!array_key_exists($nodeTypeName->value, $this->nodeTypeImplementsRuntimeCache[$superNodeTypeName->value])) {
            // HACK: We consider the currently configured node type of the given node.
            // This is a deliberate side effect of this projector!
            // Note: We could add some hash over all node type decisions to the projected read model
            // to tell whether a replay is required (e.g. if a document node type was changed to a content type vice versa)
            // With https://github.com/neos/neos-development-collection/issues/4468 this can be compared in the `getStatus()` implementation
            $this->nodeTypeImplementsRuntimeCache[$superNodeTypeName->value][$nodeTypeName->value] = $this->nodeTypeManager->getNodeType($nodeTypeName)?->isOfType($superNodeTypeName) ?? false;
        }
        return $this->nodeTypeImplementsRuntimeCache[$superNodeTypeName->value][$nodeTypeName->value];
    }

    private function tryGetNode(\Closure $closure): ?DocumentNodeInfo
    {
        try {
            return $closure();
        } catch (NodeNotFoundException $_) {
            /** @noinspection BadExceptionsProcessingInspection */
            return null;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertNode(array $data): void
    {
        try {
            $this->dbal->insert($this->tableNamePrefix . '_uri', $data, self::COLUMN_TYPES_DOCUMENT_URIS);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to insert node: %s', $e->getMessage()), 1599646694, $e);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateNode(DocumentNodeInfo $node, array $data): void
    {
        $this->updateNodeByIdAndDimensionSpacePointHash(
            $node->getNodeAggregateId(),
            $node->getDimensionSpacePointHash(),
            $data
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateNodeByIdAndDimensionSpacePointHash(
        NodeAggregateId $nodeAggregateId,
        string $dimensionSpacePointHash,
        array $data
    ): void {
        try {
            $this->dbal->update(
                $this->tableNamePrefix . '_uri',
                $data,
                [
                    'nodeAggregateId' => $nodeAggregateId->value,
                    'dimensionSpacePointHash' => $dimensionSpacePointHash,
                ],
                self::COLUMN_TYPES_DOCUMENT_URIS
            );
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf(
                'Failed to update node "%s": %s',
                $nodeAggregateId->value,
                $e->getMessage()
            ), 1599646777, $e);
        }
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function updateNodeQuery(string $query, array $parameters): void
    {
        try {
            $this->dbal->executeQuery(
                'UPDATE ' . $this->tableNamePrefix . '_uri ' . $query,
                $parameters,
                self::COLUMN_TYPES_DOCUMENT_URIS
            );
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf(
                'Failed to update node via custom query: %s',
                $e->getMessage()
            ), 1599659170, $e);
        }
    }

    private function deleteNodeByIdAndDimensionSpacePointHash(
        NodeAggregateId $nodeAggregateId,
        string $dimensionSpacePointHash
    ): void {
        try {
            $this->dbal->delete(
                $this->tableNamePrefix . '_uri',
                [
                    'nodeAggregateId' => $nodeAggregateId->value,
                    'dimensionSpacePointHash' => $dimensionSpacePointHash,
                ],
                self::COLUMN_TYPES_DOCUMENT_URIS
            );
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf(
                'Failed to delete node "%s": %s',
                $nodeAggregateId->value,
                $e->getMessage()
            ), 1599655284, $e);
        }
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function deleteNodeQuery(string $query, array $parameters): void
    {
        try {
            $this->dbal->executeQuery(
                'DELETE FROM ' . $this->tableNamePrefix . '_uri ' . $query,
                $parameters,
                self::COLUMN_TYPES_DOCUMENT_URIS
            );
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf(
                'Failed to delete node via custom query: %s',
                $e->getMessage()
            ), 1599659226, $e);
        }
    }

    private function disconnectNodeFromSiblings(DocumentNodeInfo $node): void
    {
        if ($node->hasPrecedingNodeAggregateId()) {
            $this->updateNodeByIdAndDimensionSpacePointHash(
                $node->getPrecedingNodeAggregateId(),
                $node->getDimensionSpacePointHash(),
                ['succeedingNodeAggregateId' =>
                    $node->hasSucceedingNodeAggregateId() ? $node->getSucceedingNodeAggregateId()->value : null
                ]
            );
        }
        if ($node->hasSucceedingNodeAggregateId()) {
            $this->updateNodeByIdAndDimensionSpacePointHash(
                $node->getSucceedingNodeAggregateId(),
                $node->getDimensionSpacePointHash(),
                ['precedingNodeAggregateId' =>
                    $node->hasPrecedingNodeAggregateId() ? $node->getPrecedingNodeAggregateId()->value : null
                ]
            );
        }
    }

    private function connectNodeWithSiblings(
        DocumentNodeInfo $node,
        NodeAggregateId $parentNodeAggregateId,
        ?NodeAggregateId $newSucceedingNodeAggregateId
    ): void {
        if ($newSucceedingNodeAggregateId !== null) {
            $newPrecedingNode = $this->tryGetNode(fn () => $this->getState()->getPrecedingNode(
                $newSucceedingNodeAggregateId,
                $parentNodeAggregateId,
                $node->getDimensionSpacePointHash()
            ));

            // update new succeeding node
            $this->updateNodeByIdAndDimensionSpacePointHash(
                $newSucceedingNodeAggregateId,
                $node->getDimensionSpacePointHash(),
                ['precedingNodeAggregateId' => $node->getNodeAggregateId()->value]
            );
        } else {
            $newPrecedingNode = $this->tryGetNode(fn () => $this->getState()->getLastChildNode(
                $parentNodeAggregateId,
                $node->getDimensionSpacePointHash()
            ));
        }
        if ($newPrecedingNode !== null) {
            $this->updateNode(
                $newPrecedingNode,
                ['succeedingNodeAggregateId' => $node->getNodeAggregateId()->value]
            );
        }

        // update node itself
        $this->updateNode($node, [
            'parentNodeAggregateId' => $parentNodeAggregateId->value,
            'precedingNodeAggregateId' => $newPrecedingNode?->getNodeAggregateId()->value,
            'succeedingNodeAggregateId' => $newSucceedingNodeAggregateId?->value,
        ]);
    }


    private function whenDimensionSpacePointWasMoved(DimensionSpacePointWasMoved $event): void
    {
        if ($this->getState()->isLiveContentStream($event->contentStreamId)) {
            $this->updateNodeQuery(
                'SET dimensionspacepointhash = :newDimensionSpacePointHash
                        WHERE dimensionspacepointhash = :originalDimensionSpacePointHash',
                [
                    'originalDimensionSpacePointHash' => $event->source->hash,
                    'newDimensionSpacePointHash' => $event->target->hash,
                ]
            );

            $this->updateNodeQuery(
                'SET origindimensionspacepointhash = :newDimensionSpacePointHash
                        WHERE origindimensionspacepointhash = :originalDimensionSpacePointHash',
                [
                    'originalDimensionSpacePointHash' => $event->source->hash,
                    'newDimensionSpacePointHash' => $event->target->hash,
                ]
            );
        }
    }


    private function whenDimensionShineThroughWasAdded(DimensionShineThroughWasAdded $event): void
    {
        if ($this->getState()->isLiveContentStream($event->contentStreamId)) {
            try {
                $this->dbal->executeStatement('INSERT INTO ' . $this->tableNamePrefix . '_uri (
                    nodeaggregateid,
                    uripath,
                    nodeaggregateidpath,
                    sitenodename,
                    disabled,
                    dimensionspacepointhash,
                    origindimensionspacepointhash,
                    parentnodeaggregateid,
                    precedingnodeaggregateid,
                    succeedingnodeaggregateid,
                    shortcuttarget,
                    nodetypename
                )
                SELECT
                    nodeaggregateid,
                    uripath,
                    nodeaggregateidpath,
                    sitenodename,
                    disabled,
                    :newDimensionSpacePointHash AS dimensionspacepointhash,
                    origindimensionspacepointhash,
                    parentnodeaggregateid,
                    precedingnodeaggregateid,
                    succeedingnodeaggregateid,
                    shortcuttarget,
                    nodetypename
                FROM
                    ' . $this->tableNamePrefix . '_uri
                WHERE
                    dimensionSpacePointHash = :sourceDimensionSpacePointHash
                ', [
                    'sourceDimensionSpacePointHash' => $event->source->hash,
                    'newDimensionSpacePointHash' => $event->target->hash,
                ]);
            } catch (DBALException $e) {
                throw new \RuntimeException(sprintf(
                    'Failed to insert new dimension shine through: %s',
                    $e->getMessage()
                ), 1599646608, $e);
            }
        }
    }

    public function markStale(): void
    {
        $this->getState()->disableCache();
    }
}
