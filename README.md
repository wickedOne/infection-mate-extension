# Infection Extension for Symfony Mate

A [Symfony Mate](https://symfony.com/doc/current/ai/components/mate.html) extension that lets coding agents run [Infection](https://infection.github.io/) mutation testing and work with its results: find escaped mutants, understand why they escaped, and write tests that kill them.

It is built on data Infection already produces (its JSON log and `infection describe`). The ideas come from the "AI-assisted mutation testing" section of [infection/infection#3498](https://github.com/infection/infection/issues/3498), where the agent supplies the reasoning and this extension supplies the evidence.

## Installation

```bash
composer require --dev wickedone/infection-mate-extension
vendor/bin/mate init
```

The project needs `infection/infection` (with `--logger-json`, see below), a test framework, and a coverage driver (pcov or xdebug). Extension discovery runs on Composer install and update; `vendor/bin/mate discover` refreshes it manually.

## Capabilities

| Name                         | Kind     | Purpose                                                                                                    |
|------------------------------|----------|------------------------------------------------------------------------------------------------------------|
| `infection-run`              | tool     | Run Infection, scoped by `paths` and any `options` of the installed Infection. Returns stats and a `runId` |
| `infection-summary`          | tool     | MSI, outcome counts, escaped mutants grouped by file and mutator                                           |
| `infection-escaped`          | tool     | List mutants of a run by bucket, `file` or `mutator`, paged                                                |
| `infection-mutant`           | tool     | One mutant: diff, surrounding source lines, optional test output                                           |
| `infection-describe-mutator` | tool     | Wraps `infection describe` for a mutator                                                                   |
| `infection://config`         | resource | The project's Infection configuration                                                                      |

Agents call them through the `vendor/bin/mate` CLI:

```bash
vendor/bin/mate tools:call infection-run --paths=src/Service --options='{"only-covering-test-cases":true}'
vendor/bin/mate tools:call infection-escaped --bucket=escaped --file=src/Service/Calc.php
vendor/bin/mate tools:call infection-mutant --ref=escaped:0 --withOutput=true
vendor/bin/mate resources:read infection://config
```

The extension also ships the `mate-infection-mutation-testing` skill with the workflow, how to read each bucket, and the rules for equivalent mutants and ignore annotations.

## How it works

`infection-run` passes `--logger-json=<file>` to Infection and reads that file back, so your configuration is used as is and never copied or modified. The file is a short-lived `.infection.mate.<token>.log.json` in the project root, deleted after every run. This needs an Infection version that has the `--logger-json` option ([infection/infection#3735](https://github.com/infection/infection/pull/3735)).

Parsed runs are cached in Mate's cache directory (the last 10) so the drill-down tools never re-run Infection.

## Limitations

These follow from what Infection reports today:

- Infection's log has no mutant ids. A `ref` like `escaped:3` is valid only within one `runId`.
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
