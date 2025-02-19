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

namespace Neos\ContentRepository\Core\Feature\WorkspacePublication\Command;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Publish a workspace
 *
 * @api commands are the write-API of the ContentRepository
 */
final readonly class PublishWorkspace implements CommandInterface
{
    /**
     * @param WorkspaceName $workspaceName Name of the workspace to publish
     */
    private function __construct(
        public WorkspaceName $workspaceName,
        public ContentStreamId $newContentStreamId,
    ) {
    }

    public static function fromArray(array $array): self
    {
        return new self(
            WorkspaceName::fromString($array['workspaceName']),
            isset($array['newContentStreamId']) ? ContentStreamId::fromString($array['newContentStreamId']) : ContentStreamId::create(),
        );
    }

    /**
     * During the publish process, we create a new content stream.
     *
     * This method adds its ID, so that the command
     * can run fully deterministic - we need this for the test cases.
     */
    public function withNewContentStreamId(ContentStreamId $newContentStreamId): self
    {
        return new self($this->workspaceName, $newContentStreamId);
    }

    /**
     * @param WorkspaceName $workspaceName Name of the workspace to publish
     */
    public static function create(WorkspaceName $workspaceName): self
    {
        return new self($workspaceName, ContentStreamId::create());
    }
}
