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

namespace BackOfficeDefaultTwigBundle\Form\File;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The full edition screen of a product video. The address is not editable here:
 * a merchant who wants another video adds one, so a stored identifier is never
 * silently swapped under an existing position and thumbnail.
 */
final class VideoMetadataType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Title'),
            ])
            ->add('alt', TextType::class, [
                'required' => false,
                // The alt column holds 255 characters: past that the write fails in
                // the driver, so the form says it first.
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Alternative text'),
                'help' => $this->translator->trans('What a screen reader says in place of the video. Left empty, the title is used.'),
            ])
            ->add('chapo', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Summary'),
            ])
            ->add('postscriptum', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Conclusion'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Detailed description'),
            ])
            ->add('visible', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('This video is online'),
            ])
            ->add('thumbnail_image_id', ChoiceType::class, [
                'required' => false,
                'label' => $this->translator->trans('Thumbnail'),
                'choices' => $options['thumbnail_choices'],
                'placeholder' => $this->translator->trans('First product image'),
                'help' => $this->translator->trans('Shown until the visitor starts the video.'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'csrf_token_id' => 'admin.video_metadata',
                'thumbnail_choices' => [],
            ])
            ->setAllowedTypes('thumbnail_choices', 'array');
    }
}
