# Auth — current state & migration (do this first)

Everything else depends on a logged-in user being recognized by **both** the legacy PHP stack and
the new C#/React stack during coexistence. That shared mechanism is the **auth bridge**.

## Why "auth bridge"?
During the transition there are two runtimes serving the same users. The "bridge" is the shared
identity mechanism that lets a user who authenticated in one stack be trusted by the other — it
bridges identity across the two systems. Once PHP is gone, it's just "auth" (no bridge needed).

## Current legacy auth (what exists today)
- **PHP sessions via `PHPSESSID`.** On `session_start()`, PHP sets a `PHPSESSID` cookie (an opaque
  random id) and stores the actual session data **server-side** (default: files on the EB instance).
  The browser sends `PHPSESSID` on every request; PHP loads the matching server-side record into
  `$_SESSION`.
- **Only one thing is stored:** `$_SESSION['username']` (a string), set at login/register.
- **Login flow:** `user/login.php` → `LoginController->handleLogin` → `UserModel->login`:
  `SELECT PasswordHash FROM Users WHERE username = ? AND role = 'user'`, then
  `password_verify($password, $hash)`. On success → `$_SESSION['username'] = $username`.
- **Passwords:** hashed with PHP `password_hash($pw, PASSWORD_DEFAULT)` → **bcrypt** (`$2y$...`).
- **Authorization:** "logged in" = `isset($_SESSION['username'])`. "admin" = `$_SESSION['username'] === ADMIN`
  (a hardcoded username constant). Ownership = `uploaded_by === $_SESSION['username']`.
- **Logout:** `session_unset()` + `session_destroy()`.

### Weaknesses to fix during migration (not blockers)
- No `session_regenerate_id()` on login → session-fixation risk.
- Admin is a hardcoded username, not a role/claim.
- Some endpoints don't gate on login (e.g., `post_comment` inserts a null username when logged out).
- Server-side file sessions aren't shared across stacks or horizontally scalable.

## Target auth (new stack)
- **C# is the identity authority.** On login it verifies the password and issues a **JWT**
  (short-lived access token, e.g. 15 min) + a **refresh token** (longer-lived, revocable).
- Claims in the JWT: `sub`/`username`, `role` (promote admin to a real claim), `exp`.
- **Store the token in an `HttpOnly`, `Secure`, `SameSite` cookie** (not localStorage) to reduce XSS
  token theft. React calls the API with `credentials: include`.
- **Password compatibility is critical:** existing hashes are bcrypt (`$2y$`). Use **BCrypt.Net** in
  C# to verify them, so current users log in with **no password reset**. New hashes also bcrypt.

## The auth bridge (coexistence options)
Both stacks must accept the same login during the transition. Options, simplest→cleanest:

1. **C# issues a JWT in a cookie; PHP validates that JWT.**
   PHP reads the cookie and verifies the signature (shared secret) to populate `$_SESSION['username']`
   from the token claim. One authority (C#), both trust it. **Recommended.**
2. **Shared session store.** Move PHP sessions into a store C# can also read (DB table or Redis).
   More moving parts; only worth it if we keep PHP sessions long.
3. **PHP stays authority, hands C# a token.** Reverse of #1; keeps legacy login UI longer but leaves
   the authority in the code we're trying to retire.

**Decision:** go with **#1** (recorded in [decisions.md](decisions.md), ADR-004). Build the C# auth
endpoints + JWT, migrate the login/register UI to React early, and add a small PHP shim that trusts
the JWT cookie so un-migrated PHP pages still see a logged-in user.

## Auth-first work plan
1. C# `AuthController`: `POST /api/auth/login`, `/register`, `/logout`, `/refresh`; verify bcrypt
   against `Users.PasswordHash`; issue JWT (access+refresh) in HttpOnly cookies.
2. Promote admin to a `role` claim (keep the hardcoded username working as a fallback initially).
3. Add `session_regenerate_id()`-equivalent freshness + token rotation.
4. PHP shim: on each request, if the JWT cookie is present and valid, set `$_SESSION['username']`
   from its claim (so `api/*.php` and `user/*.php` keep working unchanged during coexistence).
5. Route `/api/auth/*` to C# at the edge; migrate `login`/`logout` pages to React.
6. Parity test: log in via React → hit a still-PHP page → confirm it sees the user.

## Open questions
- Cookie domain/same-site across the (single) domain behind the ALB — should be straightforward since
  everything is one origin.
- Refresh-token storage/revocation table in MySQL (shared) vs. stateless short tokens only.
- Do we need "remember me" / token lifetimes beyond current 24h session cookie?
