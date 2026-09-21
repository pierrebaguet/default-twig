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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductVideo;
use Thelia\Model\ProductVideoQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tests\Support\Trait\CreatesTestFiles;

/**
 * The media grid of a product posts its whole order on a drop: images and videos
 * share one sequence, and the route that writes it has to refuse a list that no
 * longer matches the product rather than write half of it.
 */
final class ProductMediaReorderBackOfficeTest extends WebIntegrationTestCase
{
    use CreatesTestFiles;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->cleanUpTestFiles();
        parent::tearDown();
    }

    public function testTheGridShowsImagesAndVideosInOneList(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();

        $html = $this->gridOf($product);

        self::assertStringContainsString('data-bo-file-list-reorder-url-value', $html, 'The grid of a product carries the reorder address.');
        self::assertStringContainsString(\sprintf('bo-video-item-%d', $video->getId()), $html);
        self::assertStringContainsString(\sprintf('bo-file-alt-input-%d', $firstImage->getId()), $html);
        self::assertSame(
            [
                'image:'.$firstImage->getId(),
                'video:'.$video->getId(),
                'image:'.$secondImage->getId(),
            ],
            $this->orderShownBy($html),
            'The cards come in the order of the shared sequence.',
        );
    }

    public function testTheOrderOfTheGridIsWrittenAcrossBothTables(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();

        $this->postOrder($product, [
            'video:'.$video->getId(),
            'image:'.$secondImage->getId(),
            'image:'.$firstImage->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->positionOf($video));
        self::assertSame(2, $this->positionOf($secondImage));
        self::assertSame(3, $this->positionOf($firstImage));
    }

    public function testAListLeavingAMediumOutIsRefusedAndNothingMoves(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();

        $this->postOrder($product, [
            'video:'.$video->getId(),
            'image:'.$firstImage->getId(),
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->positionOf($firstImage));
        self::assertSame(2, $this->positionOf($video));
        self::assertSame(3, $this->positionOf($secondImage));
    }

    public function testAMediumOfAnotherProductIsRefused(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();
        [, , $foreignVideo] = $this->productWithMixedMedia();

        $this->postOrder($product, [
            'video:'.$foreignVideo->getId(),
            'image:'.$firstImage->getId(),
            'image:'.$secondImage->getId(),
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->positionOf($video), 'The video of the product keeps its place.');
        self::assertSame(2, $this->positionOf($foreignVideo), 'The video of the other product is not touched.');
    }

    public function testAMalformedEntryIsRefusedBeforeAnythingIsRead(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();

        $this->postOrder($product, ['document:'.$firstImage->getId(), 'video:'.$video->getId(), 'image:'.$secondImage->getId()]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->postOrder($product, []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testWithoutATokenNothingIsWritten(): void
    {
        [$product, $firstImage, $video, $secondImage] = $this->productWithMixedMedia();

        $this->client->request('POST', \sprintf('/admin/product/%d/media/reorder', $product->getId()), [
            'order' => ['video:'.$video->getId(), 'image:'.$secondImage->getId(), 'image:'.$firstImage->getId()],
        ]);

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->positionOf($firstImage));
        self::assertSame(2, $this->positionOf($video));
    }

    /**
     * A product with an image, a video and a second image, in that order.
     *
     * @return array{Product, ProductImage, ProductVideo, ProductImage}
     */
    private function productWithMixedMedia(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
        );

        $firstImage = $this->productImage($product);
        $video = $this->factory->productVideo($product);
        $secondImage = $this->productImage($product);

        return [$product, $firstImage, $video, $secondImage];
    }

    private function productImage(Product $product): ProductImage
    {
        $image = $this->factory->productImage($product);
        $this->trackFileForCleanup($image->getUploadDir().\DIRECTORY_SEPARATOR.$image->getFile());

        return $image;
    }

    /**
     * @param list<string> $order
     */
    private function postOrder(Product $product, array $order): void
    {
        $this->client->request('POST', \sprintf('/admin/product/%d/media/reorder', $product->getId()), [
            'order' => $order,
            '_token' => $this->tokenOf($product),
        ]);
    }

    private function gridOf(Product $product): string
    {
        $this->client->request('GET', \sprintf('/admin/image/type/product/%d/list-ajax', $product->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    /** The token the grid carries, the one its drop posts. */
    private function tokenOf(Product $product): string
    {
        self::assertSame(1, preg_match('/data-bo-file-list-token-value="([^"]+)"/', $this->gridOf($product), $matches), 'The grid renders no token.');

        return html_entity_decode($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function orderShownBy(string $html): array
    {
        preg_match_all('/data-file-id="(\d+)"\s+data-media-type="(\w+)"/', $html, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): string => $match[2].':'.$match[1], $matches);
    }

    private function positionOf(ProductImage|ProductVideo $medium): int
    {
        $reloaded = $medium instanceof ProductVideo
            ? ProductVideoQuery::create()->findPk($medium->getId())
            : ProductImageQuery::create()->findPk($medium->getId());

        self::assertNotNull($reloaded);

        return (int) $reloaded->getPosition();
    }
}
