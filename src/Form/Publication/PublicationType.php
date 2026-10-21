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

namespace BackOfficeDefaultTwigBundle\Form\Publication;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One gesture on the publication of a content or a folder: the action, among the ones
 * the current state takes, and the dates it asks for. The dates are free text in the
 * shop's time zone; the core reads them and refuses a date in the wrong order or in the
 * past.
 */
final class PublicationType extends AbstractType
{
    public const string DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', ChoiceType::class, [
                'choices' => $options['actions'],
                'expanded' => true,
                'multiple' => false,
                'choice_translation_domain' => false,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Publication'),
            ])
            ->add('publish_at', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Publication date'),
            ])
            ->add('unpublish_at', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Withdrawal date'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['actions', 'csrf_token_id'])
            ->setAllowedTypes('actions', 'array')
            ->setAllowedTypes('csrf_token_id', 'string');
    }
}
