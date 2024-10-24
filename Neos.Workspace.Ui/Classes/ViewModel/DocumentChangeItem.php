<?php

/*
 * This file is part of the Neos.Workspace.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Workspace\Ui\ViewModel;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
readonly class DocumentChangeItem
{
    public function __construct(
        public array $documentBreadCrumb,
        public string $aggregateId,
        public string $documentNodeAddress,
        public bool $isRemoved,
        public bool $isNew,
        public bool $isMoved,
        public ChangeItems $changes,
        public int $changesCount,
    ) {
    }
}
