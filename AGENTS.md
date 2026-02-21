# AGENTS

This file is the shared guide for agentic coding tools operating in this repo.
Follow existing conventions and the Laravel Boost rules in `.cursor/rules/laravel-boost.mdc`.

## Project Context
- Laravel 12 application using Laravel 10 style structure.
- PHP 8.2+ (rules mention 8.4.1) and PHPUnit 11.
- Tailwind CSS v4 with Vite.
- Sanctum auth and Spatie permissions.

## Commands

### Install and Setup
- `composer install`
- `npm install`
- `composer run setup` (creates env, runs migrate, builds assets)

### Dev Server
- `php artisan serve`
- `npm run dev`
- `composer run dev` (concurrent server, queue, logs, Vite)

### Build
- `npm run build`

### Format / Lint
- `vendor/bin/pint --dirty` (required before finalizing changes)

### Tests
- All tests: `php artisan test --compact`
- File: `php artisan test --compact tests/Feature/ExampleTest.php`
- Filter: `php artisan test --compact --filter=testName`
- Suite: `php artisan test --testsuite=Feature`

## Testing Guidance
- Prefer minimal tests related to changes.
- Use PHPUnit classes only. Convert any Pest tests to PHPUnit.
- After updating a test, run that single test.
- Use factories for model setup whenever available.

## Code Style and Conventions

### PHP Formatting
- 4 spaces, LF endings, final newline (see `.editorconfig`).
- Curly braces always, even for single-line blocks.
- Use explicit return types and parameter types for methods.
- Use constructor property promotion when possible.
- Avoid empty `__construct()` unless private.
- Prefer PHPDoc blocks for complex logic or array shapes.

### Imports
- Use `use` statements (no fully qualified names inline unless necessary).
- Group related imports; keep them readable and sorted.
- In tests, grouped imports like `use App\Models\{A, B};` are acceptable.

### Naming
- Classes: PascalCase.
- Methods, variables: camelCase.
- Table columns and attributes: snake_case.
- Enums: TitleCase keys.
- Keep method and variable names descriptive.

### Laravel Practices
- Use `php artisan make:*` to generate classes (add `--no-interaction`).
- Keep the Laravel 10 style folder layout (no migration to new structure).
- Use Eloquent relationships with return types.
- Prefer `Model::query()` over `DB::` where possible.
- Use eager loading to avoid N+1 queries.
- Use Form Request classes for validation, not inline.
- Use named routes and `route()` for URLs.
- Do not use `env()` outside config files.

### Models and Casting
- Check sibling models for `casts()` method or `$casts` usage.
- Use array shape PHPDoc when returning structured arrays.

### Error Handling
- Use `try`/`catch` around transaction blocks with rollback.
- Return consistent JSON error messages with proper status codes.
- Avoid swallowing exceptions; surface meaningful errors.

### Tests
- Use factories and factory states.
- Favor feature tests over unit tests.
- Include happy paths, failure paths, and edge cases.

## Frontend (Tailwind v4)
- Use Tailwind classes in Blade/JS templates.
- Use `@import "tailwindcss";` (already in `resources/css/app.css`).
- No `tailwind.config.js`; use CSS-first `@theme`.
- Use gap utilities for spacing in lists (`flex gap-*`).
- If existing UI supports dark mode, add `dark:` classes.

## Build and Vite Notes
- Vite inputs live in `resources/css/app.css` and `resources/js/app.js`.
- If assets do not update, run `npm run dev`, `npm run build`, or `composer run dev`.
- Vite manifest errors can be fixed by re-running `npm run build`.

## Data Generator (scripts/)
- `cd scripts && npm install`
- Generate data: `npm run generate` (also `generate:small` and `generate:large`).

## Laravel Boost / Cursor Rules (Required)
- Follow all conventions in `.cursor/rules/laravel-boost.mdc`.
- Use Boost `search-docs` for Laravel ecosystem docs before any other approach.
- Use `list-artisan-commands` before running Artisan commands.
- Use `tinker` for PHP debugging and `database-query` for read-only queries.
- Use `get-absolute-url` when sharing project URLs.
- Do not add dependencies or new base folders without approval.
- Do not create documentation files unless explicitly requested.

## Repo Structure Pointers
- Controllers: `app/Http/Controllers/` (API in `Api/`).
- Models: `app/Models/`.
- Routes: `routes/api.php`.
- Tests: `tests/Feature` and `tests/Unit`.

## Working Agreement for Agents
- Keep changes minimal and aligned with existing patterns.
- Do not remove tests or test files without approval.
- Avoid destructive git commands.
- If unsure, read sibling files and follow local conventions.
