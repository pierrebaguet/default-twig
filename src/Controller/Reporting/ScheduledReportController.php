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

namespace BackOfficeDefaultTwigBundle\Controller\Reporting;

use BackOfficeDefaultTwigBundle\Form\Reporting\ScheduledReportType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Reporting\ReportSchedulingPanel;
use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportChoices;
use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportLabels;
use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportsContext;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Reporting\Mailing\ReportFrequency;
use Thelia\Domain\Reporting\Mailing\ImmediateReportSender;
use Thelia\Domain\Reporting\Mailing\ReportMailingSettings;
use Thelia\Domain\Reporting\Mailing\ReportPeriod;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\ReportMailing;
use Thelia\Model\ReportMailingQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Tools > Export > Scheduled reports: the exports the shop e-mails by itself, to whom and when,
 * and the way the schedule gets run. The sending is the core's: the screen only holds the
 * mailings and the two settings. On a core that predates the scheduled reports every route
 * answers 404.
 */
#[Route('/admin/scheduled-reports', name: 'admin.scheduled-report.')]
final class ScheduledReportController
{
    // The value of AdminResources::EXPORT, written out like the other screens of the theme that
    // must compile on an older core.
    private const RESOURCE = 'admin.export';
    private const LIST_ROUTE = 'admin.scheduled-report.default';
    private const EDIT_ROUTE = 'admin.scheduled-report.edit';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/reports/scheduled-report/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/reports/scheduled-report/edit.html.twig';
    private const FORM_NAME = 'thelia_scheduled_report';
    private const MAX_ATTACHMENT_MB = 100;
    private const MAX_LINK_LIFETIME_DAYS = 14;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly AdminFormErrorRenderer $errors,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly ReportSchedulingPanel $scheduling,
        private readonly ScheduledReportChoices $choices,
        private readonly ScheduledReportLabels $labels,
        // Null on a core that predates the scheduled reports: the class is not there to autowire.
        private readonly ?ImmediateReportSender $sender = null,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = [];
        foreach (ReportMailingQuery::create()->orderByTitle()->find() as $mailing) {
            $rows[] = $this->toRow($mailing, $request->getLocale());
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'attachment_max_mb' => (int) ConfigQuery::read(ReportMailingSettings::ATTACHMENT_MAX_MB, ReportMailingSettings::DEFAULT_ATTACHMENT_MAX_MB),
            'link_lifetime_days' => (int) ConfigQuery::read(ReportMailingSettings::LINK_LIFETIME_DAYS, ReportMailingSettings::DEFAULT_LINK_LIFETIME_DAYS),
            'max_attachment_mb' => self::MAX_ATTACHMENT_MB,
            'max_link_lifetime_days' => self::MAX_LINK_LIFETIME_DAYS,
            'scheduling' => $this->scheduling->describe(),
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->render($request, null, $this->form($request, [
            'frequency' => ReportFrequency::Daily->value,
            'period' => ReportPeriod::Yesterday->value,
            'send_hour' => 7,
            'day_of_week' => 1,
            'day_of_month' => 1,
            'locale' => $request->getLocale(),
            'administrators' => [],
            'enabled' => true,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->submit($request, null);
    }

    #[Route('/{id}', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $mailing = ReportMailingQuery::create()->findPk($id);

        if (null === $mailing) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->render($request, $mailing, $this->form($request, $this->dataOf($mailing)));
    }

    #[Route('/{id}', name: 'save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function save(Request $request, int $id): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $mailing = ReportMailingQuery::create()->findPk($id);

        if (null === $mailing) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->submit($request, $mailing);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(Request $request, int $id): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $mailing = ReportMailingQuery::create()->findPk($id);

        if (null === $mailing || !$this->tokenIsValid($request)) {
            return $back;
        }

        $mailing->setEnabled($mailing->getEnabled() ? 0 : 1)->save();
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Scheduled report %s %s', (string) $mailing->getTitle(), $mailing->getEnabled() ? 'enabled' : 'disabled'), $id);

        return $back;
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $id = (int) $request->request->get('report_id', 0);
        $mailing = ReportMailingQuery::create()->findPk($id);

        if (null === $mailing || !$this->tokenIsValid($request)) {
            return $back;
        }

        $title = (string) $mailing->getTitle();
        $mailing->delete();
        $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('Scheduled report %s deleted', $title), $id);
        $this->flash->add($request, 'success', $this->translator->trans('The report was deleted.'));

        return $back;
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        $id = (int) $request->request->get('report_id', 0);
        $mailing = ReportMailingQuery::create()->findPk($id);

        if (null === $mailing || null === $this->sender || !$this->tokenIsValid($request)) {
            return $back;
        }

        try {
            $result = $this->sender->send($mailing);
        } catch (\Throwable $failure) {
            $this->errors->fail($this->translator->trans('Sending a scheduled report'), $failure);

            return $back;
        }

        if (null === $result) {
            $this->flash->add($request, 'warning', $this->translator->trans('The report is being sent by a scheduled run: nothing was sent, try again in a few minutes.'));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Scheduled report %s sent now', (string) $mailing->getTitle()), $id);
        $this->flash->add(
            $request,
            [] === $result->failed && [] === $result->rejected ? 'success' : 'warning',
            $this->translator->trans('Report sent to %sent%, failed for %failed%, rejected for %rejected%.', [
                '%sent%' => \count($result->delivered),
                '%failed%' => \count($result->failed),
                '%rejected%' => \count($result->rejected),
            ]),
        );

        return $back;
    }

    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            return $back;
        }

