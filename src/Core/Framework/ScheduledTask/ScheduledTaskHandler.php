<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ScheduledTask;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;

abstract class ScheduledTaskHandler extends AbstractMessageHandler
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $scheduledTaskRepository;

    public function __construct(EntityRepositoryInterface $scheduledTaskRepository)
    {
        $this->scheduledTaskRepository = $scheduledTaskRepository;
    }

    abstract public function run(): void;

    /**
     * @param ScheduledTaskInterface $task
     */
    public function handle(object $task): void
    {
        /** @var ScheduledTaskEntity|null $taskEntity */
        $taskEntity = $this->scheduledTaskRepository
            ->search(new Criteria([$task->getTaskId()]), Context::createDefaultContext())
            ->get($task->getTaskId());

        if ((!$taskEntity) || ($taskEntity->getStatus() !== ScheduledTaskDefinition::STATUS_QUEUED)) {
            return;
        }

        $this->markTaskRunning($task);

        try {
            $this->run();
        } catch (\Throwable $e) {
            $this->markTaskFailed($task);

            throw $e;
        }

        $this->rescheduleTask($task, $taskEntity);
    }

    protected function markTaskRunning(ScheduledTaskInterface $task): void
    {
        $this->scheduledTaskRepository->update([
            [
                'id' => $task->getTaskId(),
                'status' => ScheduledTaskDefinition::STATUS_RUNNING,
            ],
        ], Context::createDefaultContext());
    }

    protected function markTaskFailed(ScheduledTaskInterface $task): void
    {
        $this->scheduledTaskRepository->update([
            [
                'id' => $task->getTaskId(),
                'status' => ScheduledTaskDefinition::STATUS_FAILED,
            ],
        ], Context::createDefaultContext());
    }

    protected function rescheduleTask(ScheduledTaskInterface $task, ScheduledTaskEntity $taskEntity): void
    {
        $now = new \DateTime();
        $this->scheduledTaskRepository->update([
            [
                'id' => $task->getTaskId(),
                'status' => ScheduledTaskDefinition::STATUS_SCHEDULED,
                'lastExecutionTime' => $now,
                'nextExecutionTime' => $now->modify(sprintf('+%d seconds', $taskEntity->getRunInterval())),
            ],
        ], Context::createDefaultContext());
    }
}
