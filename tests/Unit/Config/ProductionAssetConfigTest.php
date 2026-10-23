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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/** The theme ships precompressed and minified assets in prod, and leaves dev untouched. */
final class ProductionAssetConfigTest extends TestCase
{
    public function testProductionPrecompressesTheAssets(): void
    {
        $configs = $this->importedConfigs('prod', 'framework', 'asset_mapper.yaml');

        self::assertTrue($configs[0]['asset_mapper']['precompress']['enabled'] ?? false);
    }

    public function testDevelopmentDoesNotPrecompressTheAssets(): void
    {
        self::assertSame([], $this->importedConfigs('dev', 'framework', 'asset_mapper.yaml'));
    }

    public function testProductionMinifiesTheStylesheet(): void
    {
        $configs = $this->importedConfigs('prod', 'symfonycasts_sass', 'symfonycasts_sass.yaml');

        self::assertSame('compressed', $configs[0]['sass_options']['style'] ?? null);
    }

    public function testDevelopmentKeepsTheDefaultSassStyle(): void
    {
        self::assertSame([], $this->importedConfigs('dev', 'symfonycasts_sass', 'symfonycasts_sass.yaml'));
    }

    /**
     * Imports a theme package file the way AbstractBundle::prependExtension() does
     * (a YamlFileLoader built with the kernel environment, in prepend mode).
     *
     * @return list<array<string, mixed>>
     */
    private function importedConfigs(string $environment, string $alias, string $file): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        $loader = new YamlFileLoader($container, new FileLocator(), $environment, true);
        $loader->load(\dirname(__DIR__, 3).'/config/packages/'.$file);

        return $container->getExtensionConfig($alias);
    }
}
