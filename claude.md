# Family Events: webtrees custom module

Shared guidance (server paths, deployment, permissions) lives in the root `claude.md`. This file covers this module only.

Upcoming birthdays, wedding anniversaries and death anniversaries, filtered **per event type** by how closely each person is related to the viewer, plus explicitly named people. Installed at `modules_v4/family-events/` (internal name `_family-events_`). Target: webtrees 2.2.x (also checked against 2.3 APIs).

## Layout

```
module.php                 # Single-file module (anonymous class), ModuleBlockInterface
resources/views/
  block.phtml              # The events table
  config.phtml             # Block settings form (one fieldset per event type)
  empty.phtml              # "Nothing to show" message
```

## How it works

- It is a **block**, usable on My page (per-user settings) and the tree home page. All settings are block settings (`block_setting` table), so each block instance has its own filters.
- **Root person**: the block's "Relative to" setting, or else the viewer's linked record (`UserInterface::PREF_TREE_ACCOUNT_XREF`, "gedcomid").
- **Events**: `CalendarService::getEventsList()` with `only_living = false`; the module applies its own per-category living filter.
- **Relationship search** (`findBloodRelatives`): walk up to ancestors within N generations, then down from each ancestor. Each relative gets a pair `[up, down]` (generations up to the common ancestor, then down). Degree = `max(up, down)`. Parent [1,0], sibling [1,1], 1st cousin [2,2], 3rd cousin [4,4].
- Traversal uses `Auth::PRIV_HIDE` so private links do not break the chain. Output is still privacy-safe because the facts come from `getEventsList()`, which only returns what the viewer may see.
- **Spouses** (`findSpousesOfRelatives`): partners of blood relatives inherit the relative's pair. Optional per category.
- **Couples** (MARR): shown if either partner qualifies.

## Scopes (degree limit)

| Setting | Degree | Includes |
|---|---|---|
| Immediate family | 1 | parents, siblings, children |
| Close family | 2 | + grandparents, grandchildren, aunts/uncles, nieces/nephews, 1st cousins |
| Up to 2nd cousins | 3 | + great-grandparents, 1st cousins once removed, 2nd cousins |
| Up to 3rd cousins | 4 | + 2nd cousins once removed, 3rd cousins |
| Up to 4th cousins | 5 | |
| Only named people | n/a | only the "Always include" list |
| Everyone in the tree | all | everyone (non-relatives labelled "Other") |

## Settings keys

Global: `days`, `today`, `root`. Per category (`birt`, `marr`, `deat`): `{key}_scope`, `{key}_spouses`, `{key}_living`, `{key}_named` (comma-separated xrefs).

To add a category (e.g. burials), add it to `CATEGORIES`, `DEFAULTS` and `categoryTitle()`.

## Not implemented / ideas

- No caching of the relationship search; fine for typical trees, may be slow on very large ones at "4th cousins".
- Relationship labels are English-only and gender-neutral ("Aunt/uncle").
- No "exclude these people" list.
- Not related by blood or marriage (e.g. step-children via a spouse's earlier marriage) are only reached through the spouse option or "named".
