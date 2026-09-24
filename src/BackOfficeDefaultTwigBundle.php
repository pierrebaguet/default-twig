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

namespace BackOfficeDefaultTwigBundle;

use BackOfficeDefaultTwigBundle\DependencyInjection\Compiler\BackOfficeTwigOnlyCompilerPass;
use BackOfficeDefaultTwigBundle\Hook\Attribute\AsHook;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\SearchLogReader;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\SearchLogReaderFactory;
use BackOfficeDefaultTwigBundle\Service\Report\SearchLog\TntSearchSchemaProbe;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class BackOfficeDefaultTwigBundle extends AbstractBundle
{
    public const ACTIVE_TEMPLATE_NAME = 'default-twig';

    private const ADMIN_TEMPLATE_PARAMETER = 'thelia_admin_template';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(
            new BackOfficeTwigOnlyCompilerPass(self::ACTIVE_TEMPLATE_NAME),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );

        $container->registerAttributeForAutoconfiguration(
            AsHook::class,
            static function (ChildDefinition $definition, AsHook $attribute, \Reflector $reflector): void {
                \assert($reflector instanceof \ReflectionMethod);
                $tag = [
                    'event' => $attribute->event,
                    'type' => $attribute->type,
                    'method' => $reflector->getName(),
                ];

                if (null !== $attribute->priority) {
                    $tag['priority'] = $attribute->priority;
                }

                $definition->addTag('hook.event_listener', $tag);
            },
        );
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isActive($builder)) {
            return;
        }

        $resourcePath = $this->getResourcePath();

        $container->services()
            ->load('BackOfficeDefaultTwigBundle\\', $resourcePath)
            ->exclude([
                $resourcePath.'/BackOfficeDefaultTwigBundle.php',
                $resourcePath.'/DTO/',
                $resourcePath.'/Hook/Attribute/',
                $resourcePath.'/DependencyInjection/',
                $resourcePath.'/Form/Legacy/MirroredLegacyForm.php',
            ])
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->alias(\Thelia\Core\Template\ParserHelperInterface::class, 'thelia.parser.helper');

        $container->services()
            ->alias(\Thelia\Core\Security\Resource\AdminResources::class, 'thelia.admin.resources');

        // The search log belongs to the optional TntSearch module: the reader is
        // chosen at runtime from the module state and the schema, never by type.
        $container->services()
            ->set(TntSearchSchemaProbe::class)
            ->args([inline_service(ConnectionInterface::class)->factory([Propel::class, 'getConnection'])]);

        $container->services()
            ->set(SearchLogReader::class)
            ->factory([service(SearchLogReaderFactory::class), 'create']);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isActive($builder)) {
            return;
        }

        $container->extension('twig', [
            'paths' => [
                $this->getViewsPath() => 'BackOfficeDefaultTwig',
            ],
        ]);

        $translationsPath = $this->getViewsPath().'/translations';
        if (is_dir($translationsPath)) {
            $container->extension('framework', [
                'translator' => [
                    'paths' => [$translationsPath],
                ],
            ]);
        }

        $packagesPath = $this->getConfigPath().'/packages';
        if (is_dir($packagesPath)) {
            $container->import($packagesPath.'/*.yaml');
        }

        $this->prependConfigAssetMapper($builder);
        $this->prependConfigSass($builder);
    }

    /**
     * Registers the theme assets under the "backoffice" namespace of the single
     * application asset map. Namespacing keeps the logical paths disjoint from the
     * front-office theme, which maps its own directories without a namespace; the
     * application-wide settings (importmap_path, vendor_dir, public_prefix) are
     * deliberately left untouched so the two themes cannot fight over them.
     */
    private function prependConfigAssetMapper(ContainerBuilder $builder): void
    {
        if (!$this->isAssetMapperAvailable($builder)) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    \dirname(__DIR__).'/assets' => 'backoffice',
                    // Bootstrap's own JavaScript, taken from the twbs/bootstrap Composer
                    // package so it always matches the Sass sources compiled below.
                    '%kernel.project_dir%/vendor/twbs/bootstrap/dist/js' => 'backoffice-bootstrap',
                    // The icon font referenced by the compiled stylesheet.
                    '%kernel.project_dir%/vendor/twbs/bootstrap-icons/font/fonts' => 'backoffice-fonts',
                ],
                // Same exclusion the sass-bundle recipe ships: Sass partials are
                // compile-time inputs, they must not be served as assets.
                'excluded_patterns' => [
                    '*/assets/styles/**/_*.scss',
                ],
            ],
        ]);
    }

    /**
     * Points symfonycasts/sass-bundle at the theme stylesheet. The bundle compiles
     * it with a standalone dart-sass binary (bin/console sass:build) and swaps the
     * compiled CSS in when AssetMapper serves the .scss logical path.
     */
    private function prependConfigSass(ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('symfonycasts_sass')) {
            return;
        }

        $builder->prependExtensionConfig('symfonycasts_sass', [
            'root_sass' => [
                \dirname(__DIR__).'/assets/styles/main.scss',
            ],
            'sass_options' => [
                // Resolves @import "bootstrap/..." and "bootstrap-icons/..." from the
                // Composer packages instead of a node_modules directory.
                'load_path' => [
                    '%kernel.project_dir%/vendor/twbs',
                ],
                'quiet_deps' => true,
            ],
        ]);
    }

    private function isAssetMapperAvailable(ContainerBuilder $builder): bool
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return false;
        }

        $bundlesMetadata = $builder->getParameter('kernel.bundles_metadata');
        if (!\is_array($bundlesMetadata) || !isset($bundlesMetadata['FrameworkBundle'])) {
            return false;
        }

        return is_file($bundlesMetadata['FrameworkBundle']['path'].'/Resources/config/asset_mapper.php');
    }

    private function isActive(ContainerBuilder $builder): bool
    {
        if (!$builder->hasParameter(self::ADMIN_TEMPLATE_PARAMETER)) {
            return false;
        }

        return self::ACTIVE_TEMPLATE_NAME === $builder->getParameter(self::ADMIN_TEMPLATE_PARAMETER);
    }

    private function getResourcePath(): string
    {
        return __DIR__;
    }

    private function getConfigPath(): string
    {
        return \dirname(__DIR__).'/config';
    }

    private function getViewsPath(): string
    {
        // Templates live at the bundle root so the Thelia ParserResolver picks them up
        // automatically (`templates/backOffice/<active>/<name>.html.twig`).
        return \dirname(__DIR__);
    }
}
