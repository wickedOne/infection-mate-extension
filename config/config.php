<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use MatesOfMate\ExampleExtension\Capability\ExampleResource;
use MatesOfMate\ExampleExtension\Capability\ExampleTool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Register your tools - automatically discovered by #[MateTool] attribute
    $services->set(ExampleTool::class);

    // Register your resources - automatically discovered by #[MateResource] attribute
    $services->set(ExampleResource::class);

    // Example with constructor dependencies:
    // $services->set(YourTool::class)
    //     ->arg('$someParameter', '%some.parameter%');
};
