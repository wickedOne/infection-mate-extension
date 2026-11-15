CHANGELOG
=========

0.1.0
-----

 * Mutants are identified by the `id` Infection logs per entry instead of `bucket:index`: `infection-escaped` and `infection-mutant` use `id`, and `infection-run` can re-run specific mutants through the repeatable `id` option
 * Add the `infection-run`, `infection-summary`, `infection-escaped`, `infection-mutant` and `infection-describe-mutator` tools and the `infection://config` resource
 * Add the `infection-mutation-testing` skill and `INSTRUCTIONS.md` for AI agent guidance
 * Add quality tools configuration (PHPStan level 8, PHP CS Fixer, Rector, Infection)
 * Add the CI workflow
