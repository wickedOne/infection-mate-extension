<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionExtension\Parser;

use WickedOne\InfectionExtension\Enum\BucketEnum;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class MutantResult implements \JsonSerializable
{
    public const array KEYS = ['bucket', 'id', 'mutator', 'file', 'line', 'diff', 'processOutput'];

    public function __construct(
        public readonly BucketEnum $bucket,
        public readonly string $id,
        public readonly string $mutator,
        public readonly string $file,
        public readonly int $line,
        public readonly string $diff,
        public readonly string $processOutput,
    ) {
    }

    /**
     * whether a changed line of the diff holds an array item (a key => value pair), arrow functions not counted.
     */
    public function touchesArrayItem(): bool
    {
        foreach (explode("\n", $this->diff) as $line) {
            // `fn ($x) => true` has an arrow too, but no array item
            $line = preg_replace('/\bfn\s*\([^)]*\)(?:\s*:\s*[?\w\\\\|&]+)?\s*=>/', '', $line) ?? $line;

            if (1 === preg_match('/^[+-].*=>/', $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'bucket' => $this->bucket->value,
            'id' => $this->id,
            'mutator' => $this->mutator,
            'file' => $this->file,
            'line' => $this->line,
            'diff' => $this->diff,
            'processOutput' => $this->processOutput,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \ValueError when the bucket is not one of BucketEnum
     */
    public static function fromArray(array $data): self
    {
        return new self(
            BucketEnum::from((string) $data['bucket']),
            (string) $data['id'],
            (string) $data['mutator'],
            (string) $data['file'],
            (int) $data['line'],
            (string) $data['diff'],
            (string) $data['processOutput'],
        );
    }
}
