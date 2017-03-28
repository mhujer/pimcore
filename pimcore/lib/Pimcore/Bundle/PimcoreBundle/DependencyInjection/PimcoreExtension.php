<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\PimcoreBundle\DependencyInjection;

use Pimcore\Loader\ImplementationLoader\ClassMapLoader;
use Pimcore\Loader\ImplementationLoader\PrefixLoader;
use Pimcore\Model\Document\Tag\Loader\PrefixLoader as DocumentTagPrefixLoader;
use Pimcore\Routing\Loader\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class PimcoreExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        // TODO use ConfigurableExtension or getExtension()??
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        // bundle manager/locator config
        $container->setParameter('pimcore.extensions.bundles.search_paths', $config['bundles']['search_paths']);
        $container->setParameter('pimcore.extensions.bundles.handle_composer', $config['bundles']['handle_composer']);

        // unauthenticated routes do not double-check for authentication
        $container->setParameter('pimcore.admin.unauthenticated_routes', $config['admin']['unauthenticated_routes']);

        $container->setParameter('pimcore.admin.translations.path', $config['admin']['translations']["path"]);

        // register pimcore config on container
        // TODO is this bad practice?
        // TODO only extract what we need as parameter?
        $container->setParameter('pimcore.config', $config);

        $this->setAnnotationRouteControllerLoader($container);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.yml');
        $loader->load('event_listeners.yml');
        $loader->load('templating.yml');
        $loader->load('profiler.yml');

        $this->configureImplementationLoaders($container, $config);
        $this->configureCache($container, $loader, $config);

        // load engine specific configuration only if engine is active
        $configuredEngines = ['twig', 'php'];

        if ($container->hasParameter('templating.engines')) {
            $engines = $container->getParameter('templating.engines');

            foreach ($engines as $engine) {
                if (in_array($engine, $configuredEngines)) {
                    $loader->load(sprintf('templating_%s.yml', $engine));
                }
            }
        }

        $this->addContextRoutes($container, $config['context']);
    }

    /**
     * Configure implementation loaders from config
     *
     * @param ContainerBuilder $container
     * @param array $config
     */
    protected function configureImplementationLoaders(ContainerBuilder $container, $config)
    {
        $services = [
            'pimcore.implementation_loader.document.tag'  => [
                'config'       => $config['documents']['tags'],
                'prefixLoader' => DocumentTagPrefixLoader::class
            ],
            'pimcore.implementation_loader.object.data'  => [
                'config'       => $config['objects']['class_definitions']['data'],
                'prefixLoader' => PrefixLoader::class
            ],
            'pimcore.implementation_loader.object.layout'  => [
                'config'       => $config['objects']['class_definitions']['layout'],
                'prefixLoader' => PrefixLoader::class
            ]
        ];

        // read config and add map/prefix loaders if configured - makes sure only needed objects are built
        // loaders are defined as private services as we don't need them outside the main type loader
        foreach ($services as $serviceId => $cfg) {
            $loaders = [];

            if ($cfg['config']['map']) {
                $classMapLoader = new Definition(ClassMapLoader::class, [$cfg['config']['map']]);
                $classMapLoader->setPublic(false);

                $classMapLoaderId = $serviceId . '.class_map_loader';
                $container->setDefinition($classMapLoaderId, $classMapLoader);

                $loaders[] = new Reference($classMapLoaderId);
            }

            if ($cfg['config']['prefixes']) {
                $prefixLoader = new Definition($cfg['prefixLoader'], [$cfg['config']['prefixes']]);
                $prefixLoader->setPublic(false);

                $prefixLoaderId = $serviceId . '.prefix_loader';
                $container->setDefinition($prefixLoaderId, $prefixLoader);

                $loaders[] = new Reference($prefixLoaderId);
            }

            $service = $container->getDefinition($serviceId);
            $service->setArguments([$loaders]);
        }
    }

    /**
     * Configure pimcore core cache
     *
     * @param ContainerBuilder $container
     * @param LoaderInterface  $loader
     * @param array            $config
     */
    protected function configureCache(ContainerBuilder $container, LoaderInterface $loader, array $config)
    {
        $coreCachePool = null;
        if (null !== $config['cache']['pool_service_id']) {
            $coreCachePool = $config['cache']['pool_service_id'];
        }

        // default lifetime
        $container->setParameter('pimcore.cache.core.default_lifetime', $config['cache']['default_lifetime']);

        $loader->load('cache.yml');

        // register doctrine cache if it is enabled
        if ($config['cache']['pools']['doctrine']['enabled']) {
            $loader->load('cache_doctrine.yml');

            // load named connection
            $connectionId = sprintf('doctrine.dbal.%s_connection', $config['cache']['pools']['doctrine']['connection']);

            $doctrinePool = $container->findDefinition('pimcore.cache.core.pool.doctrine');
            $doctrinePool->replaceArgument(0, new Reference($connectionId));

            if (null === $coreCachePool) {
                $coreCachePool = 'pimcore.cache.core.pool.doctrine';
            }
        }

        // register redis cache if it is enabled
        if ($config['cache']['pools']['redis']['enabled']) {
            $container->setParameter(
                'pimcore.cache.core.redis.connection',
                $config['cache']['pools']['redis']['connection']
            );

            $container->setParameter(
                'pimcore.cache.core.redis.options',
                $config['cache']['pools']['redis']['options']
            );

            $loader->load('cache_redis.yml');

            if (null === $coreCachePool) {
                $coreCachePool = 'pimcore.cache.core.pool.redis';
            }
        }

        // default to filesystem cache
        if (null === $coreCachePool) {
            $coreCachePool = 'pimcore.cache.core.pool.filesystem';
        }

        // set core cache pool alias
        $container->setAlias('pimcore.cache.core.pool', $coreCachePool);
    }

    /**
     * Add context specific routes to context guesser
     *
     * @param ContainerBuilder $container
     * @param array $config
     */
    protected function addContextRoutes(ContainerBuilder $container, array $config)
    {
        $guesser = $container->getDefinition('pimcore.service.request.pimcore_context_resolver');

        foreach ($config as $context => $contextConfig) {
            $guesser->addMethodCall('addContextRoutes', [$context, $contextConfig['routes']]);
        }
    }

    /**
     * Set annotation loader to our own implementation normalizing admin routes: converts the prefix
     * pimcore_pimcoreadmin_ to just pimcore_admin_
     *
     * @param ContainerBuilder $container
     */
    protected function setAnnotationRouteControllerLoader(ContainerBuilder $container)
    {
        $parameter = 'sensio_framework_extra.routing.loader.annot_class.class';

        // make sure the parameter is not dropped by sensio framework extra bundle
        // if this exception is thrown, implement the class override in a compiler pass
        if (!$container->hasParameter($parameter)) {
            throw new RuntimeException(sprintf(
                'The sensio framework extra bundle removed support for the "%s" parameter',
                $parameter
            ));
        }

        $container->setParameter($parameter, AnnotatedRouteControllerLoader::class);
    }
}
