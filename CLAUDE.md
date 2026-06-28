# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A demo repository for spinning up a **Drupal 11** development environment on CodeSandbox / devcontainers via Docker Compose. The repo itself contains almost no Drupal code — the Drupal core, Composer dependencies, and Drush live **inside the `drupal` container** at `/opt/drupal`. The repo's job is the Docker setup plus the bind-mounted directories where custom code and configuration go.

## Bootstrapping the environment

```sh
./setup.sh
```

`setup.sh` does the full first-time setup and is **not** idempotent in a cheap way (it runs full installs each time):
1. Seeds `web/themes`, `web/modules`, `web/sites`, `composer.json`, and `composer.lock` from a throwaway `drupal` container into the repo (these dirs are empty until this runs).
2. `docker compose up -d` — starts `mysql` (MariaDB) and `drupal` (Apache/PHP).
3. `claude-cli-install.sh` — installs UV, Node/npx, Claude Code CLI, and Spec-Kit; sets `dangerouslySkipPermissions: true` in `~/.claude/settings.json`.
4. Installs `default-mysql-client`, Drush, and dev packages (PHPUnit, `drupal/core-dev`) inside the container.
5. Runs `drush site:install standard` against `mysql://root:password@mysql/drupal`, creating an admin user `admin` / `admin`.

Site is served at **http://localhost:8080**. MariaDB is on `3306` (root password `password`, database `drupal`).

## Common commands

Everything Drupal-related runs **inside the container**. Drush is symlinked to `/usr/local/bin/drush` (and also at `/opt/drupal/vendor/bin/drush`).

```sh
# Drush — clear cache, run updates, export/import config, etc.
docker compose exec drupal drush cr
docker compose exec drupal drush updb -y
docker compose exec drupal drush cex -y          # export config to ./config (bind-mounted)
docker compose exec drupal drush cim -y          # import config from ./config
docker compose exec drupal drush uli             # one-time admin login link

# Composer (run against the container's Drupal root)
docker compose exec drupal composer require drupal/<module> --working-dir=/opt/drupal
docker compose exec drupal composer require --dev <pkg> --working-dir=/opt/drupal

# Enable / uninstall modules
docker compose exec drupal drush en <module> -y
docker compose exec drupal drush pmu <module> -y

# Open a shell in the container
docker compose exec drupal bash
```

### Tests (PHPUnit)

PHPUnit and `drupal/core-dev` are installed under `/opt/drupal`. Run from the Drupal root inside the container:

```sh
# Whole module
docker compose exec drupal /opt/drupal/vendor/bin/phpunit -c web/core web/modules/custom/<module>

# Single test file
docker compose exec drupal /opt/drupal/vendor/bin/phpunit -c web/core web/modules/custom/<module>/tests/src/Unit/SomeTest.php

# Single test method
docker compose exec drupal /opt/drupal/vendor/bin/phpunit -c web/core --filter testMethodName <path>
```

## Architecture & where things go

The container/host split is the thing to internalize:

- **`/opt/drupal`** (inside container only) — the real Drupal install: `core/`, `vendor/`, contrib modules pulled by Composer. Not in the repo. `composer.json` / `composer.lock` are bind-mounted here from the repo root, so dependency changes made via `composer require --working-dir=/opt/drupal` persist back to the repo.
- **`web/`** (repo, bind-mounted into the container) — only `themes/`, `modules/`, and `sites/` are mounted. **Put custom code here**: `web/modules/custom/<name>`, `web/themes/custom/<name>`. Contrib code installed via Composer lands in the container's `/opt/drupal`, *not* under `web/`.
- **`config/`** (repo, mounted at `/opt/drupal/config`) — target for `drush cex` config sync YAML. Currently empty.
- **`web/sites/default/`** — Drupal's `settings.php` and DB connection live here. `mysql/data/` holds MariaDB's data volume.

`composer.json` uses `drupal/core-composer-scaffold` with `web/` as the web root and `installer-paths` routing each package type (`drupal-custom-module` → `web/modules/custom/{$name}`, etc.).

### Frontend (`frontend/`, runs OUTSIDE the container)

`frontend/` is a **Next.js 15 (App Router / TypeScript)** app — the headless consumer MVP. It is **not** part of Drupal and **not** in any bind mount; it runs on the host Node (`node`/`npm` are installed by `claude-cli-install.sh`), not in the `drupal` container.

```sh
cd frontend && npm install && npm run dev   # http://localhost:3000
```

- Fetches Drupal's JSON:API **server-side** (Next → Drupal), so **no CORS config is needed** on Drupal. Base URL is `frontend/.env.local` → `DRUPAL_JSONAPI_BASE` (default `http://localhost:8080`).
- Current scope is a single **search-list page** (`app/page.tsx`) filtering `book`/`article` by `tech_stack` / `target_audience` / keyword via JSON:API `filter[...]`; data layer in `lib/jsonapi.ts`.
- The bookreview model (content types `book`/`article`/`review`, taxonomies, flags, sample data) is created by the **idempotent** `web/modules/custom/bookreview/scripts/setup_resources.php` (`drush php:script ...`). See `docs/verification-notes.md` for the full backend verification (Q1–Q5).

## Important notes

- **`.env` contains a live `ANTHROPIC_API_KEY` and is not gitignored.** There is no `.gitignore` at all, so `mysql/data/`, `web/`, and `.env` are all candidates to be accidentally committed. Treat the key as compromised and avoid committing these.
- The container's `Dockerfile` `WORKDIR` ends at `/workspace/var/www/html`, but the bind mounts and Drush operate against `/opt/drupal` and `/var/www/html` — when running commands, prefer absolute paths (`/opt/drupal/vendor/bin/...`) rather than relying on the working directory.
