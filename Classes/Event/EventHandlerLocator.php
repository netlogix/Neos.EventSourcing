<?php
namespace Flowpack\Cqrs\Event;

/*
 * This file is part of the Flowpack.Cqrs package.
 *
 * (c) Hand crafted with love in each details by medialib.tv
 */

use Flowpack\Cqrs\Annotations\EventHandler;
use Flowpack\Cqrs\Event\Exception\EventBusException;
use Flowpack\Cqrs\Message\MessageInterface;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Reflection\ReflectionService;

/**
 * EventHandlerLocator
 *
 * @Flow\Scope("singleton")
 */
class EventHandlerLocator implements EventHandlerLocatorInterface
{
    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @var array
     */
    protected $map = [];

    /**
     * Register event handlers based on annotations
     */
    public function initializeObject()
    {
        $handlers = self::loadHandlers($this->objectManager);
        $this->map = array_merge($handlers, $this->map);
    }

    /**
     * @param string $eventName
     * @param string $handlerName
     * @throws EventBusException
     */
    public function register($eventName, $handlerName)
    {
        if (!$this->objectManager->isRegistered($handlerName)) {
            throw new EventBusException(sprintf(
                "Event handler '%s' is not a registred object", $handlerName
            ));
        }

        $handlerHash = md5($handlerName);
        $this->map[$eventName][$handlerHash] = $handlerName;
    }

    /**
     * @param MessageInterface $message
     * @return EventHandlerInterface[]
     */
    public function getHandlers(MessageInterface $message)
    {
        $handlers = [];

        $eventName = $message->getName();

        if (!array_key_exists($eventName, $this->map)) {
            return $handlers;
        }

        foreach ($this->map[$eventName] as $handlerName) {
            $handlers[] = $this->objectManager->get($handlerName);
        }

        return $handlers;
    }

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array
     */
    protected static function loadHandlers(ObjectManagerInterface $objectManager)
    {
        $handlers = [];
        /** @var ReflectionService $reflectionService */
        $reflectionService = $objectManager->get(ReflectionService::class);
        foreach ($reflectionService->getClassNamesByAnnotation(EventHandler::class) as $handler) {
            /** @var EventHandler $annotation */
            $annotation = $reflectionService->getClassAnnotation($handler, EventHandler::class);
            $handlers[$annotation->event][md5($handler)] = $handler;
        }
        return $handlers;
    }
}
