# Infection Extension for Symfony Mate

[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FwickedOne%2Finfection-mate-extension%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/wickedOne/infection-mate-extension/main)
[![codecov](https://codecov.io/gh/wickedOne/infection-mate-extension/branch/main/graph/badge.svg?token=UHKAVGURP7)](https://codecov.io/gh/wickedOne/infection-mate-extension)
[![Latest Stable Version](https://poser.pugx.org/wickedone/infection-mate-extension/v)](https://packagist.org/packages/wickedone/infection-mate-extension)
[![Total Downloads](https://poser.pugx.org/wickedone/infection-mate-extension/downloads)](https://packagist.org/packages/wickedone/infection-mate-extension)
[![License](https://poser.pugx.org/wickedone/infection-mate-extension/license)](https://packagist.org/packages/wickedone/infection-mate-extension)
[![PHP Version Require](https://poser.pugx.org/wickedone/infection-mate-extension/require/php)](https://packagist.org/packages/wickedone/infection-mate-extension)

A [Symfony Mate](https://symfony.com/doc/current/ai/components/mate.html) extension that lets coding agents run [Infection](https://infection.github.io/) mutation testing and work with its results: find escaped mutants, understand why they escaped, and write tests that kill them.

It is built on data Infection already produces (its JSON log). The ideas come from the "AI-assisted mutation testing" section of [infection/infection#3498](https://github.com/infection/infection/issues/3498), where the agent supplies the reasoning and this extension supplies the evidence.

## Installation

```bash
composer require --dev wickedone/infection-mate-extension
vendor/bin/mate init
```

The project needs `infection/infection` (with `--logger-json`, see below), a test framework, and a coverage driver (pcov or xdebug). Extension discovery runs on Composer install and update; `vendor/bin/mate discover` refreshes it manually.

## Capabilities

| Name                         | Kind     | Purpose                                                                                                                                                                            |
|------------------------------|----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `infection-run`              | tool     | Run Infection, scoped by `paths` and any `options` of the installed Infection, or limited to specific mutants with the `id` option (marked `partial`). Returns stats and a `runId` |
| `infection-summary`          | tool     | MSI, outcome counts, escaped mutants grouped by file and mutator                                                                                                                   |
| `infection-escaped`          | tool     | List mutants of a run by bucket, `file` or `mutator`, paged (20 by default, at most 100); `idsOnly` lists just the ids, and mutants of often-equivalent mutators carry a `note`; a `hints` map lists how to kill the others, once per mutator; with an `id` it returns that one mutant instead (diff, surrounding source lines, optional test output with `withOutput`, and the note or kill hint)    |
| `infection://config`         | resource | The project's Infection configuration                                                                                                                                              |

Agents call them through the `vendor/bin/mate` CLI:

```bash
vendor/bin/mate tools:call infection-run --json='{"paths":["src/Service"],"options":{"only-covering-test-cases":true}}'
vendor/bin/mate tools:call infection-run --json='{"options":{"id":["<id1>","<id2>"]}}'
vendor/bin/mate tools:call infection-summary --runId=<runId>
vendor/bin/mate tools:call infection-escaped --file=src/Service/Calc.php
vendor/bin/mate tools:call infection-escaped --id=<mutant id> --runId=<runId> --withOutput=true
vendor/bin/mate resources:read infection://config
```

The extension also ships the `mate-infection-mutation-testing` skill with the workflow, how to read each bucket, and the rules for equivalent mutants and ignore annotations.

## Output format

Responses are JSON. When [`helgesverre/toon`](https://github.com/HelgeSverre/toon-php) is installed in the project (`composer require --dev helgesverre/toon`), Mate encodes them as the more token-efficient TOON format instead. There is no setting: uninstall the package to get JSON back.

## How it works

`infection-run` passes `--logger-json=<file>` to Infection and reads that file back, so your configuration is used as is and never copied or modified. The file is a short-lived `.infection.mate.<token>.log.json` in the project root, deleted after every run.

Parsed runs are cached in Mate's cache directory (the last 10, plus the last 10 partial runs kept apart) so the drill-down tools never re-run Infection. The output of a failed run and the test output of an inspected mutant is returned as an excerpt (cleaned of progress bars and padding, cut in the middle above 8,000 bytes); the full output is kept in files next to the cache (the newest 20) and named in `outputFile` or `processOutputFile`.

## Limitations

These follow from what Infection reports today:

- A mutant `id` is looked up in one cached `runId`. To re-run specific mutants, pass their ids to `infection-run` as the `id` option (`--json='{"options":{"id":["<id1>","<id2>"]}}'`, array and object parameters need `--json`); such a run is marked `partial`, its stats cover just those mutants (a run scoped with `paths` or an option such as `git-diff-lines` is not marked, `partial` only means limited to mutant ids); Infection invalidates an id when the mutated source changes.
- There is no per-mutant reason for ignored, skipped or uncovered mutants, only the bucket.
- No history across Infection runs beyond the last 10 cached ones, so mutator effectiveness over time is not available.
- Equivalent-mutant triage is left to the agent. Nothing is suppressed automatically.

Output that originates from the inspected project (diffs, source, test output) is returned inside an `untrusted_data` envelope.

## Development

```bash
composer install
composer test
composer lint
composer fix
vendor/bin/mate debug:capabilities
vendor/bin/mate skills:validate
```

Capabilities live in `src/Capability/` and are registered in `config/config.php`. See `CLAUDE.md` and `AGENTS.md` for conventions.

## Resources

- [Symfony Mate docs](https://symfony.com/doc/current/ai/components/mate.html)
- [Creating Mate extensions](https://symfony.com/doc/current/ai/components/mate/creating-extensions.html)
- [MatesOfMate contributing guide](https://github.com/matesofmate/.github/blob/main/CONTRIBUTING.md)
- [Infection documentation](https://infection.github.io/guide/)

---

*"Because every Mate needs Mates"*
