# WorshipPlanning

> ### 👉 Visit **[worship-planning.com](https://worship-planning.com)** 👈
>
> See the features and screenshots, try the [live demo](https://demo.worship-planning.com/admin/login), and read about [pricing](https://worship-planning.com/pricing.html): free to host yourself, or we host it for you. Start there before you install anything.

Multi-church worship service planner. Redesign of [WorshipHHN](https://github.com/thomasdilts/WorshipHHN) on Laravel.

If you want to try WorshipPlanning but need another language or anything else, Let us know. Write an issue!

## Stack

- Laravel 13 + Filament 5 (app shell at `/admin`)
- Shared database, hard `church_id` isolation (not DB-per-church)
- `spatie/laravel-permission` with teams (`church_id`) for flexible roles
- Custom `modules/` packages (not `nwidart/laravel-modules`)

## Architecture

```
app/Core/           Tenancy, module registry, permission registry
modules/            Optional feature modules (Events, Teams, Songs, …)
app/Filament/       Panel resources/pages for Core
```

Each church’s users and data are scoped by `church_id`. Platform admins (`is_platform_admin`) create churches and sit outside a church tenant.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# configure DB in .env, or use sqlite (default)
php artisan migrate --seed
php artisan serve
```

Open `/admin`.

| User | Password | Access |
|------|----------|--------|
| `admin@worshipplanning.test` | `password` | Platform admin (no church tenant) |
| `calum@example.test` | `password` | Church Admin — events + task templates |
| `planner@example.test` | `password` | Event Planner — events only (uses templates, cannot edit them) |

## Roles & permissions

Church admins manage this under **Administration → Roles** and assign roles on **Administration → Users**.

- Permission *strings* are registered in code (`PermissionRegistry`) by each module.
- Role *names* and which permissions they include are data per church (editable in the UI).
- Seeded templates: `Church Admin`, `Event Planner`, `Member`.

## Modules & messaging

- **Languages** — `languages` table (en / sv / ar seeded): English name, native name, font stack, `ltr`/`rtl`, ISO code. Users pick a language on their profile (default English). Laravel files live in `lang/{iso}/`; `SetUserLocale` applies the locale (Arabic uses Filament’s built-in RTL).
- **Administration → Modules** — enable/disable optional modules per church (`church_modules`).
- Module settings (e.g. SMTP) live in `church_modules.settings` JSON — **not** a global settings table.
- **Messaging** module: templates + email channel (SMS/WhatsApp stubs). Configure under **Messaging → Email settings**.
- Users pick **Preferred messaging** on their profile (and admins can set it on the user form).
- **Unavailability** module: people and teams record unavailable date ranges; the service-order planner shows **bold** conflict warnings when an assignee is away during the event. Personal list under the profile menu; each team has its own list on the team page.
- **My tasks** (**Planning → My tasks**, also in the profile menu): every event task assigned to the signed-in user. **Team → Tasks** shows tasks assigned to that team. Both default to event dates ≥ today, with a **From date** filter.
- **Teams** module: team types (Music / Prayer / Greeter…); each team has a type; task templates that use a team must pick a type; service-order team picker is filtered by that type; leaders manage roster; members can view and message teammates.
- **Pictures** module: church-wide picture library (upload/manage under **Pictures**); task templates set picture field to required / optional / unused; service-order tasks pick one or more library images when the template allows.
- **File sharing** module: church **Shared files** library plus per-team **Files** tab; task templates set **Files** and **Special needs** to required / optional / unused; service-order tasks attach shared files when the template allows.
- **Bible verses** module: task templates set **Bible verses** to required / optional / unused; service-order tasks pick OSIS passages (book/chapter/verse) via the Zamar Bible API. Configure per church under **Bible → API settings** (`ZAMAR_BIBLE_API_URL` / `ZAMAR_BIBLE_API_KEY` / `ZAMAR_BIBLE_DEFAULT` as defaults). Stored as `{bible, ref, label}` JSON — verse text stays in the API.
- **Song list** module: church song catalog (**Songs → Song list**) with CSV import/export matching WorshipHHN (`Name, Name2, Author, Description`); lyrics edited in a multi-line field; task templates set song usage + optional **Allow manual entry** (free-text title instead of a catalog pick).
- **Song statistics** module: read-only usage grid (**Songs → Statistics**) of every song appearing on event tasks — both Song list catalog picks and manual titles — with per-column sort, field filters, column show/hide + reorder, and session persistence (no extra table).
- **Task spreadsheet** (**Events → Task spreadsheet**): matrix of upcoming events (today+) vs task-template columns — person/team assignments, plus any templates flagged **Show in task spreadsheet**, ending with an **Unavailable** column of people/teams away on that event’s dates.

## Modules

Register providers in `config/modules.php`. A module service provider should:

1. Register a `ModuleDefinition` on `ModuleRegistry`
2. Register permission strings on `PermissionRegistry`
3. Mount routes / Filament resources only when the church has the module enabled

## UI/UX skills

Project skills live in `.cursor/skills/`:

- `ops-saas-ux` — dense operational software rules
- `worship-planning-ux` — domain patterns for this product

## License

GPL-3.0-or-later (same family as WorshipHHN).
