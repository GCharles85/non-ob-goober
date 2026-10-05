# Architecture decisions (ADR log)

Short records of decisions and why. Newest at the bottom. Status: Proposed | Accepted | Superseded.

---

## ADR-001 — Strangler fig migration (not a rewrite)
**Status:** Accepted
**Context:** Legacy PHP monolith on EB; moving to React + C#. A big-bang rewrite is high-risk.
**Decision:** Migrate incrementally behind an edge facade, path by path / feature by feature, with
both stacks live until the last path moves.
**Consequences:** Longer coexistence window; needs shared DB + shared auth; lower risk per step.

## ADR-002 — Edge routing via AWS ALB path rules
**Status:** Accepted
**Context:** Need a facade that routes each URL path to legacy PHP or the new C# backend.
**Decision:** Use **ALB path-based listener rules**. Default → the existing PHP (EB) target group;
specific migrated paths → a new C# target group.
**Consequences:**
- No separate proxy *server* to run/patch (the ALB is managed) — simplest operationally.
- **But the C# app still needs compute to run** (a target group backing it): a second Beanstalk
  env, or ECS/Fargate, or EC2. "No new server" applies only to the proxy layer, not the backend.
- Both target groups must sit behind the **same ALB** (the EB env already has one — add rules there),
  or stand up a new ALB in front of both. Confirm topology before first cutover.
- ALB **does** support canary weighting (weighted target groups) and client-facing redirects.
  Its real limitation vs YARP/nginx: it **cannot rewrite the request path/headers** sent to the target
  (it forwards the original path as-is). Implication: the C# backend must serve the **same paths** as
  PHP — including the legacy `.php` suffixes, e.g. `/api/search_users.php` — or we add a small rewrite
  layer (YARP/nginx) if we want clean routes like `/api/users/search`. Decide this before cutover.

## ADR-003 — Shared MySQL during coexistence
**Status:** Accepted
**Context:** Migrated and un-migrated features must stay consistent.
**Decision:** There is **one** database (existing RDS MySQL). Both PHP and C# read/write the same
tables. The DB is the integration point — the two stacks do **not** call each other.
**Consequences:** Freeze/coordinate schema changes during coexistence; avoid breaking either side.
C# owns the schema once PHP is retired. Mind the table-name casing quirk (see inventory.md).

## ADR-004 — Auth: C#-issued JWT, bcrypt-compatible, auth-first
**Status:** Accepted
**Context:** Legacy uses PHP server-side sessions with only `$_SESSION['username']`; passwords are
bcrypt via `password_hash(PASSWORD_DEFAULT)`. Both stacks must recognize the same user.
**Decision:**
- C# is the identity authority; issues JWT (access + refresh) in **HttpOnly/Secure cookies**.
- Verify existing **bcrypt** hashes with BCrypt.Net → **no password resets** for current users.
- **Auth bridge:** a small PHP shim validates the JWT cookie and sets `$_SESSION['username']`, so
  un-migrated PHP keeps working. (Bridge option #1 in auth.md.)
- Promote admin from hardcoded username to a `role` claim (keep username fallback initially).
- Do auth **before** any feature slice.
**Consequences:** Single identity authority early; one shared secret between C# and the PHP shim;
refresh-token revocation needs a small shared table (or accept short stateless tokens).

## ADR-006 — YARP as the strangler facade (behind the ALB)
**Status:** Accepted
**Context:** ALB alone can't rewrite request paths/headers (ADR-002). We want clean C# routes
(`/api/users/search`) not legacy `.php` paths, plus canary weighting and one unified local entry.
**Decision:** Use **YARP** (ASP.NET Core reverse proxy) as the facade. In prod the ALB still fronts
it (TLS, DNS, health) and forwards to the YARP service; YARP routes by path to the C# API, the legacy
PHP (EB), and the React build, with path rewrites + transforms. Chosen over nginx because it's
.NET-native (same toolchain as the backend) and unifies local dev.
**Consequences:** YARP needs compute behind the ALB (EB .NET env or ECS/Fargate). Routes/clusters
live in `GooberBox.Gateway/appsettings.json` — no rebuild to re-route. Proven locally: `/api/health`
→ C# API; catch-all → PHP.

## ADR-005 — AI video pipeline becomes a queue + worker (future)
**Status:** Proposed
**Context:** Current pipeline spawns a background PHP process (`exec(... &)`), uses ffmpeg + 3 external
APIs, and is the most complex/coupled part.
**Decision (proposed):** Reimplement as an HTTP endpoint that enqueues a job (e.g., SQS) consumed by a
C# worker/hosted service that runs the pipeline and writes `items` + uploads to S3.
**Consequences:** Proper async, retries, observability; migrate last once the platform is proven.
