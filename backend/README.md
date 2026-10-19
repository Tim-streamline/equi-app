<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## Synchronize the intake questionnaire

`database/data/intake-questionnaire-local.json` is a definition-only export of the local database from 2026-10-20 (13 sections, 394 fields, including inactive legacy definitions). It includes answer choices, conditional logic, ordering and photo settings, but **no customer answers or files**. It deliberately captures the database rather than the seeder, which may differ from CMS edits.

After deploying this code and applying the intake schema migrations through the normal deployment process, run these commands from the backend/release directory on the destination:

```sh
# Preview only: no database writes or backup files.
php artisan intakes:sync-questionnaire

# Apply in one transaction, back up definitions first, and verify the result.
php artisan intakes:sync-questionnaire --apply

# Verify that the next preview reports zero changes.
php artisan intakes:sync-questionnaire
```

The command matches the questionnaire by slug, sections by key, and fields by their unique key across that questionnaire. It updates existing rows in place, preserving IDs and creation dates. Moved fields retain their original answer namespace through `answer_section_key`. New definitions are inserted; target-only definitions are archived, never deleted. Other questionnaires are untouched. Therefore the imported definitions and active form match the source, while extra historical rows may remain inactive for preservation.

It refuses missing schema columns, incomplete snapshots, duplicate/ambiguous field keys, answer-namespace changes and field-type changes. It never guesses how to convert saved values or rename question keys. It does not execute migrations, seed the database, rewrite responses, move attachments, or clear any sync queue. PostgreSQL configuration writes are serialized; a write/verification failure rolls back the entire import.

Saved answers and photo metadata remain unchanged, including old choices no longer offered. Retired questions can disappear from the active form/review while their responses remain in the database. New photo requirements (such as year or view assignment) can require users to complete metadata for existing uploads. The preview reports changed settings and counts of saved answers on changed questions without printing those answers. Compatibility with already-open/offline clients still needs the normal web release rollout; this command does not force clients to refresh.

Before a nonempty apply, a private, timestamped JSON definition backup is created under `storage/app/private/intake-questionnaire-backups/`. It also records the original definition IDs for audit. No customer responses are included because they are never modified. Keep the deployment database backup as well. A previous definition backup can be previewed with `--file=/absolute/path/to/backup.json` and applied with `--apply`; the same namespace/type safeguards apply to rollback. Do not bypass a refusal—prepare an explicit mapping/migration if needed.

To capture later local CMS changes, export to a **new** file, review it, and supply it to the destination command with `--file`:

```sh
./vendor/bin/sail artisan intakes:export-questionnaire database/data/intake-questionnaire-next.json
php artisan intakes:sync-questionnaire --file=database/data/intake-questionnaire-next.json
```

Export never overwrites an existing file. `--slug` selects a different existing questionnaire; the importer only changes the matching destination slug and refuses to create a new top-level questionnaire implicitly. Do not run the questionnaire seeder after synchronization, as it applies the separate seed definition.

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
