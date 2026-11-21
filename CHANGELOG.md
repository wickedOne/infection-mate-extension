CHANGELOG
=========

0.2.0
-----

 * the `test-framework-extra-args` option of `infection-run` accepts values that start with a dash (`--filter=Foo|Bar`), as they are raw arguments for the test framework; every other option still rejects them
 * the skill suggests scoping the initial test run in the test runner with the `test-framework-extra-args` option, with the PHPUnit `--filter=Foo|Bar` form for the class names of a git diff, and notes that `uncovered` then means not covered by the filtered tests
 * partial runs (re-runs of specific mutants) are cached apart from regular runs: they have their own window of 10, so they never push a full run out of the cache, and `infection-summary` and `infection-escaped` only default to one when there is no regular run
 * re-running specific mutants goes through the repeatable `id` option of `infection-run` (`{"options":{"id":["<id1>","<id2>"]}}`), an empty list for a repeatable option is rejected instead of starting a full run, and the skill and instructions say that array and object parameters need `--json` from the CLI
 * a run limited to specific mutant ids is marked `partial` in the `infection-run` and `infection-summary` results, as its stats do not cover the project (a run scoped with `paths` or `git-diff-lines` is not marked: `partial` only means limited to mutant ids); the count is the number of mutants in Infection's log, so ids Infection no longer knows are not counted, and when none match the result says nothing ran instead of reporting an empty score
 * escaped `InstanceOf_` and `Ternary` mutants whose diff touches a `Traversable` check carry a `note` about test inputs that hide it (`ArrayIterator` is also `ArrayAccess`); the skill says the escaped listing already includes the diff and that `--json` combines with `--format=json`
 * `infection-escaped` takes `idsOnly` to list just the ids of a page, ready to pass as the `id` option of `infection-run`; the skill tells agents to observe mutants hidden by later code through a collaborator before calling them equivalent, and the equivalence notes say they are not a verdict
 * escaped mutants of mutators that are often equivalent (`TrueValue`, `FalseValue`, `UnwrapArrayValues`, every `Cast*`) carry a `note` in `infection-escaped` saying what to check before writing a test
 * the default page size (20) of `infection-escaped` is documented, and the `no JSON log` hint mentions concurrent runs sharing the system temp directory
 * escaped mutants of every Infection mutator without an equivalence note get a hint with the usual way to write the killing test, one text per group of mutators that mutate alike (arithmetic, boundaries and comparisons, branches, exceptions, loops, removals, return values, regex, `Unwrap*`, extensions, ...) and not starting with "To kill it,": `infection-escaped` lists them once per mutator in a `hints` map for the page instead of on every entry, `infection-mutant` returns the hint as its `note`; a test fails when a newer Infection adds a mutator that has neither a hint nor a note; `TrueValue` and `FalseValue` get the keys-only equivalence note only when the diff changes an array item
 * the skill says that Infection only mutates covered code by default, so `uncovered` stays empty and `msi` and `mutationCodeCoverage` can read 100 with untested files, and points to the `with-uncovered` option; it says a large share of timeouts hides escaped mutants and points to re-running the timed-out ids or the `with-timeouts` option, which counts them against `msi` but keeps them in the `timeouted` bucket; it tells agents to skip scoping when a full run is quick, and names `id`, not `ids`, as the option that takes the ids
 * `infection-mutant` is folded into `infection-escaped`: pass `id` (and `withOutput`) to inspect one mutant, whatever its bucket, instead of listing; `infection-describe-mutator` is removed, the diff and the hint cover what it was used for
 * the output of a failed `infection-run` and the `withOutput` test output of `infection-escaped` with an `id` are cleaned of progress bars, rulers and table padding and cut in the middle (head and tail) above 8,000 bytes instead of keeping only the last 2,000, so the errors of a failed static analysis run are no longer cut off; the full output is kept in a file (the newest 20) and named in `outputFile` or `processOutputFile`; the `no JSON log` hint and the skill name failing static analysis as a cause
 * `infection-run` checks up front that the installed Infection supports `--logger-json` and says which version to update to; `composer.json` conflicts with older releases

0.1.0
-----

 * Mutants are identified by the `id` Infection logs per entry instead of `bucket:index`: `infection-escaped` and `infection-mutant` use `id`, and `infection-run` can re-run specific mutants through the repeatable `id` option
 * Add the `infection-run`, `infection-summary`, `infection-escaped`, `infection-mutant` and `infection-describe-mutator` tools and the `infection://config` resource
 * Add the `infection-mutation-testing` skill and `INSTRUCTIONS.md` for AI agent guidance
 * Add quality tools configuration (PHPStan level 8, PHP CS Fixer, Rector, Infection)
 * Add the CI workflow
