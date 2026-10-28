<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Capability;

/**
 * temp project root with a fake vendor/bin/infection; behaviour comes from fake.json
 * (mode = log|nolog|invalid|empty|sleep, exit, stdout, stderr), arguments are dumped to argv.json.
 */
trait FakeInfectionProject
{
    private function createFakeProject(): string
    {
        $root = sys_get_temp_dir().'/infection-mate-fake-'.bin2hex(random_bytes(6));
        mkdir($root.'/vendor/bin', 0o775, true);
        mkdir($root.'/src', 0o775, true);
        file_put_contents($root.'/src/Calc.php', "<?php\n");

        $log = str_replace('/project/', $root.'/', (string) file_get_contents(__DIR__.'/../../Fixtures/infection-log.json'));
        file_put_contents($root.'/fake-log.json', $log);

        file_put_contents($root.'/vendor/bin/infection', <<<'PHP'
            <?php
            $root = dirname(__DIR__, 2);
            $args = array_slice($argv, 1);
            file_put_contents($root.'/argv.json', json_encode($args));

            $settings = json_decode((string) file_get_contents($root.'/fake.json'), true, 512, JSON_THROW_ON_ERROR) ?: [];
            $mode = $settings['mode'] ?? 'log';

            $logFile = null;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--configuration=')) {
                    $config = json_decode((string) file_get_contents($root.'/'.substr($arg, 16)), true, 512, JSON_THROW_ON_ERROR);
                    $logFile = $config['logs']['json'] ?? null;
                }
            }

            fwrite(STDOUT, $settings['stdout'] ?? '');
            fwrite(STDERR, $settings['stderr'] ?? '');

            if ('sleep' === $mode) {
                sleep(3);
            }
            if (null !== $logFile) {
                if ('log' === $mode) {
                    copy($root.'/fake-log.json', $logFile);
                } elseif ('invalid' === $mode) {
                    file_put_contents($logFile, 'not json');
                } elseif ('empty' === $mode) {
                    file_put_contents($logFile, '{}');
                }
            }

            exit($settings['exit'] ?? 0);
            PHP);

        return $root;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configureFake(string $root, array $settings): void
    {
        file_put_contents($root.'/fake.json', json_encode($settings, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string> the arguments the fake binary was started with
     */
    private function fakeArguments(string $root): array
    {
        $decoded = json_decode((string) file_get_contents($root.'/argv.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return array_map(strval(...), array_values($decoded));
    }

    private function removeFakeProject(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeFakeProject($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
