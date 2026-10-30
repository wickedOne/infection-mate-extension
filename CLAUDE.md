# CLAUDE.md

Infection extension for Symfony Mate (`wickedone/infection-mate-extension`, namespace `WickedOne\InfectionMateExtension`). It runs Infection once and lets the agent inspect the result (summary, escaped mutants, one mutant, mutator descriptions) without re-running.

## Structure

- `src/Capability/`: `RunTool` (`infection-run`), `SummaryTool` (`infection-summary`), `EscapedTool` (`infection-escaped`), `MutantTool` (`infection-mutant`), `DescribeMutatorTool` (`infection-describe-mutator`), `ConfigResource` (`infection://config`)
- `src/Runner/`: `InfectionRunner`, `ProcessExecutor`, `RunResult`; `Runner/Argument/`: `ArgumentResolver`, `InfectionCapabilities`; `Runner/Exception/InvalidArgumentException` carries the `hint` returned to the agent
- `src/Parser/`: `JsonLogParser` turns the JSON log into `MutantResult`s, one per entry in `JsonLogParser::BUCKETS`
- `src/Cache/RunCache`: parsed runs by `runId`, last 10, in `%mate.cache_dir%/infection-runs`
- `src/Discovery/ConfigurationDiscovery`: finds `infection.json5`, `infection.json` or their `.dist` variants, and the binary (`vendor/bin/infection`, else a global `infection`)
- `src/Formatter/ResultFormatter`: summary, paged listing and single-mutant payloads
- `src/Filesystem/PathResolver`
- `config/config.php` registers every service; `INSTRUCTIONS.md` maps user intents to tools; `skills/infection-mutation-testing/SKILL.md` is the Agent Skill (declared in `extra.ai-mate.skills`)

## Design rules

- `InfectionRunner` passes `--logger-json=<project root>/.infection.mate.<token>.log.json`, reads the file back and deletes it; leftovers older than a day are swept at the next run
- `InfectionCapabilities` reads `infection run --help --format=json` once per request; `ArgumentResolver` validates the names and value shapes of `options` against it, so no Infection option is named in code except the ones the extension manages (`ArgumentResolver::MANAGED` and `logger-*`)
- `paths` must be relative, exist and resolve inside the project root
- mutants have no id in Infection's log: a `ref` is `bucket:index` and only valid within one `runId`
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
