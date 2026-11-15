# JT Property — Brand Guidelines

> Official logo files were not supplied. Placeholder SVGs in `public/branding/` use this palette.
> Replace them with the real assets (same file names) and re-check colours against the logo.

## Personality & voice
- Trustworthy, calm, practical. Audience: Filipino landlords and staff, often non-technical.
- Plain English, short sentences, peso amounts always explicit (`₱1,234.56`).
- Say what will happen before money is posted ("This will create 12 bills. Nothing is sent yet.").
- Avoid jargon: "Balance brought forward", not "AR carryover". Never "Oops"/cute errors on money screens.

## Colour
| Role | Light | Dark | Use |
|---|---|---|---|
| Brand navy | `#064578` | `#7FB3E0` (text) | identity, title bars, PDF header |
| Primary teal | `#007A69` | `#24C5A4` | primary actions (Record Payment, Generate) |
| Mint accent | `#24C5A4` | `#24C5A4` | highlights, focus ring, success chips |
| Canvas | `#F5F9FC` | `#101D2D` | page background |
| Surface | `#FFFFFF` | `#16263A` | cards, tables |
| Text | `#0F1E2E` (slate-900ish) | `#E6EDF5` | body |
| Muted text | `#52637A` | `#94A6BC` | helper text |
| Border | `#DCE5EE` | `#26384F` | soft borders |
| Warning (due soon) | `#975A16` | `#E0A84A` | amber chips |
| Danger (overdue) | `#C2362F` | `#F0716A` | overdue balances, void |

Rules: no saturated gradients behind text; dark-mode cards stay muted (surface `#16263A`, never bright); all text ≥ 4.5:1.

## Typography
- UI: **Instrument Sans** (starter kit default, readable, humanist). Base 15–16px, never below 13px.
- Numbers in tables/statements: `font-variant-numeric: tabular-nums`, right-aligned.
- PDF: same family with a Noto Sans fallback for ñ/á and ₱.

## Logo usage
- Horizontal logo: login, app header, PDF statement header, printable reports.
- Square icon: sidebar collapsed / app icon. Favicon: `.ico` + `.svg`.
- Clear space = height of the "J"; never stretch, recolour or place on busy photos.
