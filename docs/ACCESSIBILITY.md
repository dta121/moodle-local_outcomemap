# Accessibility conformance — local_outcomemap

Target standard: **WCAG 2.2 Level AA**. Last full audit: **6 August 2026** (release 0.8.9).

## Scope

Every user-facing surface the plugin renders:

- Learner: outcome progress page (`results.php`).
- Staff: dashboard, curriculum, outcomes hierarchy and alignment matrix,
  course instances, policies, snapshots list, snapshot evidence report,
  attainment report, coverage, content mapping, question mapping,
  approval queue, manual release, remediation, CSV import, report links.
- Exports (CSV/JSON) are data files and out of scope for page-level criteria.

Moodle core supplies the page chrome, navigation, and all forms (Moodle
Quickforms); those inherit Moodle's own WCAG accreditation and are not
re-audited here. The companion `qbank_outcomemap` renders through the core
question bank API (columns, filters, forms) and states every workflow status
in text, so it carries no plugin-specific accessible-name or colour issues.

## How the plugin meets the standard

- **Text alternatives (1.1.1).** Every attainment/progress bar is decorative:
  the same figure and threshold always appear as adjacent text, and the graphic
  is marked `aria-hidden`. Matrix cells carry screen-reader-only status text.
- **Info and relationships (1.3.1).** The alignment matrix is a real `<table>`
  with caption and row/column header scopes. The snapshot attainment grid,
  laid out with CSS, declares ARIA table semantics (`role="table"`, rows,
  column headers, cells, colspans). Disclosures are native
  `<details>`/`<summary>` or buttons with `aria-expanded`/`aria-controls`.
- **Use of colour (1.4.1).** No state is carried by colour alone: attainment
  bands are named in words beside each coloured rate, workflow badges are
  text, and the matrix status dots differ by shape (filled circle, diamond,
  hollow square, ring) as well as hue.
- **Contrast (1.4.3, 1.4.11).** The learner page derives its neutrals and band
  tones from the theme's own text colour via `color-mix`, keeping 4.5:1 on
  light, dark, and re-branded themes; staff pages use a fixed palette audited
  against white. Non-text indicators clear 3:1.
- **Resize and reflow (1.4.4, 1.4.10).** All type is rem-based and follows the
  browser font-size preference; layouts reflow to a single column on narrow
  viewports, and numeric grids scroll horizontally rather than collapse.
- **Keyboard (2.1.1, 2.4.3, 2.4.7).** Every action is reachable by keyboard;
  inline panels move focus in, close on Escape, and return focus to their
  trigger. `:focus-visible` outlines are set plugin-wide, with an
  `@supports`-guarded fallback for engines without `:has()`.
- **Target size (2.5.8).** Matrix cells and the learner filter hold 44 px
  minimum targets; small controls keep a 24 px floor.
- **Status messages (4.1.3).** Search and filter controls announce empty
  result states through polite live regions.
- **No motion.** The plugin declares no animations or transitions, so no
  reduced-motion variant is required.
- **Robust naming (4.1.2).** Screen-reader-only text uses both `sr-only`
  (Bootstrap 4 themes, Moodle ≤ 4.5) and `visually-hidden` (Bootstrap 5,
  Moodle ≥ 5.0).
- **Print.** Filtered lists print in full, disclosures print open, and
  control chrome is suppressed.

## Audit method

Expert review of every template, renderer, page script, and the stylesheet
against the WCAG 2.2 AA success criteria, verified by the PHPUnit rendering
suite (`tests/*_page_test.php` and report tests). Recommended recurring
checks: an assistive-technology pass (NVDA/VoiceOver) and an automated scan
(axe) on each release that changes a template.

## Findings history

- **0.8.9 (this audit):** decorative bars hidden from assistive technology;
  ARIA table semantics on the snapshot grid; band names in words beside
  colour-coded rates; the draft matrix dot made hollow (shapes themselves
  predate this audit); live region on the learner filter; `:has()` fallback;
  Bootstrap 5 hidden-text classes.
- **0.8.x (Milestone 7 hardening):** focus-visible outlines, target sizes,
  matrix table semantics, live regions, focus management on inline panels.
- **0.8.6–0.8.8:** learner and staff pages moved onto the site theme with a
  contrast-audited, user-scalable type scale.
