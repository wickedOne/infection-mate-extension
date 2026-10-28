# AGENTS.md

Guidelines for AI agents helping users customize this extension template.

## Agent Role

You are helping developers turn this template into a real Symfony Mate extension.

## Responsibilities

- Replace all `Example` and `ExampleExtension` placeholders
- Keep docs aligned with the actual file layout
- Register capabilities in `config/config.php`
- Keep examples consistent with MatesOfMate house style
- Explain the current Mate workflow: `mate init`, automatic discovery, generated agent instructions, and the `tools:call` CLI

## Template Standards

- Do not add `declare(strict_types=1)` to examples.
- Do not make example classes `final`.
- Use `\JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT` in JSON examples.
- Keep file headers consistent with the repo.

## Capability Guidance

When creating tools:

- Use a clear `#[MateTool]` name in `{framework}-{action}` form.
- Prefer a flexible tool with parameters over several narrowly scoped tool names when the underlying action is the same.
- Write the description so the AI knows when to call the tool.
- Add `@param` docblocks for every parameter; Mate builds the input schema from the types and those descriptions.
- Register the class in `config/config.php`.
- Prefer JSON string output for stable MatesOfMate-style structured responses.
- Remember that current Mate also supports array and scalar returns.

When creating resources:

- Use a framework-specific URI scheme.
- Return `uri`, `mimeType`, and `text`.
- Register the class in `config/config.php`.
- Keep MIME types aligned with the actual encoding strategy.

When creating skills:

- Put each skill in `skills/<name>/SKILL.md` and declare the directory in `extra.ai-mate.skills`.
- Make the front matter `name` equal the directory name, and prefix it with the framework (`phpunit-test-run`, not `test-run`); Mate installs it as `mate-<name>`.
- Write a `description` that names the situation the skill applies to; it is the only thing an agent reads before opening the skill.
- Cover what the tool schema cannot: the order to call things in, how to read the payload, which values look like errors but are not, and which calls write.
- Keep one skill per coherent workflow, and split a read-only diagnosis from a write operation.
- Follow the house shape of the Symfony Mate skills: tool list, then `## Workflow`, `## Reading`, `## Failure paths`, `## Rules`; commands inline in backticks, no fenced blocks.
- Verify with `vendor/bin/mate skills:list` and `vendor/bin/mate skills:validate`.

## Workflow Guidance

When helping users:

1. update package name, namespace, CODEOWNERS, and license placeholders
2. replace example tool and resource names, URIs, and descriptions
3. update README and `INSTRUCTIONS.md` with framework-specific guidance
4. replace `skills/example-workflow/` with skills for the framework
5. run `composer test`
6. run `composer lint`

## Current Mate Notes

- `vendor/bin/mate init` prepares project-local Mate files
- current Mate workflows auto-discover extensions after Composer install and update
- `vendor/bin/mate discover` refreshes discovery and regenerates `mate/AGENT_INSTRUCTIONS.md`
- run tools with `mate tools:call <tool> --<param>=<value>` and read resources with `mate resources:read <uri>`
- use `mate debug:capabilities` and `mate debug:extensions` when capabilities do not show up

## Commit Messages

Never include AI attribution in commit messages. Focus on conceptual changes and outcomes.
