# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: church planners and church admins who build weekly worship services (events), assign people/teams to each line of the service order, manage songs and modules, and notify volunteers.

Secondary: volunteers/members who log in mainly to see **My tasks**, accept/refuse assignments, and record unavailability.

Platform admins create and manage churches; they sit outside a single church tenant when `is_platform_admin` is set.

## Product Purpose

WorshipPlanning is a multi-church worship service planner: congregations plan services as an ordered run-of-show, staff them with people and teams, optionally manage a song catalog, and communicate with volunteers. Success means next Sunday’s service is staffed and clear without cross-church data leaks.

## Positioning

Hard `church_id` tenancy on a shared Laravel/Filament app, module switches per church, and the **service order timeline** as the primary planning surface (not a generic CRM). Successor to WorshipHHN with the same domain jobs, simplified.

## Operating Context

- App shell: Filament admin at `/admin` (top navigation).
- Public: `/partner` church access request; login marketing aside.
- Rituals: plan event → fill service order → assign → notify → volunteer accepts/refuses; PDF/spreadsheet export.
- Languages: en / sv / ar with RTL for Arabic.

## Capabilities and Constraints

- Stack: Laravel + Filament 5; custom `modules/` packages; Spatie permission teams (`church_id`).
- Modules (optional per church): messaging, teams, songs, song statistics, unavailability, abilities, pictures, file sharing, bible verses.
- Visual redesign (2026): Operate-mode Filament UI; Persuade only for login aside + `/partner`. Must keep density, tenancy, and church logo branding.
- Do not invent testimonials, fake KPI dashboards, or cross-church features.

## Brand Commitments

- Product name: **WorshipPlanning**.
- Per-church logo upload brands the panel; product face is the service-order run-of-show.
- Shared typeface target: **Instrument Sans** (public already; panel should match).
- Tone: calm church-ops — not flashy marketing SaaS.

## Evidence on Hand

- Live Filament resources/pages under `app/Filament/`.
- Domain UX skills: `.cursor/skills/ops-saas-ux`, `.cursor/skills/worship-planning-ux`.
- README product map and seeded roles.
- No marketing photography library committed for product pages.

## Product Principles

1. Service order first — the run-of-show is the highest-value screen.
2. Volunteer mobile path — My tasks accept/refuse in few taps.
3. Hide disabled modules — no dead grey nav.
4. Status color means state only (accepted / pending / conflict).
5. Density over decoration — planners work in tables and timelines.

## Accessibility & Inclusion

Support LTR/RTL via user language; keep readable contrast on logos in dark mode (light plate behind dark marks). Prefer `prefers-reduced-motion` for non-essential animation.
