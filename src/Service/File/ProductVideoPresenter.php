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

namespace BackOfficeDefaultTwigBundle\Service\File;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Domain\Media\Video\VideoProvider;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductVideo;
use Thelia\Model\ProductVideoQuery;

/**
 * Builds what the back-office shows of the videos of a product.
 *
 * The address a merchant pasted never comes out of here: a video is presented by
 * its platform, its wording and a thumbnail taken from the product images, and
 * the player itself is only ever built by the front office from the stored
 * identifier.
 */
final readonly class ProductVideoPresenter
{
    private const THUMBNAIL_SIZE = 300;

    public function __construct(
        private EventDispatcherInterface $events,
        private AltTextResolver $altText,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, alt: string, resolved_alt: string, description: string, chapo: string, postscriptum: string, thumbnail_image_id: ?int, provider: string, provider_label: string, hosted: bool, visible: bool, position: int, thumbnail_url: string}>
     */
    public function items(int $productId, string $locale): array
    {
        $videos = ProductVideoQuery::create()
            ->filterByProductId($productId)
            ->orderByPosition()
            ->find();

        $fallbackUrl = $this->firstProductImageUrl($productId);

        $items = [];
        foreach ($videos as $video) {
            $video->setLocale($locale);
            $title = (string) $video->getTitle();
            $alt = (string) $video->getAlt();
            $provider = VideoProvider::tryFrom((string) $video->getProvider()) ?? VideoProvider::File;

            $items[] = [
                'id' => (int) $video->getId(),
                'title' => $title,
                'alt' => $alt,
                'resolved_alt' => $this->altText->resolve($alt, false, $title),
                // The inline form of the grid submits the whole edition form, so it
                // carries the fields it does not show rather than blanking them.
                'description' => (string) $video->getDescription(),
                'chapo' => (string) $video->getChapo(),
                'postscriptum' => (string) $video->getPostscriptum(),
                'thumbnail_image_id' => $video->getThumbnailImageId(),
                'provider' => $provider->value,
                'provider_label' => $provider->label(),
                'hosted' => $video->isHostedFile(),
                'visible' => (bool) $video->getVisible(),
                'position' => (int) $video->getPosition(),
                'thumbnail_url' => $this->thumbnailUrl($video, $fallbackUrl),
            ];
        }

        return $items;
    }

    /**
     * The thumbnail choices of a video: every image of the product, by position.
     *
     * @return list<array{id: int, title: string, filename: string, url: string}>
     */
    public function thumbnailChoices(int $productId, string $locale): array
    {
        $choices = [];

        foreach ($this->productImages($productId) as $image) {
            $image->setLocale($locale);
            $choices[] = [
                'id' => (int) $image->getId(),
                'title' => (string) $image->getTitle(),
                'filename' => (string) $image->getFile(),
                'url' => $this->imageUrl($image),
            ];
        }

        return $choices;
    }

    public function thumbnailUrl(ProductVideo $video, ?string $fallbackUrl = null): string
    {
        $chosen = $video->getThumbnailImage();
        if ($chosen instanceof ProductImage) {
            return $this->imageUrl($chosen);
        }

        return $fallbackUrl ?? $this->firstProductImageUrl((int) $video->getProductId());
    }

    private function firstProductImageUrl(int $productId): string
    {
        $first = ProductImageQuery::create()
            ->filterByProductId($productId)
            ->orderByPosition()
            ->findOne();

        return $first instanceof ProductImage ? $this->imageUrl($first) : '';
    }

    /**
     * @return list<ProductImage>
     */
    private function productImages(int $productId): array
    {
        return iterator_to_array(
            ProductImageQuery::create()
                ->filterByProductId($productId)
                ->orderByPosition()
                ->find(),
            false,
        );
    }

    private function imageUrl(ProductImage $image): string
    {
        $file = (string) $image->getFile();
        if ($file === '') {
            return '';
        }

        $event = new ImageEvent();
        $event->setSourceFilepath($image->getUploadDir().\DIRECTORY_SEPARATOR.$file);
        $event->setCacheSubdirectory('product');
        $event->setWidth(self::THUMBNAIL_SIZE);
        $event->setHeight(self::THUMBNAIL_SIZE);
        $event->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);

        try {
            $this->events->dispatch($event, TheliaEvents::IMAGE_PROCESS);
        } catch (\Throwable) {
            return '';
        }

        return (string) $event->getFileUrl();
    }
}
