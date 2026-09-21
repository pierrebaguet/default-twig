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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Media\Video\UnsupportedVideoUrlException;
use Thelia\Domain\Media\Video\VideoProviderResolver;

/**
 * Attaching a video to a product: either the address of a video on a platform,
 * or a file the shop will host. The two are exclusive, and one is required.
 */
final class VideoType extends AbstractType
{
    /** The name the add form is posted under, on the product images tab. */
    public const NAME = 'thelia_product_video_creation';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly VideoProviderResolver $providers,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Video address'),
                'help' => $this->translator->trans('YouTube, Vimeo or Dailymotion. Only the identifier of the video is stored.'),
            ])
            ->add('file', FileType::class, [
                'required' => false,
                'label' => $this->translator->trans('Video file'),
                'help' => $this->translator->trans('Upload a video the shop will host itself.'),
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Title'),
            ])
            // The accessible name belongs to the moment a video is added: asking a
            // merchant to reopen an edition screen for it is how a video ends up
            // published without one.
            ->add('alt', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Alternative text'),
                'help' => $this->translator->trans('What a screen reader says in place of the video. Left empty, the title is used.'),
            ])
            ->add('visible', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('This video is online'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.video_add',
            'constraints' => [new Callback($this->checkSource(...))],
        ]);
    }

    public function checkSource(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_array($value)) {
            return;
        }

        $url = \is_string($value['url'] ?? null) ? trim($value['url']) : '';
        $hasFile = ($value['file'] ?? null) instanceof UploadedFile;

        if ($url === '' && !$hasFile) {
            $context
                ->buildViolation($this->translator->trans('Give the address of a video, or upload a video file.'))
                ->atPath('children[url]')
                ->addViolation();

            return;
        }

        if ($url !== '' && $hasFile) {
            $context
                ->buildViolation($this->translator->trans('Give an address or a file, not both.'))
                ->atPath('children[url]')
                ->addViolation();

            return;
        }

        if ($url === '') {
            return;
        }

        try {
            $this->providers->resolve($url);
        } catch (UnsupportedVideoUrlException $exception) {
            $context
                ->buildViolation($this->translator->trans(
                    'This address is not recognised. Accepted platforms: %platforms%.',
                    ['%platforms%' => $exception->getEnabledProviderLabels()],
                ))
                ->atPath('children[url]')
                ->addViolation();
        }
    }
}
