## Infection Extension

Mutation testing with Infection. Use these tools instead of running `vendor/bin/infection` directly; they return compact, structured results and remember runs.

| User intent                                            | Tool                                                   |
|--------------------------------------------------------|--------------------------------------------------------|
| Run mutation testing, check the mutation score (MSI)   | `infection-run` (scope with `paths` or `gitDiffLines`) |
| What do the results look like, where are the test gaps | `infection-summary`                                    |
| Which mutants escaped, which code is uncovered         | `infection-escaped`                                    |
| Why did this mutant escape, how do I kill it           | `infection-mutant`                                     |
| What does this mutator do                              | `infection-describe-mutator`                           |
| How is Infection configured                            | `infection://config`                                   |

Start with `infection-run`, keep its `runId`, and drill down with the other tools instead of re-running. For the full workflow, equivalent-mutant handling and failure paths, read the `mate-infection-mutation-testing` skill.
