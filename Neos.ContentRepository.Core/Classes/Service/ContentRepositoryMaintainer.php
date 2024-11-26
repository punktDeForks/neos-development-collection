<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Core\Feature\ContentStreamEventStreamName;
use Neos\ContentRepository\Core\Feature\WorkspaceEventStreamName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryStatus;
use Neos\ContentRepository\Core\Subscription\Engine\Errors;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngine;
use Neos\ContentRepository\Core\Subscription\Engine\SubscriptionEngineCriteria;
use Neos\ContentRepository\Core\Subscription\Store\SubscriptionCriteria;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatusCollection;
use Neos\Error\Messages\Error;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * Set up and manage a content repository
 *
 * Initialisation / Tear down
 * --------------------------
 * The method {@see setUp} sets up the content repository like event store and projection database tables.
 * It is non-destructive.
 *
 * Resetting a content repository with {@see prune} method will purge the event stream and reset all projection states.
 *
 * Staus information
 * -----------------
 * The status of the content repository e.g. if a setup is required or if all subscriptions are active and their position
 * can be examined with {@see status}
 *
 * The event store status is available via {@see ContentRepositoryStatus::$eventStoreStatus}, and the subscription status
 * via {@see ContentRepositoryStatus::$subscriptionStatus}. Further documentation in {@see SubscriptionStatusCollection}.
 *
 * Projection subscriptions
 * ------------------------
 *
 * This maintainer offers also the public API to interact with the projection catchup. In the happy path,
 * no interaction is necessary, as {@see ContentRepository::handle()} triggers the projections after applying the events.
 *
 * Special cases:
 *
 * *Replay*
 *
 * For initialising on a new database - which contains events already - a replay will make sure that the projections
 * are emptied and reapply the events. This can be triggered via {@see replayProjection} or {@see replayAllProjections}
 *
 * And after registering a new projection a setup as well as a replay of this projection is also required.
 *
 * *Reactivate*
 *
 * In case a projection is detached but is reinstalled a reactivation is needed via {@see reactivateSubscription}
 *
 * Also in case a projection runs into the error status, its code needs to be fixed, and it can also be attempted to be reactivated.
 *
 * Note that in both cases a projection replay would also work, but with the difference that the projection is reset as well.
 *
 * @api
 */
