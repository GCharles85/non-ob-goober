# Inventory (Phase 0)

Snapshot of the legacy PHP app for migration planning. Difficulty is a rough 1–5
(1 = trivial, 5 = hard). "Auth" = requires a logged-in user.

## Backend API endpoints (`api/*.php`) — the clean strangler seam
These already return JSON and are called from the frontend via `fetch()`. Reimplement in C#
with the same contract, then flip the path at the edge; PHP pages need no changes.

| Endpoint | Method(s) | Auth | DB tables | External | Difficulty | Notes |
|----------|-----------|------|-----------|----------|:---------:|-------|
| `search_users.php` | GET | yes | Users | – | 1 | Read-only, isolated. **Good first slice.** |
| `get_conversations.php` | GET | yes | messages, conversations, participants | – | 2 | Read-only. |
| `get_messages.php` | GET | yes | messages | – | 2 | Read-only. |
| `get_new_messages.php` | GET | yes | messages | – | 2 | Polling read. |
| `fetch_comments` (in `src/`) | GET | – | Comments | – | 1 | Read-only; used by pages. |
| `fetch_replies` (in `src/`) | GET | – | Replies | – | 1 | Read-only. |
| `toggle_like.php` | POST | yes | user_likes, items | – | 2 | First **write** slice. |
| `post_comment.php` | POST | yes | Comments | – | 2 | Write. Currently allows null user if logged out — tighten. |
| `post_replies.php` | POST | yes | Replies | – | 2 | Write. |
| `send_message.php` | POST | yes | messages | – | 2 | Write. |
| `create_conversation.php` | POST | yes | conversations, messages, participants, Users | – | 3 | Multi-table. |
| `save_video_item.php` | POST | – (should be) | items | – | 2 | Inserts the post row. Called by worker now. |
| `delete.php` | POST | yes | Comments, Replies, items, user_likes, messages | S3 | 3 | deleteComment / deletePost / deleteUser; S3 cleanup. |
| `stream_video.php` | GET | – | – | S3 | 2 | Redirects to presigned S3 URL. |
| `download_video.php` | GET | – | – | S3 | 2 | Redirects to presigned S3 URL (attachment). |
| `list_uploads` (in `interpolation/`) | GET | – | – | S3 | 2 | Lists `uploads/` in the bucket. |
| `ai_agent.php` | POST | yes | messages | OpenAI | 4 | AI chat agent; also calls localhost (hardcoded). |
| `messages_ai.php` | POST | yes | messages_ai | OpenAI | 4 | AI messaging. |
| `stop_process.php` | POST | – | – | – | 2 | Kills a running generation (Linux-specific). |

## AI video generation (`interpolation/*`) — hardest, migrate last
| File | Role | External | Difficulty |
|------|------|----------|:---------:|
| `dream2img.php` | Entry: validates, spawns background worker | – | 4 |
| `dream2img_actual.php` | The pipeline: scenes → images → TTS → music → ffmpeg → S3 → DB row | OpenAI, ElevenLabs, Stability, ffmpeg, S3 | 5 |
| `dall_e_test.php` | Image generation (gpt-image) | OpenAI | 3 |
| `11labs_text_to_speech.php` | Narration | ElevenLabs | 3 |
| `stability_voice_text_to_music.php` | Music | Stability | 3 |
| `fetch_voice_info.php` / `find_voice_by_name.php` | Voice list/lookup | ElevenLabs | 2 |
| `Utils/utils.php` | ffmpeg helpers, scene assembly, form parsing | ffmpeg | 4 |

Rebuild this as: HTTP endpoint enqueues a job → **worker service** (C# hosted service / SQS consumer)
runs the pipeline → writes `items` row + uploads to S3. Replaces the current `exec(... &)` spawn.

## Frontend pages (`user/*.php`) — replace whole, as React routes
| Page | Feature | Backend it needs | Difficulty |
|------|---------|------------------|:---------:|
| `login.php` | Login/register | auth | 2 (after auth bridge) |
| `logout.php` | Logout | auth | 1 |
| `community.php` | Video feed, likes, delete | items, user_likes, delete, stream_video | 3 |
| `explore.php` | Single item view, comments, delete | items, Comments, Replies, stream_video | 3 |
| `upload.php` | Dream submission + voice pick + polling | dream2img, fetch_voice_info, list_uploads | 4 |
| `messages.php` | DM + AI agent | get/send messages, ai_agent, search_users | 4 |
| `index.php` | Landing | – | 1 |

## Shared includes (`src/*`)
`bootstrap.php` (constants, error log), `loadenv.php` (env), `connectToDB_Login.php` (mysqli),
`userModel.php` (login/register), `loginController.php`, `nav.php`, `footer.php`, `s3_client.php`,
`fetch_comments.php`, `fetch_replies.php`, `ai_config.php`.
→ In C#: these become DI services (DbContext, auth service, S3 service, config). `nav`/`footer`
become React components.

## Database tables (shared, MySQL/RDS)
`Users`, `items`, `Comments`, `Replies`, `user_likes`, `messages`, `conversations`, `participants`,
`messages_ai` / `user_ai_settings`, `support_files`.

> Casing gotcha: MySQL on Linux is case-sensitive; tables are `Users`/`Comments`/`Replies`
> (capitalized) but `items`/`messages`/etc. lowercase. EF Core mappings must match exactly.

## External services
- **OpenAI** (`api.openai.com`) — scene breakdown (GPT) + image gen (gpt-image).
- **ElevenLabs** (`api.elevenlabs.io`) — narration + voice list (needs `voices_read` on the key).
- **Stability** (`api.stability.ai`) — music.
- **AWS S3** — video storage (`gooberbucketgc6788` prod / `...test` dev), presigned URLs, instance role in prod.

## Suggested first 3 slices
1. `search_users` (difficulty 1, read, isolated) — proves the whole stack path end to end.
2. `toggle_like` (difficulty 2) — first write; simple 2-table transaction.
3. `get_conversations` + `get_messages` (reads) — sets up the messaging data model in C#.
