# Video generation: long-running request handling

How the C# backend runs the multi-minute "prompt → video" generation, and why. Captures the research
and the decision so we don't re-litigate it.

## The problem

Generation chains several slow steps — scene breakdown (gpt-4), one image per scene (gpt-image-2),
optional narration (ElevenLabs) + music (Stability), then ffmpeg clip/concat/finalize, then an S3
upload. End to end this is **minutes**, not seconds.

A plain **synchronous** HTTP request for that is fragile:
- **Proxy timeouts.** The first live test returned **504** — YARP's default `ActivityTimeout` is 100s,
  so the gateway gave up while the API was still generating (we bumped it to 15m, and Vite's proxy to
  match, but a single request held open for minutes through browser → Vite → YARP → Kestrel is brittle).
- **No reload persistence.** A synchronous request is tied to the connection. On a page reload the
  browser aborts the in-flight fetch; the server sees `RequestAborted` and cancels the generation. So a
  reload would *kill* an in-progress video — the opposite of persistence.
- **Stop.** Aborting the fetch is the only control, and it's gone after a reload.

## How the legacy PHP app does it

The robustness in `user/upload.php` lives on the **client**, not the server:
1. Snapshot the uploads via `interpolation/list_uploads.php`.
2. Fire `interpolation/dream2img.php` (the long synchronous generation).
3. Store a timer in **`localStorage`** (`videoCheckTimer`: startTime, initial file list, prompt) and
   **poll `list_uploads.php`** every ~75s; when a new file appears, generation is done.
4. Stop = `api/stop_process.php`, which `pkill`s the `dream2img_actual.php` process (blunt — kills all).

So the server is *also* synchronous; persistence comes from client `localStorage` + polling, which
survives reloads and even a 504 (PHP keeps running after the proxy gives up).

## Options considered

- **Tier A — client-driven (chosen).** Client owns the job: a `localStorage` record + polling a
  job-status endpoint. Keep the server simple, but add a **minimal in-memory job registry** so the
  generation is *not* tied to the HTTP connection (survives disconnect/reload) and can be cancelled.
- **Tier B — full async job system.** `/generate/video` enqueues a background job and returns
  immediately; a durable (DB-backed) job store + a hosted worker/queue; status + stop endpoints.
  Most robust (reload-proof, survives API restarts, scales across instances), but a lot more machinery.

### Trade-offs

| | Tier A (chosen) | Tier B |
|---|---|---|
| Reload-proof | ✅ | ✅ |
| Real stop (post-reload) | ✅ (cancel endpoint) | ✅ |
| Survives API restart | ❌ (in-memory) | ✅ (if DB-backed) |
| Multi-instance / scale | ❌ (in-memory registry) | ✅ (shared store) |
| Complexity | low–moderate | high |

## Decision — Tier A

Client-driven persistence backed by a **lightweight in-memory job registry**. Enough to match the PHP
behaviour (reload-proof + a real stop) without a durable queue we don't need on a single EB instance.

### Design

**Server**
- `GenerationJobs` singleton: `ConcurrentDictionary<jobId, { CancellationTokenSource, Status, UploadId?,
  Error?, Updated }>`. Jobs are cancellable and self-expire (swept after ~15 min). The CTS is linked to
  app shutdown + a hard max timeout, **not** `HttpContext.RequestAborted`, so a client disconnect/reload
  does **not** kill the generation.
- `POST /api/generate/video` takes a client-supplied **`jobId`**; it registers the job, runs the
  pipeline under the registry token, records `done`+uploadId / `error` / `canceled`, and still returns
  the result on the happy path. The temp working dir is deleted in a `finally` on every exit (incl. stop).
- `GET /api/generate/jobs/{jobId}` → `{ status: running|done|error|canceled|unknown, uploadId?, error? }`.
- `POST /api/generate/jobs/{jobId}/stop` → cancels the job's CTS (which also kills the ffmpeg process).
  This is the real server-side cancel — the proper replacement for `stop_process.php`'s `pkill`.

**Client (React)**
- On submit: make a `jobId` (`crypto.randomUUID()`), save `{ jobId, prompt, startedAt }` to
  `localStorage`, POST `/generate/video` with it (fire-and-forget for the UI).
- **Poll** `GET /generate/jobs/{jobId}` every few seconds → drive the status. `done` shows the feed link
  and clears storage; `error`/`canceled` reset.
- On app mount, if `localStorage` has an active job, **resume polling** (so a reload still shows
  "Generating…"). Stop button → `POST .../stop`.

### Known caveats (accepted)

- **In-memory registry → lost on API restart** (and the in-process generation dies with it). A client
  polling a now-unknown job gets `status: unknown` → it stops and tells the user to check the feed.
- **Single instance only** — the registry isn't shared across instances. Fine for the current
  single-instance EB env; revisit if we scale out.
- The happy-path request is still held open (we poll in parallel); harmless, and simpler than a true
  fire-and-forget background task.

### If we ever need more (future → Tier B)

Persist jobs in a `generation_jobs` table + a hosted `BackgroundService` worker consuming a `Channel`
(or a real queue), so jobs survive restarts and work across instances. Out of scope now.
