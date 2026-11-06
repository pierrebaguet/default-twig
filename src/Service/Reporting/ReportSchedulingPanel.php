<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\Reporting;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * How the due reports get sent: by the messenger worker of the shop, by a task of the
 * WebScheduler module when it is active, or by a line of the crontab. The module classes
 * are resolved from a locator that ignores a module that is not there: this service
 * never names them in a signature.
 */
final readonly class ReportSchedulingPanel
{
    private const COMMAND = 'report:send-due';
    private const TASK_TITLE = 'Scheduled reports';
    private const TASK_MIN_INTERVAL_SECONDS = 300;
    private const DEFAULT_EXPRESSION = '*/15 * * * *';

    public function __construct(
        #[AutowireLocator([
            'persister' => '?WebScheduler\Service\Task\TaskPersister',
            'urls' => '?WebScheduler\Service\Url\TriggerUrlBuilder',
        ])]
        private ContainerInterface $webScheduler,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire(env: 'default::THELIA_SCHEDULE_REPORT_MAILS')]
        private ?string $expression,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *     expression: string,
     *     shop_schedule_enabled: bool,
     *     worker_command: string,
     *     web_scheduler: bool,
     *     task: array{id: int, enabled: bool, url: string, admin_url: string}|null,
     *     cron_line: string,
     * }
     */
    public function describe(): array
    {
        $expression = trim((string) $this->expression);
        $webScheduler = $this->webSchedulerIsAvailable();
        $task = $webScheduler ? $this->existingTask() : null;

        return [
            'expression' => $expression,
            'shop_schedule_enabled' => '' !== $expression,
            'worker_command' => 'bin/console messenger:consume scheduler_thelia',
            'web_scheduler' => $webScheduler,
            'task' => null === $task ? null : [
                'id' => (int) $task->getId(),
                'enabled' => (bool) $task->getEnabled(),
                'url' => $this->triggerUrl($task),
                'admin_url' => $this->urls->generate('webscheduler.admin.task.update', ['id' => (int) $task->getId()]),
            ],
            // The expression of the shop schedule when there is one, so that both say the same.
            'cron_line' => \sprintf('%s cd %s && php bin/console %s', '' === $expression ? self::DEFAULT_EXPRESSION : $expression, escapeshellarg($this->projectDir), self::COMMAND),
        ];
    }

    public function webSchedulerIsAvailable(): bool
    {
        if (!class_exists('WebScheduler\Service\Task\TaskPersister') || !$this->webScheduler->has('persister')) {
            return false;
        }

        return null !== ModuleQuery::create()->filterByCode('WebScheduler')->filterByActivate(BaseModule::IS_ACTIVATED)->findOne();
    }

    /**
     * Creates the task of the reports unless there is one: true when it was created.
     */
    public function prepareWebSchedulerTask(): bool
    {
        if (!$this->webSchedulerIsAvailable() || null !== $this->existingTask()) {
            return false;
        }

        $this->webScheduler->get('persister')->create([
            'title' => self::TASK_TITLE,
            'command_name' => self::COMMAND,
            'enabled' => true,
            'min_interval_seconds' => self::TASK_MIN_INTERVAL_SECONDS,
        ]);

        return true;
    }

    private function existingTask(): ?object
    {
        $query = '\WebScheduler\Model\WebSchedulerTaskQuery';

        return $query::create()->filterByCommandName(self::COMMAND)->orderById()->findOne();
    }

    private function triggerUrl(object $task): string
    {
        return $this->webScheduler->has('urls') ? $this->webScheduler->get('urls')->build($task) : '';
    }
}
