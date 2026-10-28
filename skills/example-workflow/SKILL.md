---
name: example-workflow
description: Verify that the example extension is wired into Mate, using example-hello and the example://config resource. Use when checking that a freshly installed extension is discovered and callable. Replace this skill with one that describes your own framework workflow before publishing.
---

# Example workflow

This is the template skill, shipped so a new extension has a working skill from the first commit. Rewrite it for your framework before publishing; delete it only if the extension genuinely needs no skill.

- `example-hello` (opt `name`): returns a greeting, so a round trip through Mate can be confirmed.
- `example://config`: static reference data.

These commands accept `--format`: `json` to parse the result, `toon` (when `helgesverre/toon` is installed) for the smallest context footprint.

## Workflow

1. `vendor/bin/mate tools:call example-hello --name=Mate`. A successful call proves four things at once: the package is installed, discovery found `src/Capability`, the container loaded `config/config.php`, and the response encoder works.
2. `vendor/bin/mate resources:read example://config` for the resource side.
3. When either fails, run `vendor/bin/mate debug:extensions` before looking at the capability itself.

## Writing the real skill

A skill is judgment, not a tool list. Mate already shows the agent every tool name, description, and parameter schema, so repeating those adds nothing. Write down what the schema cannot say:

- **When to reach for these tools at all**, and which other skill covers the neighbouring case. The front matter `description` is all an agent reads before deciding, so name the situation there, not just the subject.
- **Which tool comes first**, and the cheap read-only call that should precede an expensive or writing one.
- **How to read the result**: which field carries the answer, which values look like errors but are not, what a mode or detail level actually changes.
- **The failure paths**: what a specific error message means, and what to do instead of retrying the same call.
- **What not to do**: writes that need confirmation, flags that belong to deployment rather than development, output that should never be pasted back wholesale.

Keep one skill per coherent workflow. Two tools answering the same question belong together; a read-only diagnosis and a write operation are usually better apart. Follow the shape of this file: tools first, then `## Workflow`, `## Reading`, `## Failure paths`, and `## Rules` only where it earns its place.

## Rules

- Name the directory exactly as the front matter `name`, and prefix the name with your framework, as in `phpunit-test-run`. Mate installs it as `mate-<name>` next to every other extension's skills.
- Declare the directory once in `composer.json`, under `extra.ai-mate.skills`, as `"skills": ["skills"]`.
- Check the result with `vendor/bin/mate skills:list` and `vendor/bin/mate skills:validate`.
