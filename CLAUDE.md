# CLAUDE.md

Infection extension for Symfony Mate (`wickedone/infection-mate-extension`, namespace `WickedOne\InfectionExtension`). It runs Infection once and lets the agent inspect the result (summary, escaped mutants, one mutant, mutator descriptions) without re-running.

## Structure

- `src/Capability/`: `RunTool` (`infection-run`), `SummaryTool` (`infection-summary`), `EscapedTool` (`infection-escaped`, lists a bucket or, with an `id`, inspects one mutant), `ConfigResource` (`infection://config`)
- `src/Runner/`: `InfectionRunner`, `ProcessExecutor`, `RunResult`; `Runner/Argument/`: `ArgumentResolver` (returns `ResolvedArguments`: the arguments plus whether the `id` option limits the run), `InfectionCapabilities`; `Runner/Exception/InvalidArgumentException` carries the `hint` returned to the agent
- `src/Parser/`: `JsonLogParser` turns the JSON log into `MutantResult`s, one per entry of each `BucketEnum` group
- `src/Cache/RunCache`: parsed runs by `runId`, last 10 regular and last 10 partial ones (id ends in `p`, never the default `runId`, unless there is no regular run), in `%mate.cache_dir%/infection-runs`
- `src/Discovery/ConfigurationDiscovery`: finds `infection.json5`, `infection.json` or their `.dist` variants, and the binary (`vendor/bin/infection`, else a global `infection`)
- `src/Formatter/ResultFormatter`: summary, paged listing and single-mutant payloads
- `src/Enum/`: `BucketEnum`, the outcome groups of the JSON log (the order of its cases is the order `JsonLogParser` reads them in); `EquivalenceNoteEnum` and `RemedyEnum`, the advice texts as backed enums, each with a `forMutant()` that picks the case for an escaped mutant (`null` when none fits); `RemedyEnum` has one case per group of mutators that mutate alike and maps every Infection mutator by name, `RemedyEnumTest` fails when `Infection\Mutator\ProfileList::ALL_MUTATORS` holds a mutator without a remedy or note (exempt ones are listed in the test)
- `src/Formatter/OutputExcerpt` cleans process output and cuts it in the middle above `LIMIT`; `src/Cache/OutputStore` keeps the full output of failed runs and inspected mutants in `%mate.cache_dir%/infection-outputs` (newest 20), so responses carry an excerpt plus the file path
- `src/Filesystem/PathResolver`
- `config/config.php` registers every service; `INSTRUCTIONS.md` maps user intents to tools; `skills/infection-mutation-testing/SKILL.md` is the Agent Skill (declared in `extra.ai-mate.skills`)

## Design rules

- `InfectionRunner` passes `--logger-json=<project root>/.infection.mate.<token>.log.json`, reads the file back and deletes it; leftovers older than a day are swept at the next run
- `InfectionCapabilities` reads `infection run --help --format=json` once per request; `ArgumentResolver` validates the names and value shapes of `options` against it, so no Infection option is named in code except the ones the extension manages (`ArgumentResolver::MANAGED` and `logger-*`), `id` and `test-framework-extra-args` (raw test framework arguments, so its value may start with a dash); it also rejects an Infection without `logger-json` before running
- `paths` must be relative, exist and resolve inside the project root
- a mutant `id` is the hash Infection logs per entry (what `--id` takes, repeatable); `infection-escaped` with an `id` looks it up within one `runId`, and re-running specific mutants goes through the repeatable `id` option in the `options` of `infection-run` (a list; `ArgumentResolver` rejects an empty one); such a run is stored with its number of selected mutants (`RunCache` `selected`) so summaries mark it `partial`
- `ProcessExecutor` is the only place a process is started and `PathResolver` the only `realpath` caller; these two seams keep everything else free of real I/O
- tools return `ResponseEncoder` strings; content from the inspected project (diffs, source, test output) goes through `encodeUntrusted`; failures are returned as `{error, hint[, output]}` payloads, never thrown

## Testing

- `vendor/bin/phpunit`; line coverage stays at 100% (`--coverage-text`, CI does not gate on it)
- no disk or process I/O in tests: `mikey179/vfsstream` for files, `tests/Support/FakeProcessExecutor` and `FakePathResolver` for the seams; only `ProcessExecutorTest` and `PathResolverTest` touch the real filesystem or run `php -r`
- `tests/Fixtures/infection-log.json` is the sample log for parser and formatter tests
- `composer lint`: composer validate, rector, php-cs-fixer and phpstan (level 8 over `src` and `tests`), all dry-run; `composer fix` applies rector and php-cs-fixer
- `composer test` runs `vendor/bin/infection`: `minMsi` and `minCoveredMsi` are 100 with phpstan as static analysis tool, so no escaped mutants
- `vendor/bin/mate skills:validate` checks the skill; CI runs phpunit on PHP 8.3-8.5 with lowest and highest deps, plus lint, skills validation and infection on PHP 8.5

## Conventions

- no `declare(strict_types=1)`, no `final`; code comments are lowercase and not prose, only for what is not obvious
- when a tool changes, update `INSTRUCTIONS.md`, the skill, `README.md` and `CHANGELOG.md` to the real names and behaviour
- commit messages stay clean, without AI attribution

## Resources

Generic Mate guidance (project workflow, tool/resource attributes, `ResponseEncoder`, service registration, skills) is not repeated here:

- [Symfony Mate docs](https://symfony.com/doc/current/ai/components/mate.html)
- `vendor/symfony/ai-mate/README.md` and `vendor/symfony/ai-mate/INSTRUCTIONS.md`
- `vendor/bin/mate debug:capabilities`, `debug:extensions`, `skills:list`
