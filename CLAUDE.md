# CLAUDE.md

This file provides guidance when working on the Infection extension for Symfony Mate (`wickedone/infection-mate-extension`).

## Overview

This package gives AI assistants mutation testing tools for Symfony Mate: run Infection, then inspect escaped mutants, single mutants, the summary and mutator descriptions without re-running. 

## Current Mate Workflow

- projects are initialized with `vendor/bin/mate init`
- Composer install and update handle extension discovery
- `vendor/bin/mate discover` refreshes discovery state and regenerates `mate/AGENT_INSTRUCTIONS.md`
- tools are invoked with `mate tools:call <tool> --<param>=<value>`, resources are read with `mate resources:read <uri>`
- debugging: `mate debug:capabilities`, `mate debug:extensions`, `mate tools:*`

## Structure

- `src/Capability/` holds the tools (`RunTool`, `EscapedTool`, `MutantTool`, `SummaryTool`, `DescribeMutatorTool`) and `ConfigResource`
- `src/Runner/` runs Infection: `InfectionRunner`, `ArgumentResolver`, `InfectionCapabilities`, `ProcessExecutor`, `RunResult`
- `src/Parser/` turns the JSON log into `MutantResult`s (`JsonLogParser`)
- `src/Cache/` stores runs by `runId` so follow-up tools never re-run (`RunCache`)
- `src/Discovery/` detects the project's Infection config (`ConfigurationDiscovery`)
- `src/Formatter/` builds the encoded output (`ResultFormatter`)
- `src/Filesystem/` holds `PathResolver`
- `config/config.php` registers services
- `INSTRUCTIONS.md` maps user intents to the tools
- `skills/infection-mutation-testing/SKILL.md` is the extension's Agent Skill

Design rules:

- `InfectionRunner` requests the JSON log with `--logger-json=<file>`
- `InfectionCapabilities` reads the installed Infection's `run --help --format=json` so `ArgumentResolver` validates the `options` of `infection-run` (name and value shape) against that version; no option is named in code except the ones the extension manages itself
- `ProcessExecutor` (the only place a process is started) and `PathResolver` (the only `realpath` caller) are the seams that keep tests free of real I/O

## Output Strategy

Tools return strings encoded with Mate's `ResponseEncoder`; resources that return encoded text use the `text/plain` MIME type. Failures are returned as an encoded error payload with a hint (see `RunTool::error()`), not thrown to the client.

## Service Registration

Register everything in `config/config.php` (not `config/services.php`). Services that need the project root take `$projectRoot` from `%mate.root_dir%`; the run cache lives in `%mate.cache_dir%/infection-runs`.

```php
$services = $container->services()
    ->defaults()
    ->autowire()
    ->autoconfigure();

$services->set(YourTool::class);
```

## Commands

```bash
composer install
composer test                          # phpunit
composer lint                          # composer validate, rector, php-cs-fixer, phpstan (dry-run)
composer fix                           # rector + php-cs-fixer
vendor/bin/phpunit --coverage-text     # line coverage must stay 100%
vendor/bin/infection                   # mutation tests, no escaped mutants allowed
vendor/bin/mate debug:capabilities
vendor/bin/mate debug:extensions
vendor/bin/mate skills:list
vendor/bin/mate skills:validate
```

## Working on a Capability

1. add or change the class under `src/Capability/`; tools use `#[MateTool(name: 'infection-<action>', ...)]`
2. write the description so the AI knows when to call the tool, and add `@param` docblocks for every parameter (Mate builds the input schema from them)
3. prefer one flexible tool with parameters over near-duplicate tool variants
4. register it in `config/config.php`
5. update `INSTRUCTIONS.md`, `skills/infection-mutation-testing/SKILL.md`, `README.md` and `CHANGELOG.md` so they match the real tool names and behavior
6. run `composer test`, `composer lint`

## Skills

Skills live in `skills/<name>/SKILL.md` and are declared through `extra.ai-mate.skills`. Mate copies them into the consuming project as `mate-<name>`.

- the front matter `name` must equal the directory name, and the `description` must say when the skill applies
- prefix the name with the framework so it stays unique across installed extensions
- a skill carries judgment, not a tool list: when to reach for the tools, in which order, how to read the payload, which failure paths mean what
- follow the house shape of the Symfony Mate skills: tool list, then `## Workflow`, `## Reading`, `## Failure paths`, `## Rules`; inline commands, no fenced blocks
- check the result with `vendor/bin/mate skills:list` and `vendor/bin/mate skills:validate`

## House Style vs Platform Capability

MatesOfMate house style:

- no `declare(strict_types=1)` in examples
- no `final` classes in examples
- file headers stay consistent with the org
- comments are lowercase and not prose: assume the reader understands the code and only explain what is not obvious

Current Mate platform capability:

- tools may return strings, arrays, or scalars
- encoded string payloads should use Mate's built-in `ResponseEncoder`
- encoder-backed resources should use `text/plain` MIME types

## Testing Expectations

- `composer test` must pass, and line coverage stays at 100% (CI does not gate on it)
- tests do no disk or process I/O: use `mikey179/vfsstream` for files, `tests/Support/FakeProcessExecutor` and `FakePathResolver` for the seams (real reads of existing repo files and `php -r` processes are only allowed in the `ProcessExecutor` and `PathResolver` tests)
- `tests/Fixtures/infection-log.json` is the sample Infection JSON log for parser and formatter tests
- `composer lint` must pass (phpstan runs at level 8 over `src` and `tests`)
- `vendor/bin/infection` must report no escaped mutants (`minMsi` and `minCoveredMsi` are 100, static analysis through phpstan is enabled)
- CI (`.github/workflows/ci.yml`) runs tests on PHP 8.3-8.5 with lowest and highest dependencies, plus lint and mutation tests on PHP 8.5
- docs must match the actual file layout and commands

## Authoring Guidance

1. keep `README.md`, `CLAUDE.md`, `AGENTS.md`, and `INSTRUCTIONS.md` mutually consistent
2. keep examples aligned with real package conventions in this monorepo
3. prefer concrete capability examples over placeholder prose
4. prefer flexible tool shapes over near-duplicate tool variants
5. avoid documenting steps that are obsolete in current Mate workflows

## Commit Message Convention

Keep commit messages clean and free of AI attribution.
