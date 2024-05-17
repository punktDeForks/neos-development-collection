<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\ViewHelpers\Uri;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodePath;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\NodeAddress;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\FluidAdaptor\Core\ViewHelper\Exception as ViewHelperException;
use Neos\Fusion\ViewHelpers\FusionContextTrait;
use Neos\Neos\FrontendRouting\NodeUriBuilder;

/**
 * A view helper for creating URIs pointing to nodes.
 *
 * The target node can be provided as string or as a Node object; if not specified
 * at all, the generated URI will refer to the current document node inside the Fusion context.
 *
 * When specifying the ``node`` argument as string, the following conventions apply:
 *
 * *``node`` starts with ``/``:*
 * The given path is an absolute node path and is treated as such.
 * Example: ``/sites/acmecom/home/about/us``
 *
 * *``node`` does not start with ``/``:*
 * The given path is treated as a path relative to the current node.
 * Examples: given that the current node is ``/sites/acmecom/products/``,
 * ``stapler`` results in ``/sites/acmecom/products/stapler``,
 * ``../about`` results in ``/sites/acmecom/about/``,
 * ``./neos/info`` results in ``/sites/acmecom/products/neos/info``.
 *
 * *``node`` starts with a tilde character (``~``):*
 * The given path is treated as a path relative to the current site node.
 * Example: given that the current node is ``/sites/acmecom/products/``,
 * ``~/about/us`` results in ``/sites/acmecom/about/us``,
 * ``~`` results in ``/sites/acmecom``.
 *
 * = Examples =
 *
 * <code title="Default">
 * <neos:uri.node />
 * </code>
 * <output>
 * homepage/about.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Generating an absolute URI">
 * <neos:uri.node absolute="{true"} />
 * </code>
 * <output>
 * http://www.example.org/homepage/about.html
 * (depending on current workspace, current node, format, host etc.)
 * </output>
 *
 * <code title="Target node given as absolute node path">
 * <neos:uri.node node="/sites/acmecom/about/us" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as relative node path">
 * <neos:uri.node node="~/about/us" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as node://-uri">
 * <neos:uri.node node="node://30e893c1-caef-0ca5-b53d-e5699bb8e506" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 * @api
 */
class NodeViewHelper extends AbstractViewHelper
{
    use FusionContextTrait;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * Initialize arguments
     *
     * @return void
     * @throws ViewHelperException
     */
    public function initializeArguments()
    {
        $this->registerArgument(
            'node',
            'mixed',
            'A node object, a string node path (absolute or relative), a string node://-uri or NULL'
        );
        $this->registerArgument(
            'format',
            'string',
            'Format to use for the URL, for example "html" or "json"'
        );
        $this->registerArgument(
            'absolute',
            'boolean',
            'If set, an absolute URI is rendered',
            false,
            false
        );
        $this->registerArgument(
            'arguments',
            'array',
            'Additional arguments to be passed to the UriBuilder (for example pagination parameters)',
            false,
            []
        );
        $this->registerArgument(
            'section',
            'string',
            'The anchor to be added to the URI',
            false,
            ''
        );
        $this->registerArgument(
            'addQueryString',
            'boolean',
            'If set, the current query parameters will be kept in the URI',
            false,
            false
        );
        $this->registerArgument(
            'argumentsToBeExcludedFromQueryString',
            'array',
            'arguments to be removed from the URI. Only active if $addQueryString = true',
            false,
            []
        );
        $this->registerArgument(
            'baseNodeName',
            'string',
            'The name of the base node inside the Fusion context to use for the ContentContext'
            . ' or resolving relative paths',
            false,
            'documentNode'
        );
        $this->registerArgument(
            'nodeVariableName',
            'string',
            'The variable the node will be assigned to for the rendered child content',
            false,
            'linkedNode'
        );
        $this->registerArgument(
            'resolveShortcuts',
            'boolean',
            'INTERNAL Parameter - if false, shortcuts are not redirected to their target.'
            . ' Only needed on rare backend occasions when we want to link to the shortcut itself',
            false,
            true
        );
    }

