# AI Brand Voice for Silverstripe CMS

This current project is to build a Silverstripe CMS "ai-brand-voice" module in `vendor/silverstripeltd/ai-brand-voice`. No other work is being performed.

Do not modify any files outside of the `vendor/silverstripeltd/ai-brand-voice` directory unless explicitly instructed to do so.

**Silverstripe CMS 6** module that lets users define a corporate content style guide and uses AI to evaluate page content against it, surfacing compliance status and recommendations in the CMS.

When implementing phases from a plan, implement all phases in one go. Do not stop to ask for review or confirmation between phases. Act as an autonomous developer - use your best judgement, do not ask for clarifications.

Never attempt to use MCP (Model Context Protocol) - it is disabled at an organisation level.

## Writing style

Never use em dashes (-) in any files. Use a regular hyphen (-) instead.
PHP and JS files should give each class and method a short docblock explainer. Do not include param or return types in those docblocks.
PHP methods should not have blank lines between statements (except within heredoc/nowdoc strings).

## Hard constraints

- **NEVER view or edit bundled/compiled files** - no `/dist/`, `bundle.js`, `vendor.js`, or `*.min.js`. These will blow out your context window.
- **Only change code directly related to the task** - no unrelated cleanup, lint fixes, or reformatting. Note anything worth fixing in `z-learnings.md` instead.

## Directory structure

- `client/src/` - React modal + Entwine adapter sources
- `client/tests/` - JS tests and supporting test files, mirroring the relevant `client/src/` paths
- `client/dist/` - Webpack build output (JS/CSS bundles) - do not read or edit
- `src/` - PHP classes (controllers, services, extensions, etc.)
- `_config/` - YAML configuration (extensions, routes, requirements)
- `specs/` - Technical specs used as basis for implementation. See `specs/00_overview.md` for the full spec index.
- `tests/php/` - PHPUnit tests

Files in `specs/` are prefixed with `01_`, `02_`, etc.

## Running commands

All commands run inside Docker via SSH. Never call `phpunit`, `phpcs`, `phpcbf`, `yarn`, `npm`, or `npx` directly. Always prefix the actual command with `nice -n 19 ionice -c 3 taskset -c 0` to keep CPU/IO usage low. For yarn/node commands also prepend `NODE_OPTIONS=--max-old-space-size=512` to cap memory.

### PHPUnit testing conventions

- Use `SapphireTest` which provides a temporary database - do not mock dependencies like a traditional unit test.
- Use fixtures or programmatically create data within tests.
- Use `#[DataProvider('provideFoo')]` attribute syntax (PHP 8), **not** the legacy `@dataProvider` annotation - the annotation does not work in this project.
- Place the provider method directly above the test method it supplies.
- Do not use the description argument in assertions (e.g. `$this->assertTrue(true, 'message')` - just `$this->assertTrue(true)`).

#### Running tests

`ssh webserver "cd /var/www && rm -rf /tmp/pu-cache && mkdir -p /tmp/pu-cache && SS_TEMP_PATH=/tmp/pu-cache nice -n 19 ionice -c 3 taskset -c 0 vendor/bin/phpunit vendor/silverstripeltd/ai-brand-voice/tests/ --fail-on-warning [--filter={test-name}]"`

#### PHP linting

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice && nice -n 19 ionice -c 3 taskset -c 0 ../../bin/{binary} --ignore=*/thirdparty/*,*/node_modules/* --extensions=php ."`

Run `phpcs` or `phpcbf` after changing PHP files.

Replace `{binary}` with `phpcs` or `phpcbf`.

### JavaScript testing conventions

- Flat `test()` blocks only - no `describe()` nesting.
- Use RTL queries by accessibility role/text (`getByRole`, `getByText`), not `getByTestId`.
- Place JS tests under `client/tests/`, mirroring the relevant `client/src/` path. Keep fixtures or other supporting test files alongside that mirrored test path when needed.

#### JS dependency prerequisite

This module depends on `vendor/silverstripe/admin` for shared JS tooling. Before running `yarn test` or `yarn lint`, first run `yarn install` in admin:

`ssh webserver "cd /var/www/vendor/silverstripe/admin && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn install"`

#### Running tests

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn test"`

#### Linting

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn lint"`

### Spec editing rules

- Files in `specs/` are prefixed with `01_`, `02_`, etc. Numbering reflects recommended implementation order. When adding new spec files, number them according to where they sit in the implementation sequence.

### Final step - JS build

If any `.js` or `.jsx` files were changed during the task, run `yarn build` as the **very last code-related step**, after all implementation, tests, and linting pass. Do not run it mid-implementation to check progress.

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice/client && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn install && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn build"`

## Other files

This `CLAUDE.md` file is intended to be symlinked to the project root as `CLAUDE.md`. Do not read it again directly if it is already loaded as pre-prompt.

## Learnings

Read `/app/z-learnings.md` if it exists. Append non-obvious discoveries - gotchas, workarounds, patterns, constraints. Prefix each entry with a category tag (e.g. `[testing]`, `[config]`, `[api]`). Keep entries to 1-2 sentences. Create the file with a `# Learnings` heading if it doesn't exist. Never overwrite existing entries; always append.

## z- file outputs

If you are asked to create any `z-*.md` files, always look for them and put them in the `/app` dir, not `vendor/silverstripeltd/ai-brand-voice`.
