<?php
declare(strict_types=1);
namespace Neos\EventSourcing\Event\Decorator;

/*
 * This file is part of the Neos.EventSourcing package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

interface DomainEventWithIdentifierInterface extends DomainEventDecoratorInterface
{

    /**
     * @return string
     */
    public function getIdentifier(): string;
}
