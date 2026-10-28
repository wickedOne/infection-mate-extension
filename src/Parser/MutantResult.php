<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Parser;

/**
 * @author wicliff wolda <wicliff.wolda@gmail.com>
 */
class MutantResult implements \JsonSerializable
{
    public function __construct(
        public readonly string $bucket,
        public readonly int $index,
        public readonly string $mutator,
        public readonly string $file,
        public readonly int $line,
        public readonly string $diff,
        public readonly string $processOutput,
    ) {
    }

    public function ref(): string
    {
        return $this->bucket.':'.$this->index;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'bucket' => $this->bucket,
            'index' => $this->index,
            'mutator' => $this->mutator,
            'file' => $this->file,
            'line' => $this->line,
            'diff' => $this->diff,
            'processOutput' => $this->processOutput,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['bucket'],
            (int) $data['index'],
            (string) $data['mutator'],
            (string) $data['file'],
            (int) $data['line'],
            (string) $data['diff'],
            (string) $data['processOutput'],
        );
    }
}
