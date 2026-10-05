# GooberBox migration

Migrating the legacy **PHP monolith** (`D:\html`, served on Elastic Beanstalk) to a
**React frontend + C# (ASP.NET Core) backend**, incrementally, using the **strangler fig**
pattern. No big-bang rewrite.

## Target architecture
- **Frontend:** React (Vite + TypeScript), SPA.
- **Backend:** ASP.NET Core Web API (C#), EF Core (Pomelo MySQL provider), AWS SDK for S3.
- **Database:** the existing MySQL on RDS — **shared** by both stacks during coexistence.
- **Edge/facade:** ALB path-based routing (see [decisions.md](decisions.md)). Routes each URL
  path to either the legacy PHP (current EB target group) or the new C# backend (new target group).
- **Async jobs:** the AI video pipeline becomes a queue + worker service (future phase), not `exec(&)`.

## Strategy: two independent axes
1. **Backend:** reimplement `api/*` endpoints in C#, one at a time, matching each JSON contract,
   then flip that path at the edge. The browser (legacy inline JS *or* React) keeps calling the same
   path — it doesn't care who answers.
2. **Frontend:** replace `user/*` server-rendered pages with React routes, feature by feature.

They intersect at the API contract. `api/*` is a clean seam (already JSON); `user/*` pages are the
coupled part (HTML + inline JS + inline DB queries) and get replaced whole, as React routes.

## How coexistence works
- Both PHP and C# connect to the **same database**. A row written by one is immediately visible to
  the other — that's the integration point, so the two stacks don't call each other.
- The **edge** decides, per path, which stack serves a request. Over time more paths move to C#.
- When every path is migrated, PHP/EB is retired and C# owns the schema outright.

## Order of work
1. **Auth first** — see [auth.md](auth.md). Everything else depends on a logged-in user being
   recognized by both stacks (the "auth bridge").
2. First slices: smallest, read-only, low-coupling `api/*` endpoints (see difficulty scores in
   [inventory.md](inventory.md)).
3. Writes, then whole-page React features.
4. Hard/last: AI video generation and messaging.
5. Decommission PHP.

## Docs in this folder
| File | What it is |
|------|------------|
| [inventory.md](inventory.md) | Every endpoint, page, table, dependency + migration difficulty |
| [auth.md](auth.md) | Current auth model + the JWT auth-bridge design (we do this first) |
| [contracts.md](contracts.md) | Request/response contract capture per endpoint (parity reference) |
| [decisions.md](decisions.md) | Architecture decision log (ADRs) |

## Status
Planning. Nothing migrated yet. Update the status column in [inventory.md](inventory.md) as slices move.

> Note: this folder is documentation only. Exclude `migration/` from the deploy bundle
> (`.ebignore`) so it isn't shipped to prod.
