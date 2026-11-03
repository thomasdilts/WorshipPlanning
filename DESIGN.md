---
name: WorshipPlanning
description: Calm church-ops service planner — editorial workspace, deep teal on data
colors:
  ground: "#ffffff"
  surface: "#f9f9f9"
  chrome: "#f7f6f2"
  raised: "#ffffff"
  border: "#eaeaea"
  rule: "#f3f3f3"
  control: "#d9d9d6"
  text: "#1f201d"
  muted: "#636361"
  accent: "#1b6e66"
  accent-deep: "#155e57"
  accent-soft: "#e8f5f3"
  positive: "#2e7d32"
  negative: "#b42318"
  warning: "#92400e"
typography:
  sans:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.45
  display:
    fontFamily: "Instrument Serif, Georgia, serif"
    fontSize: "1.7rem"
    fontWeight: 400
    lineHeight: 1.2
  section:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 600
    lineHeight: 1.3
rounded:
  control: "10px"
  card: "16px"
  pill: "999px"
spacing:
  gap: "16px"
  pad: "20px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "#ffffff"
    rounded: "{rounded.control}"
    padding: "0.65rem 1rem"
  button-primary-active:
    backgroundColor: "{colors.accent-deep}"
    textColor: "#ffffff"
    rounded: "{rounded.control}"
    padding: "0.65rem 1rem"
---

# Design system

## Overview

**Editorial greeting** (Taste app sheet, seed 1176345405): Operate-mode Filament shell with a warm chrome ground, white content sheet, deep teal accent used sparingly on primary actions and status-ok data. Product face is the service-order timeline. Persuade surfaces (login aside, `/partner`) use Instrument Serif for the title only.

## Colors

- Structure is tonal: white / `#f9f9f9` / `#f7f6f2` chrome — not colourful chrome.
- Accent `#1b6e66` under ~2% of pixels; never rainbow nav or KPI icon tiles.
- Status only: positive / negative / warning tokens for conflicts and task health.

## Typography

- **Instrument Sans** 400/500/600 for all UI.
- **Instrument Serif** for login/partner H1 only.
- Body 14px; section labels 14/600; sentence case; tabular nums on tables.

## Layout

- Filament **top navigation** (no sidebar). Content denser than marketing; one primary job per screen.
- Tables: no zebra; alignment and 1px rules; mobile horizontal scroll for service order / spreadsheet.

## Elevation & Depth

- No resting shadows. Separation = 1px border or one tone step.
- Dark mode: keep logo on soft accent-soft plate.

## Shapes

- Controls ~10px radius; cards ~16px; pills full.

## Components

- Primary button: accent fill, 180ms background / active `scale(0.97)`.
- Nav active: neutral text weight / subtle surface, not saturated pill.
- Empty states: one heading + one sentence + one CTA.

## Do's and Don'ts

**Do:** church logo as brand mark; accent on primary actions and conflict/ok state; Instrument Sans everywhere in panel.

**Don't:** purple gradients, glass, emoji greetings, KPI tiles with icon circles, zebra tables, decorative motion on reorder/keyboard paths.
