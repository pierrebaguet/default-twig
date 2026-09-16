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

namespace BackOfficeDefaultTwigBundle\Controller\File;

use BackOfficeDefaultTwigBundle\Form\File\VideoMetadataType;
use BackOfficeDefaultTwigBundle\Form\File\VideoType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\File\ProductVideoPresenter;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Media\DTO\ProductVideoCreateDTO;
use Thelia\Domain\Media\DTO\ProductVideoUpdateDTO;
use Thelia\Domain\Media\MediaFacade;
use Thelia\Domain\Media\Video\UnsupportedVideoUrlException;
use Thelia\Domain\Media\Video\VideoProviderResolver;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductVideo;
use Thelia\Model\ProductVideoQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The videos of a product, in the back-office.
 *
 * The address a merchant pastes is turned into a platform and an identifier as
 * soon as it is submitted, and nothing else of it is kept or rendered: no page
 * of this screen ever carries it, in a preview or in an attribute. What a
 * merchant sees of a video is its thumbnail, taken from the product images.
 */
final class VideoController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const FORM_NAME = 'thelia_product_video_creation';
    private const EDIT_FORM_NAME = 'thelia_product_video_modification';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly FormFactoryInterface $formFactory,
        private readonly MediaFacade $media,
        private readonly VideoProviderResolver $providers,
        private readonly ProductVideoPresenter $presenter,
        private readonly EditLocaleResolver $editLocale,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/video/product/{productId}/list-ajax', name: 'admin.video.list-ajax', methods: ['GET'], requirements: ['productId' => '\d+'])]
    public function videoList(int $productId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_video_list.html.twig', $this->listContext($productId, $request)));
    }

    #[Route('/admin/video/product/{productId}/form-ajax', name: 'admin.video.form-ajax', methods: ['GET'], requirements: ['productId' => '\d+'])]
    public function videoForm(int $productId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_video_form.html.twig', [
            'product_id' => $productId,
            'form' => $this->createAddForm()->createView(),
        ] + $this->listContext($productId, $request)));
    }

    #[Route('/admin/video/product/{productId}/save-ajax', name: 'admin.video.save-ajax', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function videoSave(int $productId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $form = $this->createAddForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderAddForm($productId, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        $url = trim((string) ($data['url'] ?? ''));
        $uploaded = $data['file'] ?? null;

        try {
            $resolved = $url !== '' ? $this->providers->resolve($url) : null;

            $this->media->createVideo(new ProductVideoCreateDTO(
                productId: $productId,
                provider: $resolved?->provider,
                externalId: $resolved?->externalId,
                uploadedFile: $uploaded instanceof UploadedFile ? $uploaded : null,
                locale: $this->currentEditLocale($request),
                title: (string) ($data['title'] ?? ''),
                alt: (string) ($data['alt'] ?? ''),
                visible: (bool) ($data['visible'] ?? false),
            ));
        } catch (UnsupportedVideoUrlException $exception) {
            // The only message a merchant can act on: it names the platforms his own
            // shop accepts. Everything else below is for the log, not for the screen.
            return $this->addFormError($productId, $form, 'url', $this->translator->trans(
                'This address is not recognised. Accepted platforms: %platforms%.',
                ['%platforms%' => $exception->getEnabledProviderLabels()],
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Product video creation failed.', ['exception' => $exception, 'product_id' => $productId]);

            return $this->addFormError($productId, $form, 'file', $this->translator->trans('This video could not be added.'));
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/admin/video/product/{productId}/{videoId}/delete', name: 'admin.video.delete', methods: ['POST'], requirements: ['productId' => '\d+', 'videoId' => '\d+'])]
    public function videoDelete(int $productId, int $videoId): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        $this->checkCsrf();

        $video = $this->loadVideo($productId, $videoId);
        if ($video === null) {
            return new JsonResponse(['error' => $this->translator->trans('Video not found.')], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->media->deleteVideo($video);
        } catch (\Throwable $exception) {
            $this->logger->error('Product video deletion failed.', ['exception' => $exception, 'video_id' => $videoId]);

            return new JsonResponse(['error' => $this->translator->trans('This video could not be deleted.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/admin/video/product/{productId}/{videoId}/toggle', name: 'admin.video.toggle.process', methods: ['POST'], requirements: ['productId' => '\d+', 'videoId' => '\d+'])]
    public function videoToggleVisibility(int $productId, int $videoId): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $video = $this->loadVideo($productId, $videoId);
        if ($video === null) {
            return new JsonResponse(['error' => $this->translator->trans('Video not found.')], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->media->toggleVideoVisibility($video);
        } catch (\Throwable $exception) {
            $this->logger->error('Product video visibility change failed.', ['exception' => $exception, 'video_id' => $videoId]);

            return new JsonResponse(['error' => $this->translator->trans('This video could not be saved.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/admin/video/product/{productId}/update-position', name: 'admin.video.update-position', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function videoUpdatePosition(int $productId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $videoId = (int) $request->request->get('file_id', 0);
        $position = (int) $request->request->get('position', 0);
        if ($videoId === 0 || $position === 0) {
            return new JsonResponse(['error' => $this->translator->trans('Missing file_id or position.')], Response::HTTP_BAD_REQUEST);
        }

        $video = $this->loadVideo($productId, $videoId);
        if ($video === null) {
            return new JsonResponse(['error' => $this->translator->trans('Video not found.')], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->media->updateVideoPosition($video, $position);
        } catch (\Throwable $exception) {
            $this->logger->error('Product video reordering failed.', ['exception' => $exception, 'video_id' => $videoId]);

            return new JsonResponse(['error' => $this->translator->trans('This video could not be saved.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/admin/video/product/{productId}/{videoId}/update', name: 'admin.video.update.view', methods: ['GET'], requirements: ['productId' => '\d+', 'videoId' => '\d+'])]
    public function videoUpdateView(int $productId, int $videoId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $video = $this->loadVideo($productId, $videoId);
        if ($video === null) {
            return new RedirectResponse($this->productUrl($productId));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $video->setLocale($locale);

        $form = $this->createEditForm($productId, $locale, [
            'id' => $videoId,
            'locale' => $locale,
            'title' => (string) $video->getTitle(),
            'alt' => (string) $video->getAlt(),
            'chapo' => (string) $video->getChapo(),
            'postscriptum' => (string) $video->getPostscriptum(),
            'description' => (string) $video->getDescription(),
            'visible' => (bool) $video->getVisible(),
            'thumbnail_image_id' => $video->getThumbnailImageId(),
        ]);

        return new Response($this->twig->render(
            '@BackOfficeDefaultTwig/file/video-edit.html.twig',
            $this->editContext($productId, $video, $form, (int) $editLang->getId(), $locale),
        ));
    }

    #[Route('/admin/video/product/{productId}/{videoId}/update', name: 'admin.video.update.process', methods: ['POST'], requirements: ['productId' => '\d+', 'videoId' => '\d+'])]
    public function videoUpdateProcess(int $productId, int $videoId, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $video = $this->loadVideo($productId, $videoId);
        if ($video === null) {
            return new RedirectResponse($this->productUrl($productId));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';

        $form = $this->createEditForm($productId, $locale);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $video->setLocale($locale);

            return new Response(
                $this->twig->render(
                    '@BackOfficeDefaultTwig/file/video-edit.html.twig',
                    $this->editContext($productId, $video, $form, (int) $editLang->getId(), $locale),
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $data = $form->getData();
        $submittedLocale = (string) ($data['locale'] ?? '');

        $thumbnailId = $data['thumbnail_image_id'] ?? null;

        $this->media->updateVideo($video, new ProductVideoUpdateDTO(
            locale: $submittedLocale !== '' ? $submittedLocale : $locale,
            // `false` is the only way to say "no thumbnail any more"; null would mean
            // "the caller said nothing" and would keep the image that is set.
            thumbnailImageId: $thumbnailId === null || $thumbnailId === '' ? false : (int) $thumbnailId,
            title: (string) ($data['title'] ?? ''),
            alt: (string) ($data['alt'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            chapo: (string) ($data['chapo'] ?? ''),
            postscriptum: (string) ($data['postscriptum'] ?? ''),
            visible: !empty($data['visible']),
        ));

        if ((string) $request->request->get('save_mode', 'stay') === 'close') {
            return new RedirectResponse($this->productUrl($productId));
        }

        return new RedirectResponse($this->urls->generate('admin.video.update.view', [
            'productId' => $productId,
            'videoId' => $videoId,
        ]));
    }

    private function checkCsrf(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $token = (string) ($request?->request->get('_token') ?? $request?->query->get('_token') ?? '');
        $this->tokens->checkToken($token);
    }

    private function loadVideo(int $productId, int $videoId): ?ProductVideo
    {
        return ProductVideoQuery::create()
            ->filterByProductId($productId)
            ->filterById($videoId)
            ->findOne();
    }

    private function createAddForm(): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, VideoType::class, ['visible' => true]);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function createEditForm(int $productId, string $locale, ?array $data = null): FormInterface
    {
        // Two images of a product may share a title, or have none: the id is what
        // keeps every entry of the list distinct, and selectable.
        $choices = [];
        foreach ($this->presenter->thumbnailChoices($productId, $locale) as $image) {
            $label = $image['title'] !== '' ? $image['title'] : $image['filename'];
            $choices[\sprintf('%s #%d', $label, $image['id'])] = $image['id'];
        }

        return $this->formFactory->createNamed(self::EDIT_FORM_NAME, VideoMetadataType::class, $data, [
            'thumbnail_choices' => $choices,
        ]);
    }

    private function renderAddForm(int $productId, FormInterface $form, int $status): Response
    {
        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_video_add_fields.html.twig', [
            'product_id' => $productId,
            'form' => $form->createView(),
        ]), $status);
    }

    private function addFormError(int $productId, FormInterface $form, string $field, string $message): Response
    {
        $form->get($field)->addError(new FormError($message));

        return $this->renderAddForm($productId, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<string, mixed>
     */
    private function listContext(int $productId, Request $request): array
    {
        $locale = $this->currentEditLocale($request);

        return [
            'product_id' => $productId,
            'edit_locale' => $locale,
            'items' => $this->presenter->items($productId, $locale),
            'can_update' => $this->access->check(self::RESOURCE, [], AccessManager::UPDATE) === null,
            'can_delete' => $this->access->check(self::RESOURCE, [], AccessManager::DELETE) === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editContext(int $productId, ProductVideo $video, FormInterface $form, int $editLanguageId, string $locale): array
    {
        $provider = (string) $video->getProvider();

        return [
            'product_id' => $productId,
            'video_id' => (int) $video->getId(),
            'video_title' => (string) $video->getTitle(),
            'provider' => $provider,
            'hosted' => $video->isHostedFile(),
            'thumbnail_url' => $this->presenter->thumbnailUrl($video),
            'thumbnail_choices' => $this->presenter->thumbnailChoices($productId, $locale),
            'form' => $form->createView(),
            'edit_language_id' => $editLanguageId,
            'close_url' => $this->productUrl($productId),
            'edit_url' => $this->urls->generate('admin.video.update.view', ['productId' => $productId, 'videoId' => $video->getId()]),
        ];
    }

    private function productUrl(int $productId): string
    {
        return $this->urls->generate('admin.products.update', ['product_id' => $productId, 'current_tab' => 'videos']);
    }

    private function currentEditLocale(Request $request): string
    {
        return $this->editLocale->resolveFromRequest($request)->getLocale()
            ?? LangQuery::create()->findOneByByDefault(1)?->getLocale()
            ?? 'en_US';
    }
}