final readonly class ContentRepositoryMaintainer implements ContentRepositoryServiceInterface
{
    /**
     * @internal please use the {@see ContentRepositoryMaintainerFactory} instead!
     */
    public function __construct(
        private EventStoreInterface $eventStore,
        private SubscriptionEngine $subscriptionEngine
    ) {
    }

    public function setUp(): Error|null
    {
        $this->eventStore->setup();
        $eventStoreIsEmpty = iterator_count($this->eventStore->load(VirtualStreamName::all())->limit(1)) === 0;
        $setupResult = $this->subscriptionEngine->setup();
        if ($setupResult->errors !== null) {
            return self::createErrorForReason('setup', $setupResult->errors);
        }
        if ($eventStoreIsEmpty) {
            // todo reintroduce skipBooting flag, and also notify if the flag is not set, e.g. because there are events
            $bootResult = $this->subscriptionEngine->boot();
            if ($bootResult->errors !== null) {
                return self::createErrorForReason('initial catchup', $bootResult->errors);
            }
        }
        return null;
    }

    public function status(): ContentRepositoryStatus
    {
        $lastEventEnvelope = current(iterator_to_array($this->eventStore->load(VirtualStreamName::all())->backwards()->limit(1))) ?: null;

        return ContentRepositoryStatus::create(
            $this->eventStore->status(),
            $lastEventEnvelope?->sequenceNumber ?? SequenceNumber::none(),
            $this->subscriptionEngine->subscriptionStatus()
        );
    }

    public function replayProjection(SubscriptionId $subscriptionId, \Closure|null $progressCallback = null): Error|null
    {
        $resetResult = $this->subscriptionEngine->reset(SubscriptionEngineCriteria::create([$subscriptionId]));
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        $bootResult = $this->subscriptionEngine->boot(SubscriptionEngineCriteria::create([$subscriptionId]), $progressCallback);
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('catchup', $bootResult->errors);
        }
        return null;
    }

    public function replayAllProjections(\Closure|null $progressCallback = null): Error|null
    {
        $resetResult = $this->subscriptionEngine->reset();
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        $bootResult = $this->subscriptionEngine->boot(progressCallback: $progressCallback);
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('catchup', $bootResult->errors);
        }
        return null;
    }

    /**
     * Reactivate a projection
     *
     * The explicit catchup is only needed for projections in the error or detached status with an advanced position.
     * Running a full replay would work but might be overkill, instead this reactivation will just attempt
     * catchup the projection back to active from its current position.
     */
    public function reactivateSubscription(SubscriptionId $subscriptionId, \Closure|null $progressCallback = null): Error|null
    {
        $subscriptionStatus = $this->subscriptionEngine->subscriptionStatus(SubscriptionCriteria::create([$subscriptionId]))->first();

        if ($subscriptionStatus === null) {
            return new Error(sprintf('Subscription "%s" is not registered.', $subscriptionId->value));
        }

        // todo implement https://github.com/patchlevel/event-sourcing/blob/b8591c56b21b049f46bead8e7ab424fd2afe9917/src/Subscription/Engine/DefaultSubscriptionEngine.php#L624
        return null;
    }

    /**
     * WARNING: Removes all events from the content repository and resets the projections
     * This operation cannot be undone.
     */
    public function prune(): Error|null
    {
        // prune all streams:
        foreach ($this->findAllContentStreamStreamNames() as $contentStreamStreamName) {
            $this->eventStore->deleteStream($contentStreamStreamName);
        }
        foreach ($this->findAllWorkspaceStreamNames() as $workspaceStreamName) {
            $this->eventStore->deleteStream($workspaceStreamName);
        }
        $resetResult = $this->subscriptionEngine->reset();
        if ($resetResult->errors !== null) {
            return self::createErrorForReason('reset', $resetResult->errors);
        }
        // todo reintroduce skipBooting flag to reset
        $bootResult = $this->subscriptionEngine->boot();
        if ($bootResult->errors !== null) {
            return self::createErrorForReason('booting', $bootResult->errors);
        }
        return null;
    }

    private static function createErrorForReason(string $method, Errors $errors): Error
    {
        // todo log throwable via flow???, but we are here in the CORE ...
        $message = [];
        $message[] = sprintf('%s produced the following error%s', $method, $errors->count() === 1 ? '' : 's');
        foreach ($errors as $error) {
            $message[] = sprintf('    Subscription "%s": %s', $error->subscriptionId->value, $error->message);
        }
        return new Error(join("\n", $message));
    }

    /**
     * @return list<StreamName>
     */
    private function findAllContentStreamStreamNames(): array
    {
        $events = $this->eventStore->load(
            VirtualStreamName::forCategory(ContentStreamEventStreamName::EVENT_STREAM_NAME_PREFIX),
            EventStreamFilter::create(
                EventTypes::create(
                    // we are only interested in the creation events to limit the amount of events to fetch
                    EventType::fromString('ContentStreamWasCreated'),
                    EventType::fromString('ContentStreamWasForked')
                )
            )
        );
        $allStreamNames = [];
        foreach ($events as $eventEnvelope) {
            $allStreamNames[] = $eventEnvelope->streamName;
        }
        return array_unique($allStreamNames, SORT_REGULAR);
    }

    /**
     * @return list<StreamName>
     */
    private function findAllWorkspaceStreamNames(): array
    {
        $events = $this->eventStore->load(
            VirtualStreamName::forCategory(WorkspaceEventStreamName::EVENT_STREAM_NAME_PREFIX),
            EventStreamFilter::create(
                EventTypes::create(
                    // we are only interested in the creation events to limit the amount of events to fetch
                    EventType::fromString('RootWorkspaceWasCreated'),
                    EventType::fromString('WorkspaceWasCreated')
                )
            )
        );
        $allStreamNames = [];
        foreach ($events as $eventEnvelope) {
            $allStreamNames[] = $eventEnvelope->streamName;
        }
        return array_unique($allStreamNames, SORT_REGULAR);
    }
}
