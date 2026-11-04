# CLAUDE.md

## Project Overview

Akeeba Panopticon Connector for WordPress — a WordPress plugin that exposes REST API endpoints allowing remote management of WordPress sites through [Akeeba Panopticon](https://github.com/getpanopticon/panopticon). It handles core updates, plugin/theme management, and integrates with Akeeba Backup and Admin Tools Professional.

## Build

The build system uses Apache Phing with shared build files from `../buildfiles/phing/`, which live outside this repository. See the Phing Build skill for the available targets.

## Testing

There is no automated test suite. Manual API testing is done with JetBrains HTTP Client files in `assets/*.http` (core, extensions, updates, admintools, backup). These require a `http-client.private.env.json` file (gitignored) with site URL and auth token.

## Architecture

### Entry Point and Plugin Lifecycle

`panopticon.php` — contains the `PanopticonPlugin` singleton class. It:
- Manually requires all class files from `includes/` (no autoloader for plugin classes; Composer autoloader is used only for vendor)
- Registers REST routes via the `rest_api_init` action
- Handles plugin self-update checks against `update.json` on GitHub
- Uses `Panopticon_Options_Trait` for the Settings > Panopticon admin page

### REST API Controllers

All routes are under the `v1/panopticon` namespace. Each controller in `includes/` extends `WP_REST_Controller`, except `Panopticon_Server_Info`, which is a callable class with no routes of its own (used by `Panopticon_Core`).

### Authentication

Token-based auth implemented in `PanopticonPlugin::authorizeAPIUser()`:
- Token stored as WordPress option `panopticon_token`, hashed with SHA-256 + WP salt
- Accepted headers: `Authorization: Bearer {token}`, `X-Panopticon-Token`, `X-Joomla-Token` (compatibility)
- Authenticates as the first Super Admin (multisite) or Administrator user
- Hooks into `determine_current_user` filter

## Code Conventions

- PHP 7.2 compatibility required — no typed properties, no union types, no match expressions, no named arguments, no trailing commas in function parameters
- Allman brace style (opening brace on its own line)
- Tab indentation
- Classes are not namespaced; they use a `Panopticon_` prefix
- Class files in `includes/` follow the pattern `panopticon-{name}.php`
- PHPDoc `@since` tags track the version where methods/properties were introduced
- `@var` type annotations on all properties
