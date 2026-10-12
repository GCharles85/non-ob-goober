# Production deployment

## C# backend — Elastic Beanstalk (done 2026-10-08)
- **App / env:** `gooberbox-api` / `gooberbox-api-dev` (single-instance, **.NET 10 on AL2023**), env id `e-7a7i8spkc2`.
- **URL:** http://gooberbox-api-dev.eba-tiu2qqjq.us-east-1.elasticbeanstalk.com  (`/health`, `/auth/*`).
- **Deploy:** from `D:\gooberbox-api`:
  1. `powershell -ExecutionPolicy Bypass -File .\publish.ps1` → builds `publish\` + `api-deploy.zip`
     (the zip is written with **forward-slash** paths — PowerShell's `Compress-Archive` uses
     backslashes, which EB's Linux `unzip` rejects).
  2. `eb deploy gooberbox-api-dev` (config.yml has `deploy.artifact: api-deploy.zip`).
- **Env properties (set in the console, secrets — not in git):**
  - `ConnectionStrings__GooberBox` = `server=db-goober...rds.amazonaws.com;port=3306;database=goober_box;user=root;password=<DB_PASSWORD>`
  - `Jwt__Secret` = strong random (must equal the PHP env's `JWT_SECRET` once the bridge is deployed to prod)
  - `ASPNETCORE_ENVIRONMENT=Production` (from `.ebextensions/00_launch_template.config`)
  - `__` double underscore maps to .NET config `:` (e.g. `Jwt__Secret` → `Jwt:Secret`).
- **Networking:** env instance SG `sg-0767fcd9e5bc95f3c` was added to the RDS SG `goober-rds-sg`
  (`sg-05fefa8142322ae2b`) on 3306, alongside the PHP env's SG.
- **Gotchas hit:** new-account launch-config error (fixed via `DisableIMDSv1`); app 500s on startup if
  `Jwt:Secret` is unset (auth middleware runs on every request); fixed DB server version
  (`MySqlServerVersion`) so the app starts without a DB connection (health works before SG wiring).
- **Not yet:** not in the public path (gooberboks.com still → PHP env). Gateway/entry wiring comes when
  we route real prod traffic. This env is framework-dependent; the EB platform provides the .NET 10 runtime.

## React frontend — S3 + CloudFront (done 2026-10-08)
- **Bucket:** `gooberbox-web` (private, Block Public Access ON). **CloudFront dist id:** `EX1RJIT2FI0QQ`
  (OAC reads the bucket; default root `index.html`; SPA fallback 403/404 → `/index.html` 200).
- **Deploy:** from `D:\gooberbox-web`:
  `powershell -ExecutionPolicy Bypass -File .\deploy.ps1 -Bucket gooberbox-web -DistId EX1RJIT2FI0QQ`
  (builds, `s3 sync --delete` with long cache on fingerprinted assets + `no-cache` on index.html,
  then CloudFront invalidation).
- **IAM:** added an inline policy on `goober-deploy-cli` for `s3:PutObject/DeleteObject/ListBucket` on
  `gooberbox-web*` and `cloudfront:CreateInvalidation`. (It still lacks CreateBucket / CloudFront create.)
- Currently serves the Vite starter (placeholder) at the CloudFront domain. Custom domain + public
  entry routing (/api → C# env, rest → PHP) come later.

## Cutover to the new stack (2026-10-11)

### Done (by Claude, CLI — new stack only; gooberboks.com still → PHP throughout)
- **API redeployed** to `gooberbox-api-dev` with the full migrated build (all controllers, generation
  pipeline). Verified on the EB URL: `/health`, `/api/health`, `/items`, `/api/items` → 200;
  authed endpoints → 401 anon.
- **`/api` prefix strip** (Program.cs middleware before an explicit `UseRouting`) so the bare controller
  routes work behind CloudFront (which can't strip a path prefix) exactly as behind the dev YARP gateway.
- **ffmpeg/ffprobe** installed on the EB box via `.ebextensions/01_install_ffmpeg.config` (static build →
  /usr/bin; guarded by `test:` so a transient download can't abort a redeploy). Installed on first deploy
  (the deploy completed, which a failing command would have aborted).
- **gb_auth cookie** `Secure` is now env-driven (true in prod/HTTPS).
- **Env props** (set by the owner in the console): `OPENAI_API_KEY`, `ELEVENLABS_API_KEY`,
  `STABILITY_API_KEY`, `S3__Bucket=gooberbucketgc6788` — alongside the existing DB/JWT/S3 creds.
- **Frontend** built + `s3 sync`'d to `gooberbox-web` + CloudFront invalidation (replaces the placeholder).
  App calls relative `/api/*` (same-origin), no baked-in API URL.

### ✅ LIVE 2026-10-12 — gooberboks.com now serves the new stack
CloudFront `EX1RJIT2FI0QQ` (domain `d8whuzuuei4it.cloudfront.net`) serves the React SPA with an `/api/*`
behavior → the EB API origin; apex + www point at it via Namecheap; TLS via the reused us-east-1 ACM
cert `0709c55a-f1e9-4e7e-9644-de2e22586655` (covers gooberboks.com + www). End-to-end verified in
browser (login, video generation, playback). **Fix during cutover:** the EB `ACCESS_KEY` env prop didn't
match the valid `goober-app-s3` key (`InvalidAccessKeyId` broke S3 playback + generation saves); re-set
to the correct pair. PHP `gooberbox` env kept running as rollback.

Follow-ups: push `gooberbox-api`/`gooberbox-web` to GitHub (no remote yet); decommission PHP after soak;
harden CloudFront→origin (HTTP today, exposes the gb_auth cookie on that hop); single-instance env has no
redundancy and in-memory generation jobs (Tier A).

### Phase-3 steps as executed (owner, in console + Namecheap — `goober-deploy-cli` lacks CloudFront read/update)
1. **CloudFront `EX1RJIT2FI0QQ` — add API origin + `/api/*` behavior:**
   - New **origin**: domain `gooberbox-api-dev.eba-tiu2qqjq.us-east-1.elasticbeanstalk.com`,
     **HTTP only**, port 80 (the EB env has no TLS).
   - New **behavior**: path `/api/*` → that origin; Viewer protocol **Redirect HTTP→HTTPS**;
     allowed methods **GET,HEAD,OPTIONS,PUT,POST,PATCH,DELETE**; Cache policy **CachingDisabled**;
     Origin request policy **AllViewerExceptHostHeader** (forwards the `gb_auth` cookie + Authorization,
     keeps Host = the EB origin so nginx routes it). CachingDisabled passes `Set-Cookie` back to the viewer.
2. **Custom domain on the distribution:** add CNAMEs `gooberboks.com` + `www.gooberboks.com`; attach a
   **us-east-1** ACM cert covering them (CloudFront requires us-east-1; request/validate via Namecheap DNS
   if the existing ALB cert isn't us-east-1 or doesn't cover the names).
3. **Namecheap DNS flip:** point apex `@` (ALIAS record) and `www` (CNAME) → the distribution domain
   `dXXXX.cloudfront.net` (shown on the distribution). This is the reversible cutover; roll back by
   pointing DNS back to the PHP env.
4. **Keep the PHP `gooberbox` env running** as instant rollback until the new stack is proven.
5. **Verify at https://gooberboks.com:** register/login (Secure cookie), feed + video playback, a full
   end-to-end video generation (exercises ffmpeg + the 3 keys + the prod S3 bucket), messages, profile,
   reply delete. The authed `GET /api/generate/selftest` should return `{ ffmpeg: true, ... }`.
