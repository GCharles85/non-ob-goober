# SEO + client-side router plan (gooberbox-web)

Status: **Phase 1 + 2 DONE locally (not deployed)** — drafted 2026-10-15; phases 1–2 built
2026-10-15. Phase 3 (host redirect, owner console work) and phase 4 (prerender) still to do.

## Why
Google Search Console reported the site isn't being indexed:
- **"Duplicate without user-selected canonical"** — every URL serves the *identical*
  `index.html` (the SPA fallback 403/404 → `/index.html`), and the content is drawn
  client-side, so the raw HTML Google first sees is the same empty shell with the same
  `<title>`/meta on every path. apex (`gooberboks.com`) and `www` also serve the same
  content. Google sees one page of content wearing many URLs, with no canonical hint, and
  skips indexing the duplicates.
- **"Page with redirect"** — Google crawled URLs that bounce elsewhere (the `http://`→`https://`
  viewer redirect on CloudFront, possibly `www`↔apex). Benign on its own; it just won't
  index the redirecting URL.

Separately, the app has **no URL routing** — navigation is in-memory React state
(`const [view, setView] = useState<View>('community')`), so deep links don't work, refresh
loses your place, and Back/Forward leave the app. The router fixes that UX gap and is the
foundation the SEO work sits on.

## Current state (as of 2026-10-15)
- `D:\gooberbox-web`: Vite 8 + React 19, TS. Single component tree in `src/App.tsx`.
- Views (all in `App.tsx`): `community` (default, **public**), `login` (**public**),
  `upload`→Generate (**auth**), `messages` (**auth**), `profile` (**auth**).
- Shared state lives in `App` and is threaded down: `me`, generation status + polling
  (`genStatus`, `startPoll`, `genKey`), conversations + unread badge (`convos`, `unread`,
  `markRead`), `showToast`. Any router refactor must keep this state **above** the routed
  views so generation survives navigation (a layout route + outlet context / context provider).
- Entry: `src/main.tsx` renders `<App/>` in `<StrictMode>`. No `BrowserRouter` yet.
- `index.html`: single static `<title>GooberBox</title>`, one meta set.
- Deploy: `deploy.ps1` → `npm run build` → `s3 sync --delete` (immutable assets + no-cache
  `index.html`) → CloudFront invalidation. CloudFront `EX1RJIT2FI0QQ`
  (`d8whuzuuei4it.cloudfront.net`), SPA fallback 403/404 → `/index.html` already in place.
