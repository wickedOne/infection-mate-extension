# CLAUDE.md

This file provides guidance when working on the MatesOfMate extension template.

## Project Overview

This package is the starter template for MatesOfMate extensions. It should model current Symfony Mate conventions, not outdated bootstrap steps.

## Current Mate Baseline

The template should stay aligned with:

- `symfony/ai-mate` `^0.13 || ^0.14 || ^1.0.0-BETA1` (as allowed in `composer.json`), developed against the released `0.13.x`
- current Mate response encoding conventions as shipped in core

Current workflow assumptions:

- projects are initialized with `vendor/bin/mate init`
- Composer install and update handle extension discovery in current Mate setups
- `vendor/bin/mate discover` refreshes discovery state and regenerates `mate/AGENT_INSTRUCTIONS.md`
- tools are invoked from the CLI with `mate tools:call <tool> --<param>=<value>`
- debugging commands include `mate debug:capabilities`, `mate debug:extensions`, and `mate tools:*`

## Common Commands

```bash
composer install
composer test
composer lint
composer fix
vendor/bin/mate debug:capabilities
vendor/bin/mate debug:extensions
```

## Package Structure

- `src/Capability/` holds the tools (`RunTool`, `EscapedTool`, `MutantTool`, `SummaryTool`, `DescribeMutatorTool`) and `ConfigResource`
- `src/Runner/`, `src/Exception/`, `src/Parser/`, `src/Cache/`, `src/Config/`, `src/Formatter/` hold the plumbing behind the capabilities
- `InfectionRunner` requests the JSON log with `--logger-json=<file>` (a temporary file in the project root, deleted afterwards) and never writes or copies the project's Infection config; it needs an Infection that ships that option (infection/infection#3735)
- `InfectionCapabilities` reads the installed Infection's `run --help --format=json` so `ArgumentResolver` validates the `options` of `infection-run` (name and value shape) against that version instead of a hardcoded list; no option is named in code except the ones the extension manages itself
- `ProcessExecutor` (the only place a process is started) and `PathResolver` (the only `realpath` caller) are the seams that keep tests free of real I/O
- `config/config.php` registers services
- `INSTRUCTIONS.md` maps user intents to the tools
- `skills/infection-mutation-testing/SKILL.md` is the extension's Agent Skill

## Skills

Skills live in `skills/<name>/SKILL.md` and are declared through `extra.ai-mate.skills`. Mate copies them into the consuming project as `mate-<name>`.

- the front matter `name` must equal the directory name, and the `description` must say when the skill applies
- prefix the name with the framework so it stays unique across installed extensions
- a skill carries judgment, not a tool list: when to reach for the tools, in which order, how to read the payload, which failure paths mean what
- follow the house shape of the Symfony Mate skills: tool list, then `## Workflow`, `## Reading`, `## Failure paths`, `## Rules`; inline commands, no fenced blocks
- check the result with `vendor/bin/mate skills:list` and `vendor/bin/mate skills:validate`

## Service Registration

All capabilities should be registered in `config/config.php`.

```php
$services = $container->services()
    ->defaults()
    ->autowire()
    ->autoconfigure();

$services->set(YourTool::class);
```

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

The template must be clean out of the box.

- `composer test` must pass, and line coverage stays at 100% (check with `vendor/bin/phpunit --coverage-text`; CI does not gate on it)
- tests do no disk or process I/O: use `mikey179/vfsstream` for files, `tests/Support/FakeProcessExecutor` and `FakePathResolver` for the seams (real reads of existing repo files and `php -r` processes are only allowed in the `ProcessExecutor` and `PathResolver` tests)
- `composer lint` must pass
- `vendor/bin/infection` must report no escaped mutants (`minMsi` and `minCoveredMsi` are 100)
- CI (`.github/workflows/ci.yml`) runs tests on PHP 8.3-8.5 with lowest and highest dependencies, plus lint and mutation tests on PHP 8.5
- docs must match the actual file layout and commands

## Authoring Guidance

When updating this template:

1. keep `README.md`, `CLAUDE.md`, `AGENTS.md`, and `INSTRUCTIONS.md` mutually consistent
2. keep examples aligned with real package conventions in this monorepo
3. prefer concrete capability examples over placeholder prose
4. prefer flexible tool shapes over near-duplicate tool variants
5. avoid documenting steps that are obsolete in current Mate workflows

## Commit Message Convention

Keep commit messages clean and free of AI attribution.