        $attachment = filter_var($request->request->get('attachment_max_mb'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_ATTACHMENT_MB]]);
        $lifetime = filter_var($request->request->get('link_lifetime_days'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LINK_LIFETIME_DAYS]]);

        if (false === $attachment || false === $lifetime) {
            $this->flash->add($request, 'danger', $this->translator->trans('The settings were not saved: check the two values.'));

            return $back;
        }

        ConfigQuery::write(ReportMailingSettings::ATTACHMENT_MAX_MB, (string) $attachment);
        ConfigQuery::write(ReportMailingSettings::LINK_LIFETIME_DAYS, (string) $lifetime);
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Scheduled reports settings saved');
        $this->flash->add($request, 'success', $this->translator->trans('The settings were saved.'));

        return $back;
    }

    #[Route('/web-scheduler', name: 'prepare-web-scheduler', methods: ['POST'])]
    public function prepareWebScheduler(Request $request): Response
    {
        $this->guard();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            return $back;
        }

        try {
            $created = $this->scheduling->prepareWebSchedulerTask();
        } catch (\Throwable $failure) {
            $this->errors->fail($this->translator->trans('Preparing the WebScheduler task'), $failure);

            return $back;
        }

        if ($created) {
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'WebScheduler task of the scheduled reports prepared');
            $this->flash->add($request, 'success', $this->translator->trans('The WebScheduler task was prepared.'));
        } else {
            $this->flash->add($request, 'warning', $this->translator->trans('Nothing was prepared: the task already exists, or WebScheduler is not active.'));
        }

        return $back;
    }

    private function submit(Request $request, ?ReportMailing $mailing): Response
    {
        $form = $this->form($request, []);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $values */
            $values = $form->getData();
            $mailing ??= new ReportMailing();
            $isNew = $mailing->isNew();
            $frequency = (string) $values['frequency'];

            $mailing
                ->setTitle((string) $values['title'])
                ->setExportRef((string) $values['export_ref'])
                ->setPeriod((string) $values['period'])
                ->setFrequency($frequency)
                ->setDayOfWeek(ReportFrequency::Weekly->value === $frequency ? (int) $values['day_of_week'] : null)
                ->setDayOfMonth(ReportFrequency::Monthly->value === $frequency ? (int) $values['day_of_month'] : null)
                ->setSendHour((int) $values['send_hour'])
                ->setLocale((string) $values['locale'])
                ->setRecipients(implode(',', array_values(array_unique([
                    ...array_map('strval', (array) ($values['administrators'] ?? [])),
                    ...ScheduledReportType::otherAddresses((string) ($values['other_recipients'] ?? '')),
                ]))))
                ->setEnabled(empty($values['enabled']) ? 0 : 1)
                ->save();

            // Title and id only: the recipients stay out of the audit log.
            $this->adminLogger->log(self::RESOURCE, $isNew ? AccessManager::CREATE : AccessManager::UPDATE, \sprintf('Scheduled report %s saved', (string) $mailing->getTitle()), (int) $mailing->getId());
            $this->flash->add($request, 'success', $this->translator->trans('The report was saved.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->render($request, $mailing, $form);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function form(Request $request, array $data): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, ScheduledReportType::class, $data, ['ui_locale' => $request->getLocale()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(ReportMailing $mailing): array
    {
        $administratorEmails = array_values($this->choices->administrators());
        $recipients = $mailing->getRecipientList();

        return [
            'title' => $mailing->getTitle(),
            'export_ref' => $mailing->getExportRef(),
            'period' => $mailing->getPeriod(),
            'frequency' => $mailing->getFrequency(),
            'day_of_week' => $mailing->getDayOfWeek() ?? 1,
            'day_of_month' => $mailing->getDayOfMonth() ?? 1,
            'send_hour' => (int) $mailing->getSendHour(),
            'locale' => $mailing->getLocale(),
            'administrators' => array_values(array_intersect($recipients, $administratorEmails)),
            'other_recipients' => implode("\n", array_diff($recipients, $administratorEmails)),
            'enabled' => (bool) $mailing->getEnabled(),
        ];
    }

    private function render(Request $request, ?ReportMailing $mailing, FormInterface $form): Response
    {
        $response = new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'report' => $mailing,
            'form' => $form->createView(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(ReportMailing $mailing, string $locale): array
    {
        $id = (int) $mailing->getId();
        $export = ExportQuery::create()->findOneByRef((string) $mailing->getExportRef());
        $lastSent = $mailing->getLastSentAt();
        $pending = \count($mailing->getPendingRecipientList());
        $period = ReportPeriod::tryFrom((string) $mailing->getPeriod());

        return [
            'id' => $id,
            'title' => (string) $mailing->getTitle(),
            // The period goes with the export it applies to.
            'export' => (null === $export ? (string) $mailing->getExportRef() : (string) $export->setLocale($locale)->getTitle()).(null === $period ? '' : ' ('.mb_strtolower($this->labels->period($period)).')'),
            'frequency' => $this->labels->schedule($mailing),
            'recipients' => \count($mailing->getRecipientList()),
            'enabled' => (bool) $mailing->getEnabled(),
            'toggle_url' => $this->urls->generate('admin.scheduled-report.toggle', ['id' => $id]),
            'last_sent' => null === $lastSent ? $this->translator->trans('Never') : $lastSent->format('Y-m-d H:i'),
            'pending' => $pending,
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]), grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE),
                new RowAction(kind: 'send', label: $this->translator->trans('Send now'), modalTarget: '#scheduled-report-send-modal', grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE, dataAttributes: ['report-id' => $id, 'report-label' => (string) $mailing->getTitle()]),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#scheduled-report-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['report-id' => $id, 'report-label' => (string) $mailing->getTitle()]),
            ],
        ];
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (\Throwable) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return false;
        }
    }

    /**
     * A core that predates the scheduled reports has no screen to show.
     */
    private function guard(): void
    {
        if (!ScheduledReportsContext::coreHasScheduledReports()) {
            throw new NotFoundHttpException();
        }
    }
}
