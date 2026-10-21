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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The publication of contents and folders in the back office: the state in the folder
 * list and its filter, the publication block of the sheets and its route, the signed
 * preview link, the toggle of the list, and the dashboard warning when the scheduled
 * publication task does not run.
 */
final class ContentPublicationScreenTest extends WebIntegrationTestCase
{
    private const string BODY = '<p>A body to read.</p>';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        // The theme runs on the published core as well, which may predate the publication:
        // ContentPublicationWithoutCoreTest covers the back office there.
        if (!(new ContentPublicationSupport())->isAvailable()) {
            self::markTestSkipped('The core does not carry the publication of contents and folders.');
        }

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

    public function testTheFolderListShowsTheStateOfItsFoldersAndContents(): void
    {
        $parent = $this->factory->folder();
        $this->folderIn($parent, 'draft');
        $this->folderIn($parent, 'scheduled');
        $this->folderIn($parent, 'published');
        $this->folderIn($parent, 'unpublished');
        $this->contentIn($parent, 'draft');
        $scheduled = $this->contentIn($parent, 'scheduled');
        $this->contentIn($parent, 'published');
        $this->contentIn($parent, 'unpublished');

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        self::assertResponseIsSuccessful();

        foreach (['folders', 'folder-contents'] as $table) {
            self::assertSame(
                ['draft', 'scheduled', 'published', 'unpublished'],
                $this->rowStates($crawler, $table),
                $table.': every row carries its state.',
            );
            self::assertSame(
                ['Draft', 'Scheduled for', 'Published', 'Unpublished'],
                array_map(
                    static fn (string $label): string => str_starts_with($label, 'Scheduled for') ? 'Scheduled for' : $label,
                    $crawler->filter('[data-testid="datatable-'.$table.'-cell-publication_badge"] .badge')->each(static fn (Crawler $badge): string => trim($badge->text())),
                ),
                $table.': the badge names the state, and the date a scheduled row waits for.',
            );
        }

        $scheduledBadge = $crawler->filter('[data-testid="datatable-folder-contents-row"][data-row-id="'.$scheduled->getId().'"] .badge');
        self::assertStringContainsString('2031', $scheduledBadge->text(), 'The badge of a scheduled content carries its date.');
        self::assertStringContainsString('text-bg-info', (string) $scheduledBadge->attr('class'));
    }

    public function testThePublicationFilterNarrowsBothTablesAndTheActionsKeepIt(): void
    {
        $parent = $this->factory->folder();
        $this->folderIn($parent, 'draft');
        $this->folderIn($parent, 'published');
        $this->contentIn($parent, 'draft');
        $this->contentIn($parent, 'published');
        $this->contentIn($parent, 'published');

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId().'&publication_state=draft');
        self::assertResponseIsSuccessful();

