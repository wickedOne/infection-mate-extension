# Extension Template for Symfony Mate

A starter template for building [MatesOfMate](https://github.com/matesofmate) extensions that follow the current Symfony Mate workflow.

## Quick Start

1. Use this template on GitHub.
2. Replace all `example` and `ExampleExtension` placeholders with your framework name.
3. Run `composer install`.
4. Add your tools and resources in `src/Capability/`.
5. Run `composer test` and `composer lint`.

## Current Mate Flow

This template is aligned with the current `symfony/ai-mate` `0.13.x` workflow and the current core Mate response encoding behavior:

- initialize projects with `vendor/bin/mate init`
- extension discovery is handled automatically on Composer install and update in current Mate setups
- `mate/extensions.php` controls which discovered extensions are enabled
- `vendor/bin/mate discover` still refreshes discovery state and regenerates agent instruction artifacts
- tools and resources are invoked through the `vendor/bin/mate` CLI, not an MCP server

Useful Mate commands while developing:

```bash
vendor/bin/mate debug:capabilities
vendor/bin/mate debug:extensions
vendor/bin/mate tools:list
vendor/bin/mate tools:inspect example-hello
```

## Structure

```text
extension-template/
├── .github/
├── composer.json
├── README.md
├── LICENSE
├── .gitignore
├── phpunit.xml.dist
├── phpstan.dist.neon
├── rector.php
├── .php-cs-fixer.php
├── src/
│   └── Capability/
│       ├── ExampleTool.php
│       └── ExampleResource.php
├── config/
│   └── config.php
├── skills/
│   └── example-workflow/
│       └── SKILL.md
└── tests/
    └── Capability/
        ├── ExampleToolTest.php
        └── ExampleResourceTest.php
```

## Installation in a Project

```bash
composer require --dev matesofmate/your-extension
vendor/bin/mate init
```

In current Mate setups, extension discovery is handled automatically after install and update. Run `vendor/bin/mate discover` when you want to refresh generated instruction artifacts or re-scan the project manually.

Coding agents call the tools through the `vendor/bin/mate` CLI:

```bash
vendor/bin/mate tools:call example-hello --name=World
vendor/bin/mate resources:read example://config
```

## Creating Tools

Tools are PHP classes with methods marked with `#[MateTool]`.

```php
<?php

namespace MatesOfMate\ExampleExtension\Capability;

use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * Example tool showing the default MatesOfMate style.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ListEntitiesTool
{
    public function __construct(
        private readonly SomeService $service,
    ) {
    }

    /**
     * @param string|null $scope Optional scope used to narrow the entities that are returned.
     */
    #[MateTool(
        name: 'example-list-entities',
        description: 'List available entities. Use when the user asks which entities, models, or tables exist.'
    )]
    public function execute(?string $scope = null): string
    {
        $entities = $this->service->getEntities($scope);

        return ResponseEncoder::encode([
            'entities' => $entities,
            'count' => count($entities),
        ]);
    }
}
```

Tool guidance:

- Use `{framework}-{action}` for tool names.
- Prefer one flexible tool with clear parameters over several near-duplicate tool names.
- Write descriptions that say when the AI should call the tool.
- Add `@param` docblocks for every parameter; Mate builds the input schema from the parameter types and those descriptions.
- For encoded string payloads, use Mate's built-in `ResponseEncoder` so TOON is used when available and JSON is used as a fallback.
- Current Mate also supports array and scalar tool returns. Use encoded strings when you want stable structured output across environments.
- Register tool classes in `config/config.php`.

## Creating Resources

Resources provide static or semi-static context to the AI.

```php
<?php

namespace MatesOfMate\ExampleExtension\Capability;

use Symfony\AI\Mate\Attribute\MateResource;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * Example resource showing the default MatesOfMate style.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ConfigurationResource
{
    #[MateResource(
        uri: 'example://config',
        name: 'example_config',
        mimeType: 'text/plain'
    )]
    public function getConfiguration(): array
    {
        return [
            'uri' => 'example://config',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode([
                'version' => '1.0.0',
                'features' => ['feature_a' => true],
            ]),
        ];
    }
}
```

Resource guidance:

- Use a custom URI scheme such as `example://config`.
- Return `uri`, `mimeType`, and `text`.
- Use `text/plain` for encoder-backed resource text because the payload may be TOON or JSON depending on the installed environment.
- Prefer the core Mate `ResponseEncoder` instead of maintaining a package-local encoding helper.

## Registering Services

Register capabilities in `config/config.php`:

```php
<?php

use MatesOfMate\ExampleExtension\Capability\ListEntitiesTool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(ListEntitiesTool::class);
};
```

## Agent Instructions

`INSTRUCTIONS.md` should help AI agents map common user intents to your capabilities. Keep it short, concrete, and focused on when to use your tools instead of CLI commands.

Current Mate workflows also materialize aggregated instructions into `mate/AGENT_INSTRUCTIONS.md` and maintain a managed Mate block in the project `AGENTS.md` when discovery is refreshed.

## Agent Skills

`skills/<name>/SKILL.md` holds the skills your extension ships. Declare the directory once:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Mate installs each skill into the consuming project as `mate-<name>`, so prefix the name with your framework to keep it unique. The front matter `name` must match the directory name, and the `description` must say when the skill applies, because that is all an agent reads before deciding to open it.

`INSTRUCTIONS.md` maps intents to tools. A skill goes further: which tool comes first, how to read the payload, what a specific error means, and what not to do. Write down the judgment the tool schema cannot carry.

Follow the shape the Symfony Mate skills use: the tool list first, then `## Workflow`, `## Reading`, `## Failure paths`, and `## Rules` where it earns its place. Commands go inline in backticks rather than in fenced blocks.

```bash
vendor/bin/mate skills:list
vendor/bin/mate skills:validate
```

## Testing and Quality

```bash
composer test
composer lint
composer fix
```

Useful direct commands:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Checklist Before Publishing

- [ ] Replace all `example` and `ExampleExtension` placeholders
- [ ] Update `composer.json` package name and description
- [ ] Update `.github/CODEOWNERS`
- [ ] Update `LICENSE`
- [ ] Replace example tool and resource names, URIs, and descriptions
- [ ] Replace `skills/example-workflow/` with a skill for your framework
- [ ] Update README install and usage docs for your framework
- [ ] Make sure `composer test` passes
- [ ] Make sure `composer lint` passes
- [ ] Tag a release and submit to Packagist

## Resources

- [Symfony Mate docs](https://symfony.com/doc/current/ai/components/mate.html)
- [Creating Mate extensions](https://symfony.com/doc/current/ai/components/mate/creating-extensions.html)
- [MatesOfMate contributing guide](https://github.com/matesofmate/.github/blob/main/CONTRIBUTING.md)

---

*"Because every Mate needs Mates"*
