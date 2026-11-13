<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WickedOne\InfectionExtension\Cache\RunCache;
use WickedOne\InfectionExtension\Capability\ConfigResource;
use WickedOne\InfectionExtension\Capability\DescribeMutatorTool;
use WickedOne\InfectionExtension\Capability\EscapedTool;
use WickedOne\InfectionExtension\Capability\MutantTool;
use WickedOne\InfectionExtension\Capability\RunTool;
use WickedOne\InfectionExtension\Capability\SummaryTool;
use WickedOne\InfectionExtension\Discovery\ConfigurationDiscovery;
use WickedOne\InfectionExtension\Filesystem\PathResolver;
use WickedOne\InfectionExtension\Formatter\ResultFormatter;
use WickedOne\InfectionExtension\Parser\JsonLogParser;
use WickedOne\InfectionExtension\Runner\Argument\ArgumentResolver;
use WickedOne\InfectionExtension\Runner\Argument\InfectionCapabilities;
use WickedOne\InfectionExtension\Runner\InfectionRunner;
use WickedOne\InfectionExtension\Runner\ProcessExecutor;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(PathResolver::class);
    $services->set(ProcessExecutor::class);
    $services->set(ConfigurationDiscovery::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(InfectionCapabilities::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(ArgumentResolver::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(InfectionRunner::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(JsonLogParser::class);
    $services->set(ResultFormatter::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(RunCache::class)
        ->arg('$directory', '%mate.cache_dir%/infection-runs');

    $services->set(RunTool::class)
        ->arg('$projectRoot', '%mate.root_dir%');
    $services->set(EscapedTool::class);
    $services->set(MutantTool::class);
    $services->set(SummaryTool::class);
    $services->set(DescribeMutatorTool::class);
    $services->set(ConfigResource::class);
};
