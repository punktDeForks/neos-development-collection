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

namespace Neos\Neos\Security\Authorization\Privilege;

/**
 * The privilege to read any matching node from the Content Repository.
 * This includes all properties, references and metadata
 */
class ReadNodePrivilege extends AbstractSubtreeTagBasedPrivilege
{
}
