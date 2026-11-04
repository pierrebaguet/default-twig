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

use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use Thelia\Model\Admin;
use Thelia\Model\AdminQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\LangQuery;

/**
 * What the form of a scheduled report offers: the exports that can run, whether each is
 * bounded by a date range, the shop languages and the administrators who can be written to.
 */
final readonly class ScheduledReportChoices
{
    public function __construct(private DataTransferRepository $transfers)
    {
    }

    /**
     * @return array<string, array<string, string>> export ref by title, grouped by category title
     */
    public function exports(string $locale): array
    {
        $groups = [];
        foreach ($this->transfers->findExportCatalogue($locale) as $category) {
            foreach ($category['exports'] as $export) {
                if ($export['handler_available']) {
                    $groups[$category['title']][$export['title']] = $export['ref'];
                }
            }
        }

        return $groups;
    }

    /**
     * @return list<string> the refs of the available exports that have a date range
     */
    public function refsWithDateRange(): array
    {
        $refs = [];
        foreach (ExportQuery::create()->find() as $export) {
            if ($export->isHandlerAvailable() && $export->getHandleClassInstance()->useRangeDate()) {
                $refs[] = (string) $export->getRef();
            }
        }

        return $refs;
    }

    /**
     * @return array<string, string> locale by language title
     */
    public function languages(): array
    {
        $languages = [];
        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            $languages[(string) $lang->getTitle()] = (string) $lang->getLocale();
        }

        return $languages;
    }

    /**
     * @return array<string, string> e-mail address by label, for the administrators who have one
     */
    public function administrators(): array
    {
        $administrators = [];
        foreach (AdminQuery::create()->orderByLastname()->find() as $admin) {
            /** @var Admin $admin */
            $email = trim((string) $admin->getEmail());
            if ('' !== $email) {
                $administrators[trim($admin->getFirstname().' '.$admin->getLastname()).' ('.$email.')'] = $email;
            }
        }

        return $administrators;
    }
}
