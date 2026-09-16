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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ImageMetadataType extends AbstractType
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
            ->add('success_url', HiddenType::class, [
                'required' => false,
            ])
            ->add('title', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('alt', TextType::class, [
                'required' => false,
                // The alt column holds 255 characters: past that the write fails in
                // the driver, so the form says it first.
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Alternative text'),
                'help' => $this->translator->trans('What a screen reader says in place of the image. Left empty, the title is used.'),
            ])
            ->add('decorative', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Decorative image'),
                'help' => $this->translator->trans('A decorative image carries no information and is skipped by screen readers.'),
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
                'label' => $this->translator->trans('This image is online'),
            ])
            ->add('file', FileType::class, [
                'required' => false,
                'label' => $this->translator->trans('Replace current image by this file'),
                'constraints' => [new Image()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.image_metadata',
        ]);
    }
}
