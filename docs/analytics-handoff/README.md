# Analytics Handoff Package

This folder contains the implementation package for the Resources Analytics initiative.

## Contents

1. `01-implementation-task-list.md`
   - Ordered build plan with phases, dependencies, and milestone outputs.
2. `02-technical-spec.md`
   - Developer-facing product and technical specification.
3. `03-developer-readiness-checklist.md`
   - Inputs/decisions/access needed before and during implementation.
4. `04-ticket-backlog.md`
   - Epic/story breakdown with acceptance criteria and definition of done.

## How To Use This Package

1. Start with `03-developer-readiness-checklist.md` and confirm open inputs.
2. Review and approve scope in `02-technical-spec.md`.
3. Use `04-ticket-backlog.md` to create project tickets.
4. Execute work in the order in `01-implementation-task-list.md`.

## Scope Summary

The analytics module covers:

- Search behavior and unmet-need signals.
- Snapshot/share behavior (`print`, `email`, `text`).
- Resource engagement behavior.
- Segment slicing (`staff`, `vincentian_volunteer`, `partner`).
- Geography slicing (shortcode `geography`, auto-discovered + manually managed).
- Dashboard exports (PDF, CSV, XLSX) for the active filter slice.

