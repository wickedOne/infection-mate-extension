<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WickedOne\InfectionMateExtension\Cache\RunCache;
use WickedOne\InfectionMateExtension\Capability\ConfigResource;
use WickedOne\InfectionMateExtension\Capability\DescribeMutatorTool;
use WickedOne\InfectionMateExtension\Capability\EscapedTool;
use WickedOne\InfectionMateExtension\Capability\MutantTool;
use WickedOne\InfectionMateExtension\Capability\RunTool;
use WickedOne\InfectionMateExtension\Capability\SummaryTool;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;
use WickedOne\InfectionMateExtension\Formatter\ResultFormatter;
use WickedOne\InfectionMateExtension\Parser\JsonLogParser;
use WickedOne\InfectionMateExtension\Runner\InfectionRunner;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(ConfigurationDetector::class)
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