        self::assertSame(['draft'], $this->rowStates($crawler, 'folders'));
        self::assertSame(['draft'], $this->rowStates($crawler, 'folder-contents'));
        self::assertSame('draft', $crawler->filter('[data-testid="folders-publication-filter"] option[selected]')->attr('value'));
        self::assertStringContainsString('publication_state=draft', (string) $crawler->filter('a[href*="/admin/content/toggle-online"]')->attr('href'), 'A toggle brings the user back to the filtered list.');
        self::assertStringContainsString('publication_state=draft', (string) $crawler->filter('a[href*="/admin/folders/toggle-online"]')->attr('href'));

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId().'&publication_state=published');
        self::assertSame(['published'], $this->rowStates($crawler, 'folders'));
        self::assertSame(['published', 'published'], $this->rowStates($crawler, 'folder-contents'));

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId().'&publication_state=unknown');
        self::assertCount(2, $this->rowStates($crawler, 'folders'), 'An unknown state filters nothing.');
    }

    public function testTheOverdueFilterListsTheRowsWhoseDateNothingApplied(): void
    {
        $parent = $this->factory->folder();
        $overdue = $this->contentIn($parent, 'scheduled');
        $overdue->setPublishAt(new \DateTimeImmutable('-2 hours'))->save($this->getPropelConnection());
        $this->contentIn($parent, 'scheduled');
        $this->contentIn($parent, 'published');

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId().'&publication_state=overdue');

        self::assertSame(
            [(string) $overdue->getId()],
            $crawler->filter('[data-testid="datatable-folder-contents-row"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-row-id')),
        );
        self::assertStringContainsString('text-bg-warning', (string) $crawler->filter('[data-testid="datatable-folder-contents-cell-publication_badge"] .badge')->attr('class'));
    }

    public function testANewContentAndANewFolderStartAsDrafts(): void
    {
        $parent = $this->factory->folder();

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        self::assertCount(0, $crawler->filter('[name="thelia_content_creation[visible]"]'), 'The creation form has no online checkbox.');
        self::assertCount(0, $crawler->filter('[name="thelia_folder_creation[visible]"]'));
        self::assertCount(1, $crawler->filter('[data-testid="content-create-draft-note"]'));

        $form = $crawler->filter('[data-testid="content-create-form"]')->form();
        $form['thelia_content_creation[title]'] = 'A content written as a draft';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $content = ContentQuery::create()->useContentI18nQuery()->filterByTitle('A content written as a draft')->endUse()->findOne();
        self::assertNotNull($content);
        self::assertSame(0, (int) $content->getVisible());
        self::assertNull($content->getPublishAt());
        self::assertNull($content->getUnpublishAt());

        // A request that still carries the flag, from a page of the old form, puts nothing online:
        // the form has no such field and refuses it.
        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        $values = $crawler->filter('[data-testid="content-create-form"]')->form()->getPhpValues();
        $values['thelia_content_creation']['title'] = 'A content posted online';
        $values['thelia_content_creation']['visible'] = '1';
        $this->client->request('POST', '/admin/content/create', $values);
        self::assertSame(0, ContentQuery::create()->filterByVisible(1)->useContentI18nQuery()->filterByTitle('A content posted online')->endUse()->count());

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        $form = $crawler->filter('[data-testid="folder-create-form"]')->form();
        $form['thelia_folder_creation[title]'] = 'A folder written as a draft';
        $this->client->submit($form);

        $folder = FolderQuery::create()->useFolderI18nQuery()->filterByTitle('A folder written as a draft')->endUse()->findOne();
        self::assertNotNull($folder);
        self::assertSame(0, (int) $folder->getVisible());
        self::assertNull($folder->getPublishAt());
    }

    public function testTheSheetShowsThePublicationBlockInPlaceOfTheCheckbox(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[name="thelia_content_modification[visible]"]'), 'The online checkbox is gone from the general form.');
        $block = $crawler->filter('[data-testid="content-publication"]');
        self::assertCount(1, $block);
        self::assertSame('draft', $block->attr('data-publication-state'));
        self::assertSame('Draft', trim($crawler->filter('[data-testid="content-publication-state"]')->text()));
        self::assertSame(
            ['publish', 'schedule'],
            $crawler->filter('[data-bo-publication-form-target="action"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')),
            'A draft is published or scheduled.',
        );
        self::assertSame('content-publication-form', $crawler->filter('[data-testid="content-publication-publish-at"]')->attr('form'));
        self::assertSame('schedule', $crawler->filter('[data-testid="content-publication-publish-at"]')->ancestors()->filter('[data-publication-actions]')->attr('data-publication-actions'));
        self::assertCount(1, $crawler->filter('form#content-publication-form[action$="/admin/content/publication/'.$content->getId().'"]'));
        self::assertCount(0, $crawler->filter('[data-testid="content-edit-form"] form'), 'The publication form is not nested in the general form.');

        $published = $this->contentIn($this->factory->folder(), 'published', ['unpublish_at' => '+30 days']);
        $crawler = $this->client->request('GET', '/admin/content/update/'.$published->getId());
        self::assertSame(
            ['unpublish', 'schedule_unpublication', 'cancel'],
            $crawler->filter('[data-bo-publication-form-target="action"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')),
            'A published content with a withdrawal date is unpublished, its withdrawal moved or cancelled.',
        );
        self::assertStringStartsWith('Published until', trim($crawler->filter('[data-testid="content-publication-state"]')->text()));
    }

    public function testAContentIsPublishedThroughTheBlock(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'publish';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/content/update/'.$content->getId());
        $content = $this->reloaded($content);
        self::assertSame(1, (int) $content->getVisible());
        self::assertNotNull($content->getPublishAt());

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-success"]'));
        self::assertSame('published', $crawler->filter('[data-testid="content-publication"]')->attr('data-publication-state'));
    }

    public function testAContentIsScheduledThenUnpublishedThroughTheBlock(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'schedule';
        $form['thelia_content_publication[publish_at]'] = '2032-03-01 09:30';
        $form['thelia_content_publication[unpublish_at]'] = '2032-04-01 18:00';
        $this->client->submit($form);

        $content = $this->reloaded($content);
        self::assertSame(0, (int) $content->getVisible());
        self::assertSame('2032-03-01 09:30', $content->getPublishAt('Y-m-d H:i'));
        self::assertSame('2032-04-01 18:00', $content->getUnpublishAt('Y-m-d H:i'));

        $published = $this->contentIn($this->factory->folder(), 'published');
        $crawler = $this->client->request('GET', '/admin/content/update/'.$published->getId());
        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'unpublish';
        $this->client->submit($form);

        $published = $this->reloaded($published);
        self::assertSame(0, (int) $published->getVisible());
        self::assertNotNull($published->getUnpublishAt());
    }

    public function testAFolderIsScheduledThroughItsBlock(): void
    {
        $folder = $this->folderIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/folders/update/'.$folder->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name="thelia_folder_modification[visible]"]'));
        self::assertNull($crawler->filter('[data-testid="folder-publication"]')->filter('[data-testid="folder-publication-empty-body"]')->getNode(0), 'A folder has no body guard.');

        $form = $crawler->filter('[data-testid="folder-publication-submit"]')->form();
        $form['thelia_folder_publication[action]'] = 'schedule';
        $form['thelia_folder_publication[publish_at]'] = '2032-05-01 08:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/folders/update/'.$folder->getId());
        $folder = FolderQuery::create()->findPk($folder->getId());
        self::assertNotNull($folder);
        $folder->reload();
        self::assertSame(0, (int) $folder->getVisible());
        self::assertSame('2032-05-01 08:00', $folder->getPublishAt('Y-m-d H:i'));
    }

    public function testAContentWithNoBodyIsRefusedAndLeftAsItWas(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft', ['body' => '<p>&nbsp;</p>']);

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        self::assertStringContainsString('cannot be published', $crawler->filter('[data-testid="content-publication-empty-body"]')->text(), 'The sheet warns before the gesture.');

        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'publish';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/content/update/'.$content->getId());
        $content = $this->reloaded($content);
        self::assertSame(0, (int) $content->getVisible());
        self::assertNull($content->getPublishAt(), 'Nothing was written.');

        $crawler = $this->client->followRedirect();
        $flash = $crawler->filter('[data-testid="bo-flash-danger"]');
        self::assertCount(1, $flash);
        self::assertStringContainsString('default language', $flash->text());
        self::assertStringNotContainsString('&nbsp;', $flash->html(), 'The message never quotes the body.');
    }

    public function testTheSheetPointsOutTheOtherLanguagesWithAnEmptyBodyWithoutBlocking(): void
    {
        $other = LangQuery::create()->filterByByDefault(0)->orderByPosition()->findOne();
        self::assertNotNull($other, 'The test shop has a second language.');
        $other->setActive(true)->save($this->getPropelConnection());
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());

        $mention = $crawler->filter('[data-testid="content-publication-empty-body"]');
        self::assertCount(1, $mention);
        self::assertStringContainsString((string) $other->getTitle(), $mention->text());
        self::assertStringNotContainsString('cannot be published', $mention->text(), 'Another language does not stop the publication.');
        self::assertStringContainsString('alert-secondary', (string) $mention->attr('class'));
    }

    public function testDatesInTheWrongOrderAreRefused(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'schedule';
        $form['thelia_content_publication[publish_at]'] = '2032-03-01 09:30';
        $form['thelia_content_publication[unpublish_at]'] = '2032-02-01 09:30';
        $this->client->submit($form);

        self::assertNull($this->reloaded($content)->getPublishAt());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('withdrawal date must come after', $crawler->filter('[data-testid="bo-flash-danger"]')->text());
    }

    public function testAGestureTheStateDoesNotTakeIsRefused(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $token = (string) $crawler->filter('#content-publication-form input[name="thelia_content_publication[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/content/publication/'.$content->getId(), ['thelia_content_publication' => ['action' => 'unpublish', '_token' => $token]]);
        self::assertResponseRedirects('/admin/content/update/'.$content->getId());
        $content = $this->reloaded($content);
        self::assertNull($content->getUnpublishAt(), 'A draft is not unpublished.');

        $this->client->request('POST', '/admin/content/publication/'.$content->getId(), ['thelia_content_publication' => ['action' => 'publish', '_token' => 'forged']]);
        self::assertSame(0, (int) $this->reloaded($content)->getVisible(), 'A forged token publishes nothing.');
    }

    public function testThePublicationRouteAsksForTheRightToUpdate(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');
        $reader = $this->factory->restrictedAdmin([AdminResources::CONTENT => [AccessManager::VIEW], AdminResources::FOLDER => [AccessManager::VIEW]]);
        $reader->eraseCredentials();
        $this->injector?->setAdmin($reader);

        $this->client->request('POST', '/admin/content/publication/'.$content->getId(), ['thelia_content_publication' => ['action' => 'publish']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->reloaded($content)->getVisible());
    }

    public function testSavingTheGeneralFormKeepsTheState(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'published');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());
        $this->client->submit($crawler->filter('[data-testid="content-edit-top-save-stay"]')->form());

        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->reloaded($content)->getVisible(), 'Saving the sheet of a published content leaves it online.');

        $folder = $this->folderIn($this->factory->folder(), 'published');
        $crawler = $this->client->request('GET', '/admin/folders/update/'.$folder->getId());
        $this->client->submit($crawler->filter('[data-testid="folder-edit-top-save-stay"]')->form());

        $folder = FolderQuery::create()->findPk($folder->getId());
        self::assertNotNull($folder);
        $folder->reload();
        self::assertSame(1, (int) $folder->getVisible(), 'Saving the sheet of a published folder leaves it online.');
    }

    public function testTheToggleOfTheListPublishesThroughTheBodyGuard(): void
    {
        $parent = $this->factory->folder();
        $empty = $this->contentIn($parent, 'draft', ['body' => '']);
        $published = $this->contentIn($parent, 'published');

        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        $this->postToggle($crawler, $empty);
        self::assertSame(0, (int) $this->reloaded($empty)->getVisible(), 'An empty content stays offline.');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('default language', $crawler->filter('[data-testid="bo-flash-danger"]')->text());

        $this->postToggle($crawler, $published);
        $published = $this->reloaded($published);
        self::assertSame(0, (int) $published->getVisible());
        self::assertNotNull($published->getUnpublishAt(), 'Toggled off, the content reads as unpublished, not as scheduled with a date passed.');

        $folder = $this->folderIn($parent, 'published');
        $crawler = $this->client->request('GET', '/admin/folders?folder_id='.$parent->getId());
        $toggle = $crawler->filter('[data-testid="datatable-folders-row"][data-row-id="'.$folder->getId().'"] a.bo-toggle');
        $this->client->request('POST', (string) $toggle->attr('href'), ['_token' => $crawler->filter('meta[name="bo-token"]')->attr('content')]);
        $folder = FolderQuery::create()->findPk($folder->getId());
        self::assertNotNull($folder);
        $folder->reload();
        self::assertSame(0, (int) $folder->getVisible());
        self::assertNotNull($folder->getUnpublishAt(), 'Toggled off, the folder reads as unpublished too.');
    }

    public function testThePreviewButtonCarriesASignedLinkToCopy(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');

        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId());

        $href = (string) $crawler->filter('[data-testid="content-preview-btn"]')->attr('href');
        self::assertMatchesRegularExpression('#^https?://[^/]+/preview/content/'.$content->getId().'\?.*_expiration=\d+.*_hash=#', $href);
        self::assertSame('_blank', $crawler->filter('[data-testid="content-preview-btn"]')->attr('target'));
        self::assertSame($href, trim($crawler->filter('[data-bo-copy-to-clipboard-target="source"]')->text()), 'The copy button hands out the same link.');
        self::assertCount(1, $crawler->filter('[data-testid="content-preview-copy"]'));
        self::assertStringContainsString((new \DateTimeImmutable('+7 days'))->format('Y'), $crawler->filter('[data-testid="content-preview-expiry"]')->text());

        $folder = $this->folderIn($this->factory->folder(), 'draft');
        $crawler = $this->client->request('GET', '/admin/folders/update/'.$folder->getId());
        self::assertStringContainsString('/preview/folder/'.$folder->getId().'?', (string) $crawler->filter('[data-testid="folder-preview-btn"]')->attr('href'));
    }

    public function testThePreviewLinkOpensThePageInTheLanguageBeingEdited(): void
    {
        $content = $this->contentIn($this->factory->folder(), 'draft');
        $folder = $this->folderIn($this->factory->folder(), 'draft');

        foreach (['en_US', 'fr_FR'] as $locale) {
            $lang = LangQuery::create()->findOneByLocale($locale);
            self::assertNotNull($lang);

            $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId().'?edit_language_id='.$lang->getId());
            self::assertStringContainsString('locale='.$locale, (string) $crawler->filter('[data-testid="content-preview-btn"]')->attr('href'), 'Content edited in '.$locale.'.');

            $crawler = $this->client->request('GET', '/admin/folders/update/'.$folder->getId().'?edit_language_id='.$lang->getId());
            self::assertStringContainsString('locale='.$locale, (string) $crawler->filter('[data-testid="folder-preview-btn"]')->attr('href'), 'Folder edited in '.$locale.'.');
        }
    }

    public function testARefusedGestureIsWordedInTheLanguageOfTheAdministrator(): void
    {
        $french = LangQuery::create()->findOneByLocale('fr_FR');
        self::assertNotNull($french);
        $content = $this->contentIn($this->factory->folder(), 'draft');

        // The message comes from the catalog of the core: the theme carries no copy of it.
        $crawler = $this->client->request('GET', '/admin/content/update/'.$content->getId().'?lang='.$french->getCode());
        $form = $crawler->filter('[data-testid="content-publication-submit"]')->form();
        $form['thelia_content_publication[action]'] = 'schedule';
        $form['thelia_content_publication[publish_at]'] = '2020-01-01 10:00';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertSame('La date de publication doit être dans le futur.', trim($crawler->filter('[data-testid="bo-flash-danger"]')->text()));
        self::assertNull($this->reloaded($content)->getPublishAt());
    }

    public function testTheDashboardWarnsWhenScheduledDatesAreLeftBehind(): void
    {
        $parent = $this->factory->folder();
        $overdue = $this->contentIn($parent, 'scheduled');
        $overdue->setPublishAt(new \DateTimeImmutable('-2 days'))->save($this->getPropelConnection());
        $recent = $this->contentIn($parent, 'scheduled');
        $recent->setPublishAt(new \DateTimeImmutable('-2 hours'))->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/home');
        self::assertResponseIsSuccessful();

        $alert = $crawler->filter('[data-testid="dashboard-alert"]')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), 'content:publish-scheduled'));
        self::assertCount(1, $alert, 'The contents left behind for over a day are reported, the folders are not.');
        self::assertStringStartsWith('1 content has', trim($alert->text()), 'A date passed two hours ago is not reported yet.');
        $href = (string) $alert->attr('href');
        self::assertStringContainsString('folder_id='.$parent->getId(), $href);
        self::assertStringContainsString('publication_state=overdue', $href);
    }

    /**
     * @param array{body?: string, unpublish_at?: string} $options
     */
    private function contentIn(Folder $folder, string $state, array $options = []): Content
    {
        $content = $this->factory->content($folder, ['visible' => 'published' === $state ? 1 : 0]);
        $content->setLocale($this->defaultLocale())->setDescription($options['body'] ?? self::BODY);
        $this->applyState($content, $state, $options['unpublish_at'] ?? null);
        $content->save($this->getPropelConnection());

        return $content;
    }

    private function folderIn(Folder $parent, string $state): Folder
    {
        $folder = $this->factory->folder((int) $parent->getId(), ['visible' => 'published' === $state ? 1 : 0]);
        $this->applyState($folder, $state, null);
        $folder->save($this->getPropelConnection());

        return $folder;
    }

    private function applyState(Content|Folder $row, string $state, ?string $unpublishAt): void
    {
        match ($state) {
            'draft' => $row->setPublishAt(null)->setUnpublishAt(null),
            'scheduled' => $row->setPublishAt(new \DateTimeImmutable('2031-06-15 10:00:00'))->setUnpublishAt(null),
            'published' => $row->setPublishAt(new \DateTimeImmutable('-3 days'))->setUnpublishAt(null === $unpublishAt ? null : new \DateTimeImmutable($unpublishAt)),
            'unpublished' => $row->setPublishAt(new \DateTimeImmutable('-10 days'))->setUnpublishAt(new \DateTimeImmutable('-1 day')),
            default => throw new \InvalidArgumentException($state),
        };
    }

    /**
     * @return list<string>
     */
    private function rowStates(Crawler $crawler, string $table): array
    {
        return $crawler->filter('[data-testid="datatable-'.$table.'-row"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-publication-state'));
    }

    private function postToggle(Crawler $crawler, Content $content): void
    {
        $toggle = $crawler->filter('[data-testid="datatable-folder-contents-row"][data-row-id="'.$content->getId().'"] a.bo-toggle');
        self::assertCount(1, $toggle);
        $this->client->request('POST', (string) $toggle->attr('href'), ['_token' => $crawler->filter('meta[name="bo-token"]')->attr('content')]);
        self::assertResponseRedirects();
    }

    private function reloaded(Content $content): Content
    {
        $reloaded = ContentQuery::create()->findPk($content->getId());
        self::assertNotNull($reloaded);
        $reloaded->reload();

        return $reloaded;
    }

    private function defaultLocale(): string
    {
        return (string) LangQuery::create()->findOneByByDefault(1)?->getLocale();
    }
}
