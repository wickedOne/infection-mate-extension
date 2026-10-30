<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Runner;

use WickedOne\InfectionMateExtension\Exception\InvalidArgument;
use WickedOne\InfectionMateExtension\Filesystem\PathResolver;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class ArgumentResolver
{
    private const array MANAGED = ['configuration', 'no-progress', 'force-progress', 'help', 'version', 'quiet', 'silent', 'verbose', 'ansi', 'no-ansi', 'no-interaction'];

    public function __construct(
        private readonly string $projectRoot,
        private readonly PathResolver $paths,
        private readonly InfectionCapabilities $capabilities,
    ) {
    }

    /**
     * @param string[]|null                $paths
     * @param array<array-key, mixed>|null $options infection run options by name without dashes
     *
     * @return list<string>
     *
     * @throws InvalidArgument
     */
    public function resolve(?array $paths, ?array $options, ?int $timeout): array
    {
        if (null !== $timeout && $timeout < 1) {
            throw new InvalidArgument('Invalid timeout.', 'Pass a number of seconds of at least 1, or omit it for the default.');
        }

        $files = [];

        foreach ($paths ?? [] as $path) {
            $files[] = $this->path($path);
        }

        $definition = $this->capabilities->definition();

        if ([] !== $files && null !== $definition && !\in_array('paths', $definition['arguments'], true)) {
            throw new InvalidArgument('The installed Infection does not accept paths.', 'Update infection/infection, or leave paths out.');
        }

        return [...$this->options($options ?? [], $definition), ...$files];
    }

    /**
     * @param array<array-key, mixed>                                                                                                            $options
     * @param array{options: array<string, array{accept_value: bool, is_value_required: bool, is_multiple: bool}>, arguments: list<string>}|null $definition
     *
     * @return list<string>
     */
    private function options(array $options, ?array $definition): array
    {
        if ([] === $options) {
            return [];
        }

        if (null === $definition) {
            throw new InvalidArgument('Could not read the options of the installed Infection.', 'Check that "infection run --help" works in the project, or leave options out.');
        }

        $known = array_filter($definition['options'], fn (string $name): bool => !$this->managed($name), \ARRAY_FILTER_USE_KEY);
        $arguments = [];

        foreach ($options as $name => $value) {
            if (!isset($known[$name])) {
                throw new InvalidArgument(\sprintf('The installed Infection does not support the option "%s".', $name), 'Use one of: '.implode(', ', array_keys($known)).'.');
            }

            array_push($arguments, ...$this->option($name, $value, $known[$name]));
        }

        return $arguments;
    }

    /**
     * @param array{accept_value: bool, is_value_required: bool, is_multiple: bool} $shape
     *
     * @return list<string>
     */
    private function option(string $name, mixed $value, array $shape): array
    {
        if (null === $value || false === $value) {
            return [];
        }

        if (true === $value) {
            if ($shape['is_value_required']) {
                throw new InvalidArgument(\sprintf('The option "%s" needs a value.', $name), 'Pass a string or number for it.');
            }

            return ['--'.$name];
        }

        if (!$shape['accept_value']) {
            throw new InvalidArgument(\sprintf('The option "%s" takes no value.', $name), 'Pass true to switch it on, or leave it out.');
        }

        $values = \is_array($value) ? $value : [$value];

        if (\is_array($value) && !$shape['is_multiple']) {
            throw new InvalidArgument(\sprintf('The option "%s" takes a single value.', $name), 'Pass one string or number.');
        }

        return array_map(fn (mixed $single): string => '--'.$name.'='.$this->value($name, $single), array_values($values));
    }

    private function value(string $name, mixed $value): string
    {
        $value = \is_int($value) || \is_float($value) ? (string) $value : $value;

        if (!\is_string($value) || '' === $value || str_starts_with($value, '-') || preg_match('/[\x00-\x1f]/', $value)) {
            throw new InvalidArgument(\sprintf('Invalid value for the option "%s".', $name), 'Pass a non-empty string or number that does not start with a dash.');
        }

        return $value;
    }

    /**
     * options the extension sets itself or that only shape the console output.
     */
    private function managed(string $name): bool
    {
        return \in_array($name, self::MANAGED, true) || str_starts_with($name, 'logger-');
    }

    private function path(string $path): string
    {
        if ('' === $path || str_starts_with($path, '-') || \in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
            throw new InvalidArgument(\sprintf('Invalid path "%s".', $path), 'Paths must be relative to the project root and stay inside it.');
        }

        $real = $this->paths->resolve($this->projectRoot.'/'.$path);
        $root = $this->paths->resolve($this->projectRoot);

        if (null !== $real && null !== $root && $real !== $root && !str_starts_with($real, $root.'/')) {
            throw new InvalidArgument(\sprintf('Path "%s" resolves outside the project.', $path), 'Paths must stay inside the project root.');
        }

        if (null === $real) {
            throw new InvalidArgument(\sprintf('Path "%s" does not exist.', $path), 'Check the path relative to the project root.');
        }

        return $path;
    }
}