    /**
     * Renders the URI.
     */
    public function render(): string
    {
        $node = $this->arguments['node'];
        if (!$node instanceof Node) {
            $node = $this->getContextVariable($this->arguments['baseNodeName']);
        }

        if ($node instanceof Node) {
            $contentRepository = $this->contentRepositoryRegistry->get(
                $node->subgraphIdentity->contentRepositoryId
            );
            $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
            $nodeAddress = $nodeAddressFactory->createFromNode($node);
        } elseif (is_string($node)) {
            $nodeAddress = $this->resolveNodeAddressFromString($node);
        } else {
            throw new ViewHelperException(sprintf(
                'The "node" argument can only be a string or an instance of %s. Given: %s',
                Node::class,
                is_object($node) ? get_class($node) : gettype($node)
            ), 1601372376);
        }

        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($this->controllerContext->getRequest());
        $uriBuilder->setFormat($this->arguments['format'])
            ->setCreateAbsoluteUri($this->arguments['absolute'])
            ->setArguments($this->arguments['arguments'])
            ->setSection($this->arguments['section'])
            ->setAddQueryString($this->arguments['addQueryString'])
            ->setArgumentsToBeExcludedFromQueryString($this->arguments['argumentsToBeExcludedFromQueryString']);

        $uri = '';
        if (!$nodeAddress) {
            return '';
        }
        try {
            $uri = (string)NodeUriBuilder::fromUriBuilder($uriBuilder)->uriFor($nodeAddress);
        } catch (
            HttpException
            | NoMatchingRouteException
            | MissingActionNameException $e
        ) {
            $this->throwableStorage->logThrowable(new ViewHelperException(sprintf(
                'Failed to build URI for node: %s: %s',
                $nodeAddress,
                $e->getMessage()
            ), 1601372594, $e));
        }
        return $uri;
    }

    /**
     * Converts strings like "relative/path", "/absolute/path", "~/site-relative/path" and "~"
     * to the corresponding NodeAddress
     *
     * @param string $path
     * @return \Neos\Neos\FrontendRouting\NodeAddress
     * @throws ViewHelperException
     */
    private function resolveNodeAddressFromString(string $path): ?NodeAddress
    {
        /* @var Node $documentNode */
        $documentNode = $this->getContextVariable('documentNode');
        $contentRepository = $this->contentRepositoryRegistry->get(
            $documentNode->subgraphIdentity->contentRepositoryId
        );
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);
        $documentNodeAddress = $nodeAddressFactory->createFromNode($documentNode);
        if (strncmp($path, 'node://', 7) === 0) {
            return $documentNodeAddress->withNodeAggregateId(
                NodeAggregateId::fromString(\mb_substr($path, 7))
            );
        }
        $subgraph = $contentRepository->getContentGraph($documentNodeAddress->workspaceName)->getSubgraph(
            $documentNodeAddress->dimensionSpacePoint,
            VisibilityConstraints::withoutRestrictions()
        );
        if (strncmp($path, '~', 1) === 0) {
            $siteNode = $subgraph->findClosestNode($documentNodeAddress->nodeAggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
            if ($siteNode === null) {
                throw new ViewHelperException(sprintf(
                    'Failed to determine site node for aggregate node "%s" and subgraph "%s"',
                    $documentNodeAddress->nodeAggregateId->value,
                    json_encode($subgraph, JSON_PARTIAL_OUTPUT_ON_ERROR)
                ), 1601366598);
            }
            if ($path === '~') {
                $targetNode = $siteNode;
            } else {
                $targetNode = $subgraph->findNodeByPath(
                    NodePath::fromString(substr($path, 1)),
                    $siteNode->nodeAggregateId
                );
            }
        } else {
            $targetNode = $subgraph->findNodeByPath(
                NodePath::fromString($path),
                $documentNode->nodeAggregateId
            );
        }
        if ($targetNode === null) {
            $this->throwableStorage->logThrowable(new ViewHelperException(sprintf(
                'Node on path "%s" could not be found for aggregate node "%s" and subgraph "%s"',
                $path,
                $documentNodeAddress->nodeAggregateId->value,
                json_encode($subgraph, JSON_PARTIAL_OUTPUT_ON_ERROR)
            ), 1601311789));
            return null;
        }
        return $documentNodeAddress->withNodeAggregateId($targetNode->nodeAggregateId);
    }
}
