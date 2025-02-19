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

namespace Neos\ContentRepository\Core\Projection\ContentGraph;

use Neos\ContentRepository\Core\SharedModel\Node\NodeName;

/**
 * The relative node path is a collection of node names {@see NodeName}.
 *
 * If it contains no elements, it is considered root in combination with {@see AbsoluteNodePath}.
 *
 * Example:
 * root path: '' is resolved to []
 * non-root path: 'my-document/main' is resolved to ~ ['my-document', 'main']
 *
 * It describes the hierarchy path of a node to an ancestor node in a subgraph.
 *
 * To fetch a node on a path use the subgraph: {@see ContentSubgraphInterface::findNodeByPath()}
 *
 * ```php
 * $subgraph->findNodeByPath(
 *     NodePath::fromString("my-document/main"),
 *     $siteNodeAggregateId
 * )
 * ```
 *
 * @api
 */
final readonly class NodePath implements \JsonSerializable
{
    /**
     * @var array<NodeName>
     */
    private array $nodeNames;

    private function __construct(NodeName ...$nodeNames)
    {
        $this->nodeNames = $nodeNames;
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    public static function fromString(string $path): self
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return self::createEmpty();
        }

        return self::fromPathSegments(
            explode('/', $path)
        );
    }

    /**
     * @param array<int,string> $pathSegments
     */
    public static function fromPathSegments(array $pathSegments): self
    {
        return new self(...array_map(
            function (string $pathPart) use ($pathSegments): NodeName {
                try {
                    return NodeName::fromString($pathPart);
                } catch (\InvalidArgumentException) {
                    throw new \InvalidArgumentException(sprintf(
                        'The path "%s" is no valid NodePath because it contains a segment "%s"'
                            . ' that is no valid NodeName',
                        implode('/', $pathSegments),
                        $pathPart
                    ), 1548157108);
                }
            },
            $pathSegments
        ));
    }

    public static function fromNodeNames(NodeName ...$nodeNames): self
    {
        return new self(...$nodeNames);
    }

    public function isEmpty(): bool
    {
        return $this->getLength() === 0;
    }

    /**
     * IMMUTABLE function to create a new NodePath by appending a path segment. Returns a NEW NodePath object
     */
    public function appendPathSegment(NodeName $nodeName): self
    {
        return new self(
            ...$this->nodeNames,
            ...[$nodeName]
        );
    }

    /**
     * @return array<int,NodeName>
     */
    public function getParts(): array
    {
        return array_values($this->nodeNames);
    }

    public function getLength(): int
    {
        return count($this->nodeNames);
    }

    public function equals(NodePath $other): bool
    {
        return $this->serializeToString() === $other->serializeToString();
    }

    public function serializeToString(): string
    {
        return implode(
            '/',
            array_map(
                fn (NodeName $nodeName): string => $nodeName->value,
                $this->nodeNames
            )
        );
    }

    public function jsonSerialize(): string
    {
        return $this->serializeToString();
    }

    public function __toString(): string
    {
        return $this->serializeToString();
    }
}
