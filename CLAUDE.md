# JT Property Management System — Claude instructions

Spec: `JT_Property_Management_MASTER_PROMPT.md` · Checklist: `JT_Property_Management_BUILD_CHECKLIST.md`
Stack: Laravel 13 + React/TypeScript + Inertia + Vite + Tailwind + shadcn/ui + MySQL (single Laravel monolith).

## Skills — ALWAYS use these

Before responding to any task, check which skill applies and invoke it via the Skill tool.

| When | Skill |
|---|---|
| Start of every session / any task | `superpowers:using-superpowers` |
| New feature, screen, module, or behavior change | `superpowers:brainstorming` → `superpowers:writing-plans` |
| Executing a plan | `superpowers:subagent-driven-development` or `superpowers:executing-plans` |
| Writing any code (billing, payments, balances especially) | `superpowers:test-driven-development` |
| Any bug, failing test, unexpected behavior | `superpowers:systematic-debugging` |
| Before saying "done" / "fixed" / "passing" | `superpowers:verification-before-completion` |
| After finishing a phase or major feature | `superpowers:requesting-code-review` |
| Questions about codebase structure / architecture | `graphify` (query `graphify-out/` if it exists; rebuild after big changes) |
| Any UI/UX work: pages, layouts, components, colors, typography, dashboards, PDF statement design | `ui-ux-pro-max` |
| Any Laravel work (routes, models, migrations, Inertia, queues, mail, tests) | Laravel Boost MCP tools + guidelines (see below) |
| Writing/reviewing code: keep it simple, no unrequested abstractions | `ponytail` (and `ponytail-review` / `ponytail-audit` after each phase) |

**Ponytail vs. the spec:** Ponytail means simplest code that works. It never removes
requirements from the master prompt: audit trails, immutable financial entries, policies,
tests for billing logic, and PDF/email features are all required.

## Laravel Boost

Laravel Boost (`laravel/boost`) must be installed as soon as the Laravel app exists (Phase 1):

```bash
composer require laravel/boost --dev
php artisan boost:install   # choose Claude Code; enables MCP server + AI guidelines
```

After install, prefer Boost MCP tools (`search-docs`, `database-schema`, `tinker`, `list-routes`,
`last-error`, `read-log-entries`, `browser-logs`) over guessing. Use `search-docs` for
version-specific Laravel 13 / Inertia / Tailwind docs before writing framework code.

## Project rules (summary — the master prompt wins on conflicts)

- Money: `DECIMAL(15,2)` or integer centavos, never floats. Timezone `Asia/Manila`. Currency `₱1,234.56`.
- All financial calculations server-side in Laravel services; posted entries immutable, corrections via reversals + audit log.
- Authorization in every controller/action (policies), not just hidden React buttons.
- Do not build separate frontend/backend apps or an unnecessary REST API.

===
