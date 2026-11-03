---
name: worship-planning-ux
description: >-
  Domain UX for WorshipPlanning: multi-church service planning, teams, volunteer
  scheduling, songs, and module-aware UI. Use when designing or implementing
  event/service-order, team, task accept/refuse, church tenancy, or module screens.
---

# WorshipPlanning UX

Extends `ops-saas-ux`. Product context: redesign of WorshipHHN — churches plan services (events), assign people/teams to activities, manage songs, notify volunteers.

## Product map (mental model)

| Concept | User language | Notes |
|---------|---------------|--------|
| Church | Church / congregation | Tenant boundary; data never crosses |
| Event | Service / event | Has a **service order** (ordered activities) |
| Activity | Line in the service | May attach song, person, team, bible, time |
| Team | Team (choir, band, tech) | Membership + unavailability |
| Task | My assignment | Accept / refuse; ICS/PDF export |
| Module | Feature switch | Songs, Bible, Media, Messaging — hide when off |

## Core screens (priority)

### 1. Service order (highest product value)

- Vertical **timeline / ordered list** of activities for one event
- Drag (or up/down) reorder; inline add/edit/remove
- Row shows: time, name/type, assignee or team, song/bible chip if module on
- Primary actions on the event: Notify, Export PDF/Excel, Copy event
- Do **not** turn this into a generic Filament relation manager only — it must feel like a run-of-show

### 2. My tasks

- Default home for members/volunteers after login when they are not admins
- Accept / refuse with clear status
- Link to event context; blocked/unavailable dates nearby
- Mobile-first

### 3. Teams

- Roster, add/remove members
- Unavailability calendar/list
- Team-scoped permissions (“manage this team”) distinct from church-wide roles

### 4. Church vs platform

- Platform admin: create/manage churches; no mixing into one church’s service UI by accident
- Church users: never see other churches’ data or nav
- Church switcher only if a user truly belongs to multiple churches (v1: one church per user is fine)

## Authorization UX

- Roles are **labels a church invents**; UI copies talk about **permissions**
- Ship role templates (Member, Event editor, Team lead, Church admin) as clones, not hard locks
- Disabled module ⇒ remove nav + fields; no dead grey menu items
- Activity types may require a module (e.g. song line needs Songs) — hide those types when module off

## Tenancy UX

- Assume `church_id` isolation everywhere
- Errors for cross-church access: generic not found / forbidden, no data leak
- Seeded demo church is fine; production onboarding = create church → invite admin

## Print & notify

- PDF / spreadsheet / ICS are first-class on event and “my tasks”
- Notify flows: who gets what, preview, don’t bury under settings

## Visual tone

- Calm, readable, church-ops — not flashy marketing
- Prefer teal/slate operational palette already started on the Filament panel; avoid purple-on-white clichés

## Reference (legacy WorshipHHN)

When unsure what a screen must do, check the Yii app behaviors for: event activities, team players/tasks, user blocked dates, song import, notifications — then simplify, don’t clone every quirk.
