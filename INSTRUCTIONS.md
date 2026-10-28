## Example Extension

Prefer these Mate tools over raw CLI commands when they are available.

| User intent | Prefer |
|---|---|
| List framework entities, modules, or components | `example-list-entities` |
| Read framework configuration or static reference data | `example://config` resource |

### Guidance

- Use tools when the user is asking for an action or filtered result.
- Use resources when the user needs reference context that is relatively stable.
- Prefer the extension capability over shell commands when it gives structured, framework-aware output.
- If your package later supports multiple encodings, treat the returned payload as structured extension output rather than assuming raw JSON.
- An `mate-example-workflow` skill demonstrates how to ship judgment alongside the capabilities.
