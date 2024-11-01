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

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

/**
 * @internal
 */
final readonly class CommandHooksFactoryDependencies
{
    private function __construct(
        public ContentRepositoryId $contentRepositoryId,
    ) {
    }

    /**
     * @internal
     */
    public static function create(
        ContentRepositoryId $contentRepositoryId,
    ): self {
        return new self(
            $contentRepositoryId,
        );
    }
}
