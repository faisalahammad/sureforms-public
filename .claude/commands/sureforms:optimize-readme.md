# SureForms readme.txt search-ranking optimizer

Optimize the three readme.txt header fields that WordPress.org search actually scores — the
plugin **title**, the **short description**, and the **Tags** line — so SureForms ranks better for
its target keywords, without touching anything release-managed and without breaking WordPress.org
guidelines.

This command **only rewrites copy**. It never bumps a version, never edits `Stable tag`, and never
invents facts.

## Arguments

Parse from: `$ARGUMENTS`

- `keywords` — optional, comma-separated. The search terms to optimize for, most important first.
  Default (SureForms' live priorities): `form, contact form, form builder, payment form, survey`.
- `--apply` — optional. Write the changes to `readme.txt`. Without it, run in **dry-run**: print the
  proposed header and the reasoning, change nothing.

Default is dry-run. The title is the plugin's public display name, so always show the before/after
and get a "yes" from the user before writing it, even with `--apply`.

## Working directory

Run from the sureforms plugin root — the directory containing `sureforms.php` and `readme.txt`.

---

## How WordPress.org scores a search result (the facts this command relies on)

Source of truth — read it if a rule below is ever in doubt:
`https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/plugin-directory/class-plugin-search.php`

Final score = **where the term matches** × **six quality signals**.

**Where the term matches (per-field weight):**

| Field | Weight | Editable here? |
|---|---|---|
| Plugin name (a whole word) **or** slug | 5 | Title: yes. Slug (`sureforms`): **no — immutable** |
| Author name | 3 | No |
| Term as a substring inside a title word (`form` in `SureForms`) | 2 | Indirect |
| Short description **or** Tags | 2 | **Yes — this is the main lever** |
| Anywhere else in the readme (description, FAQ, features) | 0.1 | Not worth optimizing |

**The six quality signals (multipliers) — none live in these three fields:**
recent-update recency, `Tested up to` freshness, active installs (with a penalty under 1M),
percent of support threads resolved, and star-rating average. This command does **not** move them —
it only notes if `Tested up to` looks stale so a human can act.

**The two consequences that drive every rewrite below:**

1. The slug is `sureforms` (one word), so a search for `form` never gets the 5-point whole-word name
   credit. The closest recoverable credit is a **standalone** high-value word in the *title*
   (`Contact Form Builder` → `form` is its own word), which beats the 2-point substring credit from
   `SureForms` alone.
2. Score is **concentrated** across the words in a field. A shorter title and a tight short
   description give each keyword a larger share. Padding words dilute every keyword in the field.

---

## Hard constraints — never cross these

These protect the listing from being flagged or rejected. A rewrite that violates any of them is
worse than no rewrite.

- **Short description: 150 characters max.** WordPress.org truncates past that. Count it.
- **Tags: only the first 5 are indexed.** Extra tags are dead weight — never list more than 5.
- **No competitor trademarks as keywords.** Never add `wpforms`, `gravity forms`, `ninja forms`,
  `contact form 7`, `elementor`, `jetpack`, etc. to the title, tags, or short description. This is a
  direct WordPress.org guideline violation and risks removal.
- **No keyword stuffing.** Repeating a keyword doesn't stack — the field is scored once per term.
  `Form Builder, Form Maker, Form Creator, Forms` is stuffing; write natural phrases a human reads.
- **Only claim real features.** Every keyword must map to a shipped capability. Survey/Quiz/Calculator
  are Business-tier — keep them phrased the way the current short description does (e.g. "in SureForms
  Business") so the claim stays honest. Never add a keyword for a feature SureForms doesn't have.
- **Never edit** `Stable tag`, `Requires at least`, `Requires PHP`, `Contributors`, `License`, or the
  `Plugin Name:` header in `sureforms.php`. If `Tested up to` is behind the current WordPress
  release, only *report* it — do not change it (it must reflect a real tested version).
- **Keep the `=== ... ===` and header syntax intact** — WordPress.org's parser is strict.

---

## Steps

### 1 — Read the current header

Read the top block of `readme.txt` (through the short-description line). Capture the current title,
short description (with its character count), and tags.

### 2 — Score the current fields

For each target keyword, note where it currently lands (whole word in title = 5, substring = 2, in
short description / tags = 2, or absent). List the gaps — a target keyword that only appears at 0.1
weight, or not at all, is the opportunity.

### 3 — Propose the rewrite

Produce candidate values for the three fields, applying the two consequences above:

- **Title** — front-load the highest-value keywords as **standalone words**, drop filler, keep it
  reading like a real product name. Aim for ≤ ~9 words. Example shape:
  `SureForms – Contact Form Builder, Payment Form, Survey, Quiz & Calculator`.
- **Short description** — one natural sentence, ≤ 150 chars, carrying the top 2–3 keywords that the
  title couldn't. Report the exact character count.
- **Tags** — exactly the 5 highest-value distinct keywords, no overlap-for-overlap's-sake, no
  trademarks.

For **each** field, give a one-line rationale tied to the scoring model (which keyword moved from
which weight to which, or why a word was cut).

### 4 — Show before/after

Print a compact before → after for all three fields, the character count for the short description,
and any constraint check that mattered (e.g. "tags trimmed to 5", "short desc = 143/150"). If
`Tested up to` looks stale versus the current WordPress release, note it here as a separate
recommendation — do not fold it into the edit.

### 5 — Apply (only with `--apply` and after title confirmation)

On confirmation, use `Edit` to replace exactly the title line, the short-description line, and the
`Tags:` line in `readme.txt`. Change nothing else. Re-read the three lines and confirm the header
still parses (syntax intact, short desc ≤ 150, ≤ 5 tags).

### 6 — Report

Summarize what changed and what was deliberately left alone (versions, quality signals). Remind the
user that title/short-description/tags are also worth mirroring wherever the marketing site controls
the same listing, and that the quality-signal wins (resolving support threads, crossing 1M installs,
keeping `Tested up to` current) live outside this file.

---

## Notes

- Dry-run first. Only pass `--apply` once the copy reads well to a human — search reads it, but so do
  buyers.
- This is intentionally a copy tool. If a rewrite ever tempts you to change a version or a quality
  signal to "help the score", stop — that's out of scope and, for `Tested up to`, dishonest.
