<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Filesystem\PathResolver;

class PathResolverTest extends TestCase
{
    public function testResolvesExistingPathsToTheirCanonicalForm(): void
    {
        $this->assertSame(__FILE__, (new PathResolver())->resolve(__DIR__.'/../Filesystem/./PathResolverTest.php'));
    }

    public function testReturnsNullForMissingPaths(): void
    {
        $this->assertNull((new PathResolver())->resolve(__DIR__.'/missing'));
    }

    public function testAbsolutePathsStartWithASlash(): void
    {
        $resolver = new PathResolver();

        $this->assertTrue($resolver->isAbsolute('/src/A.php'));
        $this->assertFalse($resolver->isAbsolute('src/A.php'));
        $this->assertFalse($resolver->isAbsolute('./src/A.php'));
    }
}
