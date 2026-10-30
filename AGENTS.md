# AGENTS.md

Read `CLAUDE.md` first: it holds the structure, design rules, testing constraints and conventions of this extension.

- namespace `WickedOne\InfectionMateExtension`; tools are named `infection-*`, the resource is `infection://config`
- before finishing: `vendor/bin/phpunit`, `composer lint`, `composer test` (Infection, no escaped mutants) and `vendor/bin/mate skills:validate`
- commit messages carry no AI attribution

## Resources

- [Symfony Mate docs](https://symfony.com/doc/current/ai/components/mate.html) for generic Mate workflow and extension authoring
- `vendor/symfony/ai-mate/INSTRUCTIONS.md` for the generic Mate agent instructions
