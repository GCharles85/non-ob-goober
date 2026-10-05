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

### GET /api/search_users.php  — difficulty 1 (first slice)
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

### POST /api/toggle_like.php  — difficulty 2 (first write slice)
- **Auth:** requires login (reads `$_SESSION['username']`; currently no explicit 401 guard — add one in C#).
- **Content-Type:** `application/json`. Method must be POST (else `405`).
- **Body:** `{ "fileId": string (uploadId), "isLiked": bool }`.
  - ⚠️ **`isLiked` = the state BEFORE the toggle.** `isLiked=false` → **like** (insert); `isLiked=true` → **unlike** (delete). (Counterintuitive naming — preserve it, or fix contract + frontend together.)
- **Success:** `200` `{ "success": true, "action": "liked"|"unliked", "newLikeCount": <int> }` _(verify exact keys in Network tab)_.
- **Side effects:** insert/delete `user_likes(username, uploadId)` (unique on pair); `items.likes` +1 / GREATEST(-1,0); then re-SELECT count.
- **Errors:** `400` already-liked/unliked or missing fields; `404` item not found; `405` wrong method.
- **Consumers:** `user/community.php` inline JS (`toggleLike`).
- **Notes:** wrap the two-table mutation in a **transaction** in C#. Relies on a unique constraint on `user_likes(username, uploadId)`.

### POST /api/post_comment.php  — difficulty 2
- **Auth:** reads `$_SESSION['username']` but **does NOT enforce login** → inserts null username when logged out (the "null Today" bug). **Enforce 401 in C#.**
- **Content-Type:** form-encoded (`$_POST`).
- **Body:** `imageId`: string (the item's uploadId), required; `content`: string, required; `name`: string, optional.
- **Success:** `200` `{ "success": true, "comment": { "id": int, "comment": string, "name": string|null, "username": string, "createdAt": "<MySQL datetime>" } }`.
- **Errors:** `{ "success": false, "message": "Missing required fields" }` (no HTTP code set); `{ "success": false, "message": "Error posting comment" }` on insert failure.
- **Side effects:** insert `Comments(Username, Content, Name)` — note `Name` stores the **item uploadId** (that's how comments link to a post); also calls dead `/opt/update-db-dump.sh` in prod (remove).
- **Consumers:** `JS/toggleComments.js` (`postComment`).

### GET /api/get_messages.php  — difficulty 2
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

> Add remaining endpoints (send_message, post_replies, create_conversation, delete, get_conversations,
> save_video_item, stream/download_video, list_uploads, ai_agent, messages_ai) as they come up.
