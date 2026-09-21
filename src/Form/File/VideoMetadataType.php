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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The full edition screen of a product video.
 *
 * The source can be replaced here, an address by another address or by a file
 * and the other way round: a merchant who mistyped an address, or who re-encoded
 * the film he hosts, fixes it without losing the position, the thumbnail, the
 * wording and the combinations the video is bound to. Both fields left empty mean
 * the video keeps the source it has - which is what an edition of the wording
 * alone posts.
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
            ->add('url', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Replace with another address'),
                'help' => $this->translator->trans('YouTube, Vimeo or Dailymotion. Only the identifier of the video is stored.'),
            ])
            ->add('file', FileType::class, [
                'required' => false,
                'label' => $this->translator->trans('Replace with a file'),
                'help' => $this->translator->trans('Hosted by the shop. MP4, WebM or Ogg.'),
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
                'constraints' => [new Callback($this->checkSource(...))],
            ])
            ->setAllowedTypes('thumbnail_choices', 'array');
    }

    /**
     * A replacement names one source, never two: an address and a file together
     * leave no way to say which one the merchant meant to keep.
     */
    public function checkSource(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_array($value)) {
            return;
        }

        $url = \is_string($value['url'] ?? null) ? trim($value['url']) : '';
        $hasFile = ($value['file'] ?? null) instanceof UploadedFile;

        if ($url !== '' && $hasFile) {
            $context
                ->buildViolation($this->translator->trans('Give an address or a file, not both.'))
                ->atPath('children[url]')
                ->addViolation();
        }
    }
}