- **React 19 note:** React 19 natively hoists `<title>`, `<meta>`, and `<link>` rendered
  anywhere in the tree into `<head>`. So per-route metadata needs **no** `react-helmet` —
  each route component can render its own tags. (Prerendering, phase 4, is what bakes them
  into the served HTML for crawlers that don't run JS.)

## Phases

### Phase 1 — Add the router (foundation; pure UX win, no infra change) — ✅ DONE 2026-10-15
Implemented: `react-router-dom@7`; `BrowserRouter` in `main.tsx`; `App` is now a `<Routes>` table
with an `AppLayout` layout route holding the shared state (generation polling, convos/unread, toast)
above the `<Outlet>` (passed via `useOutletContext`); routes `/`, `/login`, `/generate`, `/messages`,
`/profile`, `*`→NotFound; `RequireAuth` gates the three auth routes (redirect to `/login?next=…`,
waits on a new `authLoading` flag to avoid a redirect flash); `Nav` uses `NavLink`; logout/delete
`navigate('/')`. Build + tsc + lint clean.

- Add `react-router-dom` (v7). Wrap the app in `<BrowserRouter>` in `main.tsx`.
- Route map:
  | Path | View | Access |
  |------|------|--------|
  | `/` | Community (feed) | public |
  | `/login` | Login | public |
  | `/generate` | Generate | auth |
  | `/messages` | Messages | auth |
  | `/profile` | Profile | auth |
  | `*` | NotFound (404 screen) | public |
- Restructure `App` into a **layout route**: it keeps all the shared state (`me`, generation,
  convos/unread, toast) and renders `<Nav/>` + `<Outlet/>`, passing shared state to children
  via `useOutletContext` (or a small React context). This preserves "generation keeps running
  when you navigate away."
- `Nav`: replace the `onNavigate(view)` anchors with `<NavLink>`; active styling comes from
  NavLink's `isActive` instead of `view === …`.
- **Auth gate:** a `<RequireAuth>` wrapper for `/generate`, `/messages`, `/profile`. If not
  logged in, redirect to `/login?next=<path>`; after a successful login, navigate to `next`
  (default `/`). Replaces today's per-page "Log in to…" messages for deep-linked access.
- **Avoid a redirect flash:** add an `authLoading` flag (true until `/api/auth/me` resolves);
  `RequireAuth` renders nothing/a spinner while loading, so a logged-in user deep-linking
  `/profile` isn't bounced to `/login` before the auth check finishes.
- Logout / delete-account currently `setView('community')` → becomes `navigate('/')`.
- **No infra change needed:** the existing SPA fallback already serves `index.html` for any
  path, so deep links, refresh, and Back/Forward start working the moment this ships.

### Phase 2 — Per-route metadata + canonical (SEO task #1 "unique titles" + #2 "canonical") — ✅ DONE 2026-10-15
Implemented: a `<Seo>` component (React 19 native `<title>`/`<meta>`/`<link>` hoisting, no helmet)
rendered by each route wrapper + NotFound. `CANONICAL_ORIGIN = 'https://gooberboks.com'` (one-line
flip). Public `/` and `/login` are indexable with distinct titles/descriptions + self-referential
canonical; `/generate`, `/messages`, `/profile`, and the 404 add `robots: noindex, nofollow`. The
static `<title>` was removed from `index.html` (a 2nd `<title>` would win by document order and pin
every route to one title) — the real titles are crawler-visible in the rendered DOM now, and phase 4
bakes them into static HTML for non-JS crawlers.

- Each route renders, via React 19 native head tags:
  - a distinct `<title>` (e.g. "GooberBox — Community", "Log in · GooberBox", …);
  - a distinct `<meta name="description">`;
  - `<link rel="canonical" href="https://gooberboks.com<path>">` built from the chosen
    canonical host + the current path.
- **Auth-gated routes get `<meta name="robots" content="noindex">`** — `/generate`,
  `/messages`, `/profile` are login-walls/thin to a crawler; telling Google not to index them
  removes them from the duplicate pile. Public `/` and `/login` stay indexable.
- This alone resolves the "duplicate without user-selected canonical" verdict for Googlebot's
  JS-rendered pass (Googlebot runs JS, just on a delay). Phase 4 makes it robust/instant for
  all crawlers.

### Phase 3 — Canonical host + redirect (SEO task #1 "page with redirect" / apex-vs-www) — ✅ DONE 2026-10-15
Verified live: `curl -sI https://www.gooberboks.com/community` → `301` +
`Location: https://gooberboks.com/community` (`X-Cache: FunctionGeneratedResponse`); apex → `200`.
Details below.
Decision made: canonical host = apex `gooberboks.com`; redirect `www`→apex. Function written to
`migration/cloudfront-www-to-apex.js`. Owner steps (CloudFront console, dist `EX1RJIT2FI0QQ`):
1. **Functions** → **Create function** → name `www-to-apex-redirect`, runtime **cloudfront-js-2.0**
   → paste `cloudfront-www-to-apex.js` → **Save changes** → **Publish**.
2. Distribution → **Behaviors** → **Default (`*`)** → **Edit** → **Function associations** →
   **Viewer request** = CloudFront Functions → `www-to-apex-redirect` → **Save**. (Default behavior
   is enough — the 301 fires on the document request before the SPA loads.)
3. No Namecheap change (both apex + www already point at the distribution; cert covers both).
4. Verify: `curl -sI https://www.gooberboks.com/community` → `301` + `location: https://gooberboks.com/community`;
   `curl -sI https://gooberboks.com/community` → `200`.

- **Decision needed (owner):** pick the canonical host. Recommend **apex `gooberboks.com`**
  (shorter, it's the brand); redirect `www.gooberboks.com` → apex.
- Implement the `www`→apex 301 with a **CloudFront Function** (viewer-request): if
  `Host == www.gooberboks.com`, return `301` to `https://gooberboks.com<uri>`. (CloudFront
  doesn't do host-based redirects on its own.) Owner adds this in the console, attaches to the
  default behavior.
- The `http`→`https` redirect is expected and fine — keep it; the canonical tag + host redirect
  are what collapse the duplicates.
- Owner work: CloudFront console (create + attach the Function). No Namecheap change (both
  hostnames already point at the distribution; the Function handles the redirect).

### Phase 4 — robots.txt + sitemap.xml (prerender deliberately SKIPPED) — ✅ DONE 2026-10-15
**Decision (owner, 2026-10-15): stop at robots + sitemap; no HTML prerender.** Rationale: the OAC
setup falls every path back to one `index.html`, so static per-route head tags would need distinct
per-route files + a second CloudFront rewrite function; and Googlebot renders JS, so it already sees
the per-route canonical/title/noindex from phase 2. Added `public/robots.txt` (permissive — auth
routes are de-indexed via `meta noindex`, NOT a Disallow, so Google can still crawl and see the
noindex) and `public/sitemap.xml` (apex `/` + `/login`). Submit the sitemap in Search Console after
deploy. If link-preview cards or non-JS SEO are ever wanted: add static Open Graph tags to
index.html (generic, same per route — no conflict with React's `<title>`), or do the full per-route
prerender. Original options kept below for reference.

- Goal: serve real, filled-in HTML (title/description/canonical baked in) for the public routes
  so crawlers that don't execute JS still get them — instead of the empty shell.
- Scope: prerender **only the public routes** (`/`, `/login`). Auth routes stay client-only
  (they're `noindex` anyway). The feed's *content* is dynamic/fetched client-side; prerendering
  gives a correct shell + metadata, which is what the GSC warnings are about — not indexing
  individual videos.
- Options (decision at that point): `vite-react-ssg` (integrates with the react-router config,
  emits static HTML per route at build, no server — best fit for S3/CloudFront), or a small
  build-time `renderToString` step. Avoid full SSR (would need a running server; we're static
  on S3/CloudFront).
- Add `public/robots.txt` (allow; point at sitemap) and a `public/sitemap.xml` listing the
  public URLs on the canonical host. Submit the sitemap in Search Console.
- `deploy.ps1` stays the same if SSG writes into `dist/` (it does); multiple HTML files get the
  `no-cache` treatment like `index.html` (adjust the sync excludes so each prerendered
  `*.html` is `no-cache`, assets stay immutable).

## Sequencing & deploy
- Build phases 1→2→3→4 in order (each depends on the prior). Verify locally with
  `npm run dev` + `npm run build` after each.
- **Deploy strategy:** bundle 1+2 (and 4 when ready) into **one** frontend deploy to minimize
  CloudFront invalidations; phase 3 is an independent console change by the owner.
- Phase 1 is a strict improvement and safe to ship alone if we want the UX win first.

## Open decisions (owner)
1. **Canonical host:** apex `gooberboks.com` (recommended) vs `www`.
2. **Prerender depth:** lightweight (shell + metadata on `/` and `/login`) — recommended — vs
   fuller SSG later. The GSC warnings are satisfied by the lightweight version.

## What this does NOT change
- The API, auth cookie, generation pipeline, or CloudFront `/api/*` behavior.
- The PHP rollback env (still deactivated/scaled-to-0).
