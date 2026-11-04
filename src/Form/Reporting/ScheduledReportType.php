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

namespace BackOfficeDefaultTwigBundle\Form\Reporting;

use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportChoices;
use BackOfficeDefaultTwigBundle\Service\Reporting\ScheduledReportLabels;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Reporting\Mailing\ReportFrequency;
use Thelia\Domain\Reporting\Mailing\ReportPeriod;

/**
 * A scheduled report: what is sent (an export over a period), when, in which language and to
 * whom. The recipients are administrators of the shop, ticked, and other addresses, typed one
 * per line: sales figures leave the shop only to addresses the merchant confirmed.
 */
final class ScheduledReportType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ScheduledReportChoices $choices,
        private readonly ScheduledReportLabels $labels,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = (string) $options['ui_locale'];
        $rangeRefs = $this->choices->refsWithDateRange();
        $exports = $this->choices->exports($locale);

        $withoutRange = [];
        foreach ($exports as $group) {
            foreach ($group as $title => $ref) {
                if (!\in_array($ref, $rangeRefs, true)) {
                    $withoutRange[] = $title;
                }
            }
        }

        $periods = [];
        foreach (ReportPeriod::cases() as $period) {
            $periods[$this->labels->period($period)] = $period->value;
        }

        $frequencies = [
            $this->translator->trans('Every day') => ReportFrequency::Daily->value,
            $this->translator->trans('Every week') => ReportFrequency::Weekly->value,
            $this->translator->trans('Every month') => ReportFrequency::Monthly->value,
        ];

        $days = $this->labels->weekdays();

        $monthDays = [];
        foreach (range(1, 28) as $day) {
            $monthDays[(string) $day] = $day;
        }

        $hours = [];
        foreach (range(0, 23) as $hour) {
            $hours[\sprintf('%02d:00', $hour)] = $hour;
        }

        $administrators = $this->choices->administrators();

        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'label' => $this->translator->trans('Name of the report'),
                'help' => $this->translator->trans('Used as the subject of the e-mail.'),
            ])
            ->add('export_ref', ChoiceType::class, [
                'choices' => $exports,
                'placeholder' => $this->translator->trans('Choose an export'),
                'constraints' => [new NotBlank()],
                'choice_attr' => static fn (mixed $ref): array => ['data-date-range' => \in_array($ref, $rangeRefs, true) ? '1' : '0'],
                'label' => $this->translator->trans('Export'),
                'help' => $this->translator->trans('The export that is run and sent as a CSV file.'),
            ])
            ->add('period', ChoiceType::class, [
                'choices' => $periods,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Period covered'),
                'help' => $this->translator->trans('The period applies only to the exports that have a date range. The other ones always cover all their data.')
                    .([] === $withoutRange ? '' : ' '.$this->translator->trans('Without a date range: %exports%.', ['%exports%' => implode(', ', $withoutRange)])),
            ])
            ->add('frequency', ChoiceType::class, [
                'choices' => $frequencies,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Frequency'),
            ])
            ->add('day_of_week', ChoiceType::class, [
                'required' => false,
                'choices' => $days,
                'placeholder' => false,
                'label' => $this->translator->trans('Day of the week'),
                'help' => $this->translator->trans('Used by a weekly report.'),
            ])
            ->add('day_of_month', ChoiceType::class, [
                'required' => false,
                'choices' => $monthDays,
                'placeholder' => false,
                'label' => $this->translator->trans('Day of the month'),
                'help' => $this->translator->trans('Used by a monthly report. Limited to the 28th so that every month has it.'),
            ])
            ->add('send_hour', ChoiceType::class, [
                'choices' => $hours,
                'constraints' => [new Choice(choices: range(0, 23))],
                'label' => $this->translator->trans('Sent from'),
                'help' => $this->translator->trans('Hour of the server. The report leaves at the first run of the schedule after this hour.'),
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => $this->choices->languages(),
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Language of the e-mail and of the file'),
            ])
            ->add('administrators', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $administrators,
                'label' => $this->translator->trans('Administrators of the shop'),
            ])
            ->add('other_recipients', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 4, 'data-testid' => 'report-other-recipients'],
                'label' => $this->translator->trans('Other addresses'),
                'help' => $this->translator->trans('One address per line.'),
            ])
            ->add('confirm_external', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('I confirm that these addresses may receive the sales figures of the shop'),
            ])
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Report enabled'),
                'help' => $this->translator->trans('Untick to stop the report without deleting it.'),
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateAsAWhole(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['ui_locale' => 'en_US', 'csrf_token_id' => 'admin.export'])
            ->setAllowedTypes('ui_locale', 'string');
    }

    /**
     * The addresses typed in the free field, one per line, without blanks or duplicates.
     *
     * @return list<string>
     */
    public static function otherAddresses(?string $text): array
    {
        $addresses = [];
        foreach (preg_split('/[\r\n,;]+/', (string) $text) ?: [] as $line) {
            $address = trim($line);
            if ('' !== $address && !\in_array(strtolower($address), array_map('strtolower', $addresses), true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function validateAsAWhole(FormEvent $event): void
    {
        $form = $event->getForm();
        /** @var array<string, mixed> $data */
        $data = $event->getData();

        $others = self::otherAddresses($data['other_recipients'] ?? null);

        foreach ($others as $address) {
            if (\count($this->validator->validate($address, new Email())) > 0) {
                $form->get('other_recipients')->addError(new FormError($this->translator->trans('"%address%" is not a valid e-mail address.', ['%address%' => $address])));
            }
        }

        if ([] !== $others && empty($data['confirm_external'])) {
            $form->get('confirm_external')->addError(new FormError($this->translator->trans('Confirm that these addresses may receive the sales figures of the shop.')));
        }

        if ([] === $others && [] === ($data['administrators'] ?? [])) {
            $form->get('administrators')->addError(new FormError($this->translator->trans('Choose at least one recipient.')));
        }

        if (ReportFrequency::Weekly->value === ($data['frequency'] ?? null) && empty($data['day_of_week'])) {
            $form->get('day_of_week')->addError(new FormError($this->translator->trans('Choose the day of the week.')));
        }

        if (ReportFrequency::Monthly->value === ($data['frequency'] ?? null) && empty($data['day_of_month'])) {
            $form->get('day_of_month')->addError(new FormError($this->translator->trans('Choose the day of the month.')));
        }
    }
}
