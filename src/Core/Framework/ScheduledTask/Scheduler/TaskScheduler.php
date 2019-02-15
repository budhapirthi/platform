<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ScheduledTask\Scheduler;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\MinAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\MinAggregationResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\ScheduledTask\ScheduledTaskDefinition;
use Shopware\Core\Framework\ScheduledTask\ScheduledTaskEntity;
use Shopware\Core\Framework\ScheduledTask\ScheduledTaskInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class TaskScheduler
{
    /**
     * @var EntityRepositoryInterface
     */
    private $scheduledTaskRepository;

    /**
     * @var MessageBusInterface
     */
    private $bus;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        MessageBusInterface $bus
    ) {
        $this->scheduledTaskRepository = $scheduledTaskRepository;
        $this->bus = $bus;
    }

    public function queueScheduledTasks(): void
    {
        $criteria = $this->buildCriteriaForAllScheduledTask();
        $tasks = $this->scheduledTaskRepository->search($criteria, Context::createDefaultContext())->getEntities();

        $updatePayload = [];
        /** @var ScheduledTaskEntity $task */
        foreach ($tasks as $task) {
            $this->queueTask($task);

            $updatePayload[] = [
                'id' => $task->getId(),
                'status' => ScheduledTaskDefinition::STATUS_QUEUED,
            ];
        }

        if (count($updatePayload) > 0) {
            $this->scheduledTaskRepository->update($updatePayload, Context::createDefaultContext());
        }
    }

    public function getNextExecutionTime(): ?\DateTime
    {
        $criteria = $this->buildCriteriaForNextScheduledTask();
        /** @var MinAggregationResult $aggregation */
        $aggregation = $this->scheduledTaskRepository
            ->aggregate($criteria, Context::createDefaultContext())
            ->getAggregations()
            ->get('nextExecutionTime');

        return $aggregation->getMin();
    }

    public function getMinRunInterval(): ?int
    {
        $criteria = $this->buildCriteriaForMinRunInterval();
        /** @var MinAggregationResult $aggregation */
        $aggregation = $this->scheduledTaskRepository
            ->aggregate($criteria, Context::createDefaultContext())
            ->getAggregations()
            ->get('runInterval');

        return $aggregation->getMin();
    }

    private function buildCriteriaForAllScheduledTask(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new RangeFilter(
                'nextExecutionTime',
                [
                    RangeFilter::LT => (new \DateTime())->format(DATE_ATOM),
                ]
            ),
            new EqualsFilter('status', ScheduledTaskDefinition::STATUS_SCHEDULED)
        );

        return $criteria;
    }

    private function queueTask(ScheduledTaskEntity $taskEntity): void
    {
        $taskClass = $taskEntity->getScheduledTaskClass();

        if (!in_array(ScheduledTaskInterface::class, class_implements($taskClass))) {
            throw new \RuntimeException(sprintf(
                'Tried to schedule "%s", but class does not implement ScheduledTaskInterface',
                $taskClass
            ));
        }

        /** @var ScheduledTaskInterface $task */
        $task = new $taskClass();
        $task->setTaskId($taskEntity->getId());

        $this->bus->dispatch($task);
    }

    private function buildCriteriaForNextScheduledTask(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('status', ScheduledTaskDefinition::STATUS_SCHEDULED)
        )
        ->addAggregation(new MinAggregation('nextExecutionTime', 'nextExecutionTime'));

        return $criteria;
    }

    private function buildCriteriaForMinRunInterval(): Criteria
    {
        $criteria = new Criteria();
        $criteria->addAggregation(new MinAggregation('runInterval', 'runInterval'));

        return $criteria;
    }
}
