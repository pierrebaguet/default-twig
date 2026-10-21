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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use BackOfficeDefaultTwigBundle\Service\Publication\ContentPublicationSupport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * On a core that predates the publication of contents and folders the back office is left
 * as it was: the online checkbox on the sheets and in the creation forms, the plain
 * preview link, no state in the lists, no filter, no dashboard warning, and publication
 * routes that answer 404.
 *
 * The test runs on a core that has the publication: the theme is handed the answer of a
 * core without it, by a support that looks for a class that does not exist, which is
 * what the published core gives the CI of the theme.
 */
final class ContentPublicationWithoutCoreTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        static::getContainer()->set(
            ContentPublicationSupport::class,
            new ContentPublicationSupport('Thelia\\Domain\\CMS\\Publication\\ClassOfACoreWithoutPublication'),
        );

        $injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($injector);
        $this->injector = $injector;
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        // Null when the case was skipped before the session was set up.
        if (null !== $this->injector) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheFolderListHasNoStateAndNoFilter(): void
    {
        $parent = $this->factory->folder();
        $this->factory->folder((int) $parent->getId());
        $this->factory->content($parent);

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId().'&publication_state=draft');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[data-testid="folders-publication-filter-form"]'));
        self::assertCount(0, $crawler->filter('[data-testid$="-cell-publication_badge"]'));
        self::assertCount(0, $crawler->filter('[data-publication-state]'));
        self::assertCount(1, $crawler->filter('[data-testid="datatable-folders-row"]'), 'The publication filter of the URL is ignored.');
        self::assertCount(1, $crawler->filter('[data-testid="datatable-folder-contents-row"]'));
        self::assertStringNotContainsString('publication_state', (string) $crawler->filter('a[href*="/admin/content/toggle-online"]')->attr('href'));
        self::assertCount(1, $crawler->filter('[name="thelia_content_creation[visible]"]'), 'The creation forms keep their online checkbox.');
        self::assertCount(1, $crawler->filter('[name="thelia_folder_creation[visible]"]'));
    }

    public function testACreationKeepsTheOnlineCheckbox(): void
    {
        $parent = $this->factory->folder();

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        $form = $crawler->filter('[data-testid="content-create-form"]')->form();
        $form['thelia_content_creation[title]'] = 'A content created online';
        $form['thelia_content_creation[visible]']->tick();
        $this->client->submit($form);

        $content = ContentQuery::create()->useContentI18nQuery()->filterByTitle('A content created online')->endUse()->findOne();
        self::assertNotNull($content);
        self::assertSame(1, (int) $content->getVisible());
    }

    public function testTheSheetKeepsItsCheckboxAndItsPlainPreviewLink(): void
    {
        $content = $this->factory->content($this->factory->folder());

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('[name="thelia_content_modification[visible]"]'));
        self::assertCount(0, $crawler->filter('[data-testid="content-publication"]'));
        self::assertCount(0, $crawler->filter('#content-publication-form'));
        self::assertCount(0, $crawler->filter('[data-testid="content-preview-copy"]'));
        self::assertStringNotContainsString('/preview/', (string) $crawler->filter('[data-testid="content-preview-btn"]')->attr('href'));

        $folder = $this->factory->folder();
        $crawler = $this->client->request('GET', '/admin/folders/update/'.$folder->getId());
        self::assertCount(1, $crawler->filter('[name="thelia_folder_modification[visible]"]'));
        self::assertCount(0, $crawler->filter('[data-testid="folder-publication"]'));
    }

    public function testTheCheckboxStillTakesAContentOffline(): void
    {
        $content = $this->factory->content($this->factory->folder());

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $form = $crawler->filter('[data-testid="content-edit-top-save-stay"]')->form();
        $form['thelia_content_modification[visible]']->untick();
        $this->client->submit($form);

        self::assertSame(0, (int) $this->reloaded($content)->getVisible());
    }

    public function testThePublicationRoutesAnswerNotFound(): void
    {
        $content = $this->factory->content($this->factory->folder(), ['visible' => 0]);

        $this->client->request('POST', '/admin/content/publication/'.$content->getId(), ['thelia_content_publication' => ['action' => 'publish']]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/folders/publication/'.$this->factory->folder()->getId(), ['thelia_folder_publication' => ['action' => 'publish']]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, (int) $this->reloaded($content)->getVisible());
    }

    public function testTheDashboardSaysNothingOfThePublication(): void
    {
        $content = $this->factory->content($this->factory->folder(), ['visible' => 0]);
        $this->leaveBehind($content);

        $crawler = $this->client->request('GET', '/admin/home');
        self::assertResponseIsSuccessful();

        self::assertStringNotContainsString('content:publish-scheduled', $crawler->filter('body')->text());
    }

    /**
     * A content scheduled two days ago that nothing published, when the core the test runs
     * on has the column: the published core has none, and nothing to report either.
     */
    private function leaveBehind(Content $content): void
    {
        if (method_exists($content, 'setPublishAt')) {
            $content->setPublishAt(new \DateTimeImmutable('-2 days'))->save($this->getPropelConnection());
        }
    }

    private function reloaded(Content $content): Content
    {
        $reloaded = ContentQuery::create()->findPk($content->getId());
        self::assertNotNull($reloaded);
        $reloaded->reload();

        return $reloaded;
    }
}
