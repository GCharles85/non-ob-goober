# API contract capture

Before porting any `api/*` endpoint to C#, capture its **exact** current contract here. Parity with
this contract is what lets us flip the edge route to C# without touching the frontend. If the C#
response shape, params, status codes, or auth differ, the untouched inline JS / React breaks silently.

Captured from the PHP source. Verify response bodies against the Network tab where marked.

## Template (copy per endpoint)
```
### <path>
- Method / Auth / Params / Success / Errors / Side effects / Consumers / Notes
```

---

## Captured

### GET /api/search_users.php  — difficulty 1 (first slice)  ✅ MIGRATED → C# `GET /users/search?term=`
- **Auth:** requires `$_SESSION['username']` → 401 if missing.
- **Params (query):** `term`: string, required, **min length 2**.
- **Success:** `200`, `application/json` — a **JSON array of username strings**, e.g. `["alice","bob"]`.
  Query: `SELECT Username FROM Users WHERE Username LIKE %term% AND Username != <me> AND role='user' LIMIT 10`.
- **Errors:** `400` `{"error":"Search term too short"}` (missing/short); `401` (not logged in);
  `500` `{"error":"Server error"}`.
- **Side effects:** none (read).
- **Consumers:** `JS/messages.js` (`searchUsers`).
- **Notes:** excludes the current user; only `role='user'`. Uses deprecated `FILTER_SANITIZE_STRING`
  (gone in PHP 8.1+ as a constant — port as plain trim/validate in C#).

### POST /api/toggle_like.php  — difficulty 2 (first write slice)  ✅ MIGRATED → C# `POST /items/{fileId}/like`
> C# version decides like/unlike from the **DB state** (is there a user_likes row?) instead of a client
> `isLiked` flag, so it can't desync. Same two-table mutation in a transaction; returns
> `{success, action:"liked"|"unliked", fileId, newLikeCount}`. The React feed uses it. The legacy
> `toggle_like.php` still exists for the PHP page until the edge route is flipped.
>
> **New (no legacy JSON equivalent):** `GET /items` — the community feed, replacing community.php's
> inline `SELECT Path, likes, uploaded_by FROM items ORDER BY likes DESC` + the user_likes lookup.
> Returns `[{fileId, path, likes, uploadedBy, likedByMe}]`; anonymous allowed (likedByMe=false logged out).
- **Auth:** requires login (reads `$_SESSION['username']`; currently no explicit 401 guard — add one in C#).
- **Content-Type:** `application/json`. Method must be POST (else `405`).
- **Body:** `{ "fileId": string (uploadId), "isLiked": bool }`.
  - ⚠️ **`isLiked` = the state BEFORE the toggle.** `isLiked=false` → **like** (insert); `isLiked=true` → **unlike** (delete). (Counterintuitive naming — preserve it, or fix contract + frontend together.)
- **Success:** `200` `{ "success": true, "action": "liked"|"unliked", "newLikeCount": <int> }` _(verify exact keys in Network tab)_.
- **Side effects:** insert/delete `user_likes(username, uploadId)` (unique on pair); `items.likes` +1 / GREATEST(-1,0); then re-SELECT count.
- **Errors:** `400` already-liked/unliked or missing fields; `404` item not found; `405` wrong method.
- **Consumers:** `user/community.php` inline JS (`toggleLike`).
- **Notes:** wrap the two-table mutation in a **transaction** in C#. Relies on a unique constraint on `user_likes(username, uploadId)`.

### POST /api/post_comment.php  — difficulty 2  ✅ MIGRATED → C# `POST /items/{fileId}/comments`
> C# **enforces login (401)** — fixes the "null Today" bug. Inserts `Comments(Username, Content, Name=fileId)`
> (Name = the post's uploadId). Returns `{success, comment:{id, content, username, createdAt}}`. Also added
> **`GET /items/{fileId}/comments`** (list, oldest first, anonymous ok) — no legacy JSON equivalent (prod
> rendered comments server-side in explore.php). Replies (`post_replies.php` → `Replies` table) NOT migrated yet.
- **Auth:** reads `$_SESSION['username']` but **does NOT enforce login** → inserts null username when logged out (the "null Today" bug). **Enforce 401 in C#.**
- **Content-Type:** form-encoded (`$_POST`).
- **Body:** `imageId`: string (the item's uploadId), required; `content`: string, required; `name`: string, optional.
- **Success:** `200` `{ "success": true, "comment": { "id": int, "comment": string, "name": string|null, "username": string, "createdAt": "<MySQL datetime>" } }`.
- **Errors:** `{ "success": false, "message": "Missing required fields" }` (no HTTP code set); `{ "success": false, "message": "Error posting comment" }` on insert failure.
- **Side effects:** insert `Comments(Username, Content, Name)` — note `Name` stores the **item uploadId** (that's how comments link to a post); also calls dead `/opt/update-db-dump.sh` in prod (remove).
- **Consumers:** `JS/toggleComments.js` (`postComment`).

### GET /api/get_messages.php  — difficulty 2  ✅ MIGRATED (collapsed) → C# `GET /messages?conversationId=&afterId=`
- **Auth:** requires login (401). **Params:** `conversation_id`: int, required (400 if invalid).
- **Success:** `200` JSON array of `{ message_id:int, content:string, sender:string, unix_seconds:int }`,
  ordered by timestamp ASC. Filtered to rows where the user is sender or receiver in that conversation.
- **Side effects:** none. **Consumers:** `JS/messages.js` (initial conversation load).
- **Purpose:** **full history** for a conversation (initial load).

### GET /api/get_new_messages.php  — difficulty 2
- **Auth:** requires login (401). **Params:** `conversation_id`: int; `last_id`: int — both required (400).
- **Success:** `200` JSON array of `{ message_id:int, content:string, sender:string, timestamp:string }`
  where `message_id > last_id`, ordered ASC.
- **Side effects:** calls dead `/opt/update-db-dump.sh` in prod (remove). **Consumers:** `JS/messages.js` polling loop.
- **Purpose:** **incremental poll** — only messages newer than the client's last seen id.
- ⚠️ **Inconsistency vs get_messages:** returns raw `timestamp` (string) instead of `unix_seconds` (int).
  In the new stack, **collapse both into one** `GET /messages?conversationId=&afterId=` (afterId optional),
  return a consistent timestamp format — or replace polling with SignalR push.
  **✅ DONE:** `get_new_messages.php` collapsed into the same `GET /messages` (pass `afterId`); shape is now
  consistently `{messageId, content, sender, unixSeconds}` (camelCase JSON). React polls it every 3s.

### Messaging writes/lists — difficulty 2  ✅ MIGRATED
- `POST /api/send_message.php` → **`POST /messages`** `{conversationId, receiverUser, content}`. Sender is the
  **JWT user** (legacy trusted a client-supplied `sender_user`). Returns the created `{messageId, content,
  sender, unixSeconds}`, 201. `GET /messages` is **scoped to the caller** (sender or receiver) — authorizes + filters.
- `GET /api/get_conversations.php` → **`GET /conversations`** → `[{conversationId, participant, lastMessageTime}]`,
  newest activity first (derived from `messages`; the `participants` table is empty/unused).
- `POST /api/create_conversation.php` → **`POST /conversations`** `{username}` → find-or-create 1:1;
  `{status, conversationId, isNew, username}`; 404 if user missing. Seeds a "Conversation started" message
  (so it shows in the list, matching prod). Verified: history (141), afterId poll, non-participant→0, 400/401/404.

### GET /api/stream_video.php, /api/download_video.php  ✅ MIGRATED → C# `GET /videos/stream|download?path=`
- Both just **302-redirect to a presigned S3 GetObject URL** (20 min); S3 serves bytes with native range
  support (needed for `<video>` seeking). stream sets `response-content-type=video/mp4`; download sets
  `attachment; filename=...`. Path guard: must start `uploads/`, no `..` → 404. Anonymous (public feed).
- **S3 config:** bucket `gooberbucketgc6788` (prod) / `gooberbucketgc6788test` (dev), region us-east-1.
  Creds mirror legacy s3_client.php: `ACCESS_KEY`/`SECRET_ACCESS_KEY` env vars (dev) else default chain
  (EB instance role, prod). **Signing creds need `s3:GetObject` on the bucket** — in prod, grant the C#
  EB env's instance role; in dev, run the API with the read user's keys (else the presigned URL returns 403).
- React feed now points `<source>` at `/api/videos/stream?path=`. Verified: 302 + correct presigned URL,
  bad-path/traversal → 404 (S3 fetch 403 locally until read creds are supplied).

### Generation pipeline (dream → video) — difficulty 4+, incremental
The big one. Legacy orchestrator `interpolation/dream2img.php` chains: dream → scene prompts (GPT text) →
**image_gen/gpt_image.php** (gpt-image-2, one PNG/scene) → interpolation into video (ffmpeg) → optional
ElevenLabs narration + Stability music → `api/save_video_item.php` (S3 + items row). Being migrated step by step.
- **✅ Image generation** → C# `ImageGenService` + `POST /generate/images {prompts}` (authed). Faithful port of
  `generate_scene_images`: OpenAI `v1/images/generations`, model `gpt-image-2`, n=1, 1024x1024; same fallback
  chain (reuse prev frame → pull a future frame → blank black frame). Blank frame via a tiny built-in PNG
  encoder (`BlankPng`) — no image library (ImageSharp v4 needs a paid license; v2 flags CVEs). Key from
  `OPENAI_API_KEY` (dev .env / prod env property). Dropped the legacy `CURLOPT_SSL_VERIFYPEER=false` hack
  (HttpClient validates certs). Verified: 400/401 + blank-frame fallback produces valid 1024x1024 PNGs.
- **✅ Dream → scene breakdown** → C# `SceneBreakdownService` + `POST /generate/scenes {dream, numScenes?}`.
  Ports the dream2img_actual.php step: OpenAI `v1/chat/completions`, model `gpt-4`, temp 0.8, the 4-message
  prompt; parses the fixed text format into scenes `{number, prompt, narration, duration}` + numScenes (2-8).
  Voice/music additions to the system prompt deferred to the TTS/music steps. Verified: 400/401 + graceful
  empty with no key; scene regex validated on a sample. Each scene's `prompt` feeds `/generate/images`.
- **✅ Video assembly (ffmpeg)** → C# `FfmpegService` (ports the ffmpeg parts of utils.php): each scene image →
  a clip held for its duration (`-loop 1 -i img -c:v libx264 -t dur -pix_fmt yuv420p -r 24`), clips joined with
  the concat demuxer (forward-slash list file — the backslash "Invalid data" fix). `POST /generate/video
  {dream,numScenes?}` runs scenes→images→clips→concat and returns the mp4; `GET /generate/selftest` proves
  ffmpeg + the path work with blank frames (no key). Verified selftest (real mp4) + 401 + 422-without-key.
  **PROD:** ffmpeg must be installed on the EB instance (add an `.ebextensions` package for AL2023). Note the
  legacy "interpolation" is just frame-rate upsampling, not motion interp; we hold each image for its duration.
- **✅ Audio (TTS + music + mux)** → C# `TtsService` (ElevenLabs `/v1/text-to-speech/{voiceId}`, model
  eleven_multilingual_v2, voices-list lookup + fallback voice), `MusicService` (Stability
  `/v2beta/audio/stable-audio-2/text-to-audio`, multipart, wav), and `FfmpegService.CreateSceneWithAudioAsync`
  (port of create_scene_with_audio: ffprobe voice length, scene = max(dur, voice+1.5)s, voice+music amix
  [music vol 0.15 + fades, voice adelay 0.5s] / music-only [vol 0.25] / voice-only, then `-c:a aac -shortest`).
  `POST /generate/video` gains `voice`/`music`/`voiceId`; `GET /generate/selftest` now also proves the mux with a
  synthesized silent track (audioMux:true) — no keys. Needs ELEVENLABS_API_KEY / STABILITY_API_KEY for real audio.
- **✅ save_video_item (S3 upload + items row)** → C# `VideoStoreService.SaveAsync` (finalize via
  `FfmpegService.FinalizeAsync` = volume 1.25 + alimiter + `+faststart`, else faststart-only; then S3 `PutObject`
  `uploads/dream_video_<yyyyMMdd_HHmmss>.mp4` private; then insert items row Name/Keywords/Path/Description/
  uploaded_by/uploadId). `POST /generate/video` gains `save:true` → returns `{uploadId, path, itemId}` instead of
  the mp4. Standalone `POST /items {name,path,uploadId,keywords?,description?}` ports save_video_item.php's DB
  insert (uploaded_by = JWT user). Verified: /items insert shows in the feed (then cleaned up), 400/401, finalize
  keeps audio. **S3 PutObject needs write creds** (dev .env ACCESS_KEY/SECRET or prod env props).
  **FULL PIPELINE now in C#:** dream→scenes→images→audio→clips→concat→finalize→S3→items→feed.
- ⏳ Remaining (non-core): `list_uploads`, `stop_process`.

### Remaining endpoints  ✅ MIGRATED (2026-10-11)
- `list_uploads.php` → **`GET /uploads`** (S3 ListObjectsV2 prefix `uploads/`; needs s3:ListBucket). [Authorize].
- `delete.php` → REST deletes, owner-or-admin (`Admin:Username`, default bumbameal882):
  - **`DELETE /items/{fileId}`** (post) — removes replies on its comments, the comments, its likes, the row
    (transaction), then the S3 object (best-effort).
  - **`DELETE /comments/{commentId}`** — removes its replies (FK) then the comment.
  - **`DELETE /users/me`** (self account) — cascades replies/comments/messages/likes/items/user_ai_settings
    (FK order) then the user; clears the gb_auth cookie. (Admin-delete-other-user omitted.)
- `post_replies.php` → **`GET` / `POST /comments/{commentId}/replies`** (Reply model → `Replies` table; POST
  enforces login). Replies have an FK to comments, handled in all comment deletes.
- `ai_agent.php` + `messages_ai.php` → **NOT MIGRATED (intentionally).** The legacy UI never wired the AI chat
  to users, so it's a dormant/future feature. A C# port (`POST /messages/ai` + `AiChatService`, gpt-3.5-turbo)
  was written then **removed** to avoid a dead endpoint. If it becomes a real feature: ElevenLabs-style port —
  find-or-create the AI convo, store user msg, reply via OpenAI with the last 20 messages as context, store the
  reply as the AI (not as the user, which was a legacy quirk).
- `stop_process.php` → **N/A** (it `pkill`s the PHP generation process). The C# stack cancels via the request's
  CancellationToken, so there's no separate process to kill; not ported.
Verified against local DB: replies CRUD, comment delete (cascades replies), post delete (owner + 403), full
account cascade (every table → 0), /uploads 200, /messages/ai creates the convo + returns gracefully.
