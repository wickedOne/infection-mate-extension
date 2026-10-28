# CLAUDE.md

This file provides guidance when working on the MatesOfMate extension template.

## Project Overview

This package is the starter template for MatesOfMate extensions. It should model current Symfony Mate conventions, not outdated bootstrap steps.

## Current Mate Baseline

The template should stay aligned with:

- released `symfony/ai-mate` `0.13.x`
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

- `src/Capability/ExampleTool.php` demonstrates a tool
- `src/Capability/ExampleResource.php` demonstrates a resource
- `config/config.php` registers services
- `INSTRUCTIONS.md` demonstrates concise agent guidance
- `skills/example-workflow/SKILL.md` demonstrates an Agent Skill

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

Current Mate platform capability:

- tools may return strings, arrays, or scalars
- encoded string payloads should use Mate's built-in `ResponseEncoder`
- encoder-backed resources should use `text/plain` MIME types

## Testing Expectations

The template must be clean out of the box.

- `composer test` must pass
- `composer lint` must pass
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
