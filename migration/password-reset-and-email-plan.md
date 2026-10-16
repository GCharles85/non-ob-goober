# Password reset + email collection + contact-form wiring

Status: **PLANNED (not started)** — drafted 2026-10-16. No code changed yet. Decisions below are confirmed.

## Why / context
- Users should be able to **reset a forgotten password** using their **username or email**.
- That requires **collecting emails**, which are **optional** but needed to reset later. Tell users this at signup.
- Also: **wire the contact form** so the email to `gooberboks@gmail.com` includes the submitting user's
  email **when they provided one**, and set it as `Reply-To` so replies go straight to them.

## Key finding — no DB migration needed
- The `Users` table **already has an `Email` column** (mapped in `User.cs` as `Email string?`; login reads
  the whole row in prod, so the column exists). It's just null for everyone today. So email collection needs
  **no schema change**.
- Password-reset tokens will be **stateless** (signed, no table) — see below — so **no `password_resets`
  table** and again no migration.

## Where contact-form emails come from today (answer to a Q, for the record)
`EmailService` authenticates to Gmail SMTP as `gooberboks@gmail.com`, so each message is From/To
`gooberboks@gmail.com`, identifying the sender only by **username**. No user email is included yet (not
collected). It only sends once `Smtp__Password` (a Gmail **app password**) is set; without it `/feedback`
returns 502. (An app password is a 16-char Google-issued password for one app, usable only with 2FA on —
stored as the secret `Smtp__Password`, used by MailKit to log in to Gmail SMTP.)

## Confirmed decisions
- **Profile email editing: YES** — add a `PATCH /auth/email` endpoint + profile UI so existing accounts
  (and new ones) can set/change/clear their email and thereby enable reset.
- **Email uniqueness: YES** — enforce unique (case-insensitive) email when provided, so "reset by email"
  is unambiguous. Registration/patch reject an email already in use.
- **Reset token lifetime: 10 minutes.**
- **No auto-login after reset** — on success, send the user to the **login page** and show a **toast**
  ("Password reset — please log in"). 
- **Verify the reset actually took** — after building, confirm end-to-end that the new password works and
  the old one fails (smoke test against the local stack).

## Plan

### Part 1 — Collect email (optional) at signup + manage it on the profile
- **Register form:** optional email input + helper text: *"Optional — we'll only use it to reset your
  password. Without it you can't recover your account."* Basic client-side format check.
- **`Register` (API):** accept optional email; if present, validate format, lowercase-normalize, enforce
  uniqueness; store it. (Password policy already enforced here.)
- **`PATCH /auth/email` (API, `[Authorize]`):** set/change/clear the current user's email (validate +
  unique). **Profile UI:** show current email (or "none") with an edit control.

### Part 2 — Contact form includes the sender's email
- `FeedbackController` looks up the user's email; when present, add it to the body **and** set the message
  `Reply-To` to it. When absent, body notes "no email on file." (Still From/To `gooberboks@gmail.com`.)

### Part 3 — Password reset (by username or email)
- **Login screen:** "Forgot password?" link → small form taking **username or email** → `POST /auth/forgot`.
- **`POST /auth/forgot`:** find the account by username or email; if it exists **and** has an email, email a
  reset link via `EmailService`. **Always return the same generic response** (no account enumeration).
- **Reset link:** `https://gooberboks.com/reset?token=…` → new SPA route **`/reset`** with a new-password
  form (reuse the live strong-password checklist) → `POST /auth/reset { token, newPassword }`.
- **`POST /auth/reset`:** verify token (signature + 10-min expiry + hash-binding), enforce the password
  policy, update the hash. **No auto-login** — respond success; the SPA routes to `/login` with a success
  toast.
- **Token = stateless, no table:** a signed token (10-min expiry) whose signature is **bound to the user's
  current password hash**, so it's **single-use automatically** (changing the password invalidates any
  outstanding token). Reuses the JWT secret with a distinct "reset" purpose — no new secret.

### Security baked in
No account enumeration (uniform `forgot` response); tokens signed + short-lived + single-use; new password
must pass the policy; emails validated, normalized, unique.

## Dependencies / notes
- **The `/reset` page needs the client-side router**, which is built + committed but **not live in prod**
  (prod is on the rolled-back pre-router build). Reset can't work in prod until the router build deploys —
  it rides the same next deploy.
- Reuses `EmailService` (Gmail SMTP) — so the reset emails need `Smtp__Password` set too.
- Spans **both repos** (API + web). Per [[gooberbox-commit-workflow]], commit per-feature only when the
  user says, and don't push until prod reflects the changes.
