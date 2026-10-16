# Content moderation (dirty-input filter)

Status: **BUILT + verified locally 2026-10-16 — not committed, not deployed.** Both projects build
clean. Verified via curl against the local stack: foul input → `400 {reason:"language"}` on comments,
replies, feedback, and generate (before any API spend); clean input passes; obfuscation caught
(`f.u.c.k`, `sh1t`, `FUUUCK`); and "assess the class in Scunthorpe" is NOT flagged (no false positive).

Implemented as described below: `TextModerationService` + `BannedWords` (api/Services), registered as a
singleton, called from `ItemsController.AddComment`, `CommentsController.AddReply`, `FeedbackController`,
and `GenerateController.Video` (prompt + narration + music), returning `400 { error, reason: "language" }`.
Frontend surfaces the warning inline on the comment and reply forms (`.moderation-warning`), the feedback
dialog (existing error line), and the generate form (existing error state; `generate()` now handles the
pre-flight 400 instead of polling a job that never started). Private messages untouched.

## Goal
Screen user text that is shown publicly or sent to an external API for foul language. On a hit at submit
time, **block the action and warn** the user so they can revise. Private messages are deliberately out of
scope.

## Confirmed decisions
- **Behavior: block & warn.** The comment/feedback/generation is rejected (not saved, external API not
  called) and the server returns a clear warning; the user edits and resubmits.
- **Engine: local wordlist only.** Fully in-process, no network call, nothing extra sent anywhere.
- **Scope: lexical toxicity** — profanity, slurs, and explicit sexual/violent *words*. **Known, accepted
  gap:** context-based harassment and threats (e.g. "I know where you live" — no bad words) are NOT
  detected by a wordlist. Revisit with the OpenAI Moderation API later if that gap matters (see Future).

## Surfaces (where it applies)
Enforced **server-side, on submit**, via one shared service so the rule is identical everywhere:
- **Comments** — `ItemsController.AddComment`
- **Replies** — the reply POST in `CommentsController`
- **Feedback form** — `FeedbackController.Send`
- **Generate prompts** — `GenerateController` video endpoint: the prompt **and** the optional
  `narrationPrompt` / `musicPrompt` (they hit OpenAI/ElevenLabs/Stability and the output is public).
  Checked up front so a dirty prompt never spends an external API call.

**Excluded:** private messages (`MessagesController`). **Not included (open to add later):** usernames at
signup — they're public, but left out for now per discussion.

## How it works
A shared `TextModerationService` with a single `Check(text)` that returns clean / flagged (+ the matched
term for logging, never shown to the user).

- **Normalize before matching**, to defeat trivial evasion: lowercase; collapse repeated letters
  (`fuuuck` → `fuck`); map common leetspeak (`0→o, 1→i/l, 3→e, 4→a, @→a, $→s`); strip punctuation/spacing
  between letters (`f.u.c.k`, `f u c k`).
- **Word-boundary matching** against a curated list to limit false positives (the "Scunthorpe" problem —
  don't flag `class`, `assistant`, `Scunthorpe`, etc.).
- **Wordlist lives in an editable resource file** shipped with the API (categorized: profanity / slurs /
  sexual / violent), not hard-coded in a controller, so it can be tuned without touching logic.

## Response contract
On a hit: **HTTP 400** with `{ error: "<friendly warning>", reason: "language" }`. The warning is generic
and actionable, e.g. *"That contains language we don't allow here — please edit and try again."* (never
echoes the matched word).

Frontend shows the warning inline on each surface:
- Comments / replies → under the input in the comments/replies panel.
- Feedback dialog → the existing error line.
- Generate form → the existing error state, before the job starts.

The server is the enforcement boundary. A frontend pre-check mirror (instant feedback before send) is a
possible later nicety, not part of this.

## Tradeoffs (accepted)
- **False positives** — reduced by normalization + word boundaries + a curated list; never fully gone.
- **English-first** — the list is English; other languages largely pass through.
- **Evasion** — any blocklist can be worked around; this is a deterrent, not a guarantee.
- **No contextual detection** — harassment/threats without flagged words slip through (the accepted gap).

## Future (not now)
Add the **OpenAI Moderation API** (free; key already present; generate already uses OpenAI downstream) as
a second pass for the contextual categories (hate / harassment / threats / sexual / violence), upgrading to
the "hybrid" engine **without changing the surface list or the 400 response contract** — so it's a drop-in
later if the gap becomes a problem.

## Process notes
Per [[gooberbox-commit-workflow]]: build when the user says go, commit per-feature only on their say-so,
don't push until prod reflects it. Spans `gooberbox-api` (the service + endpoint hooks) and
`gooberbox-web` (showing the warnings).
