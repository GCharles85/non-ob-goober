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

## PHP env deactivated (scaled to 0) — 2026-10-12  ⚠️ SUPERSEDED by termination (see next section)

> The scale-to-0 below kept the env alive as a cheap rollback. We later decided to **terminate** it
> outright (sheds the ALB too). Once terminated, the "bring back up" steps here no longer apply —
> use **"Recreating the PHP env after termination"** instead. Left for history.

After the cutover, the legacy PHP env `gooberbox` (env id `e-gtrzvjdtp4`, **LoadBalanced**, WebServer tier)
was **scaled to zero instances** to stop paying for compute while keeping it as a rollback. Not terminated
— the environment, config, and CNAME are preserved.

Safety confirmed first: the shared RDS is **not** coupled to this env (`aws:rds:dbinstance`
`HasCoupledDatabase=false`), so zeroing/terminating it never touches `db-goober` (standalone, `available`).

**What was changed** (`aws elasticbeanstalk update-environment --environment-name gooberbox`):
- `aws:autoscaling:asg` → `MinSize=0`, `MaxSize=0`   (was 1 / 1)
- `aws:autoscaling:updatepolicy:rollingupdate` → `RollingUpdateEnabled=false`   (was true)
  - **Required**: with `MaxSize=0`, EB rejects the change otherwise —
    `ConfigurationValidationException: MinInstancesInService (0) should be less than MaxSize (0)`.
    Disabling rolling updates for the change bypasses that validation.

Result: ASG Min/Desired/Max = 0/0/0, instance terminated, env Health `Grey` / Status `Ready`.
**Residual cost:** the **ALB stays** (~$16–18/mo); removing it requires a full `eb terminate` (destroys the env).

### TO BRING THE PHP ENV BACK UP (restore originals + re-enable rolling updates)
```
aws elasticbeanstalk update-environment --environment-name gooberbox \
  --option-settings \
    Namespace=aws:autoscaling:asg,OptionName=MinSize,Value=1 \
    Namespace=aws:autoscaling:asg,OptionName=MaxSize,Value=1 \
    Namespace=aws:autoscaling:updatepolicy:rollingupdate,OptionName=RollingUpdateEnabled,Value=true
```
Wait for Status `Ready` / Health `Green`; a fresh instance launches with the last-deployed PHP app.
**Note:** DNS still points `gooberboks.com` → CloudFront, so to actually *serve* PHP again you must also
repoint Namecheap (apex/www) back to the PHP env's CNAME (`gooberbox.us-east-1.elasticbeanstalk.com`).
PHP ASG: `awseb-e-gtrzvjdtp4-stack-AWSEBAutoScalingGroup-ZZKrXHfu0s1i`.

## Recreating the PHP env after termination — written 2026-10-15

**TERMINATED 2026-10-15** (`eb terminate gooberbox`) to shed the ALB (~$16–18/mo) as well as compute —
verified env `e-gtrzvjdtp4` Status=`Terminated`, RDS `db-goober` still `available`. Terminate destroys
the **environment** only — the EB **application** `gooberbox`, its
**saved configs** (incl. `gooberbox-v2`), and uploaded **app versions** all survive. So bringing PHP
back later is a **recreate from the saved config**, not a scale-up. RDS is safe to terminate around:
`db-goober` is standalone (`HasCoupledDatabase=false`, verified).

**This replaces the scale-up steps above** — after termination the env, its CNAME, and its instance SG
no longer exist, so there is nothing to scale.

### 0. Preflight (this account gets cost-cut — verify before anything)
- **RDS `db-goober`** exists + `available` (MySQL 8.4). If it was deleted to save money, restore from a
  snapshot (or recreate + reload the `goober_box` schema/data) first.
- **VPC Internet Gateway** attached (the LoadBalanced env's public subnets + ALB need it).
- **RDS SG `goober-rds-sg`** (`sg-05fefa8142322ae2b`) still exists. (The C# env's SG
  `sg-0767fcd9e5bc95f3c` stays in it, untouched by this.)
- **ACM cert** `0709c55a-f1e9-4e7e-9644-de2e22586655` (covers `gooberboks.com` + `www`, us-east-1) still
  present — the ALB 443 listener uses it.
- **Saved config present:** `eb config list` (or EB console → app `gooberbox` → Saved configurations)
  shows `gooberbox-v2`.

### 1. Recreate the environment from the saved config
From the EB app source dir **`D:\goober`** (the obfuscated build output; `.elasticbeanstalk/config.yml`
→ app `gooberbox`, env `gooberbox`, PHP 8.4 AL2023, us-east-1, key pair `aws-eb`):
```
eb create gooberbox --cfg gooberbox-v2
```
This restores the **LoadBalanced** env with its original settings: instance type, **ASG Min/Max = 1/1**,
**`RollingUpdateEnabled=true`**, the 443 listener + cert, the `DisableIMDSv1` launch template, and all
`.ebextensions` (php.ini, ffmpeg install, S3 instance-profile auth). **Because this comes from the saved
config, the scale-to-0 rolling-updates workaround is irrelevant — you do NOT re-toggle
`RollingUpdateEnabled`; a fresh create already has healthy 1/1 + rolling updates on.**

### 2. Re-set env properties / secrets (NOT in git)
The saved config was captured 2026-10-01 and may not carry the current secrets. After create, confirm
(EB console → Configuration → Environment properties, or `eb setenv …`) the PHP app's `getenv()` vars:
- `DB_HOST` = `db-goober…rds.amazonaws.com`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` = `goober_box`
- `JWT_SECRET` — must equal the C# env's `Jwt__Secret` if the auth bridge is in play
- `APP_ENV=production` (so `loadenv.php` uses `getenv()` and does NOT read a `.env`)
- AI keys the PHP generation pipeline uses: `OPENAI_API_KEY`, `ELEVENLABS_API_KEY`, `STABILITY_API_KEY`
- **S3 needs no keys in prod** — bucket `gooberbucketgc6788` is reached via the EC2 **instance role**
  (`.ebextensions/97_instance_profile.config`). Do not add `ACCESS_KEY/SECRET_ACCESS_KEY` on prod.

### 3. Re-wire the new instance SG to RDS
The recreated env gets a **new** instance security group. Add it inbound on **3306** to
`goober-rds-sg` (`sg-05fefa8142322ae2b`) — the old env's rule was destroyed with it. Find the new SG via
`eb status` / EC2 console (the env's ASG instances).

### 4. Deploy the app code (if the create didn't ship current code)
`eb create` deploys the current app version from `D:\goober`. For the exact last-live obfuscated build,
run the normal pipeline from **`D:\html`** (edit only here):
```
powershell -ExecutionPolicy Bypass -File D:\html\tools\deploy.ps1
```
(obfuscates → `/MIR` into `D:\goober` → `git commit` → `eb deploy gooberbox`). Never edit `D:\goober`
directly. See [[gooberbox-eb-deploy]] for the pipeline details.

### 5. DNS cutover back to PHP
The new env gets a **new** CNAME hash — grab it from `eb status` (e.g. `gooberbox.<hash>.us-east-1.elasticbeanstalk.com`).
To actually serve PHP again, in **Namecheap** repoint apex `@` + `www` from the CloudFront domain back to
that EB CNAME. That bypasses CloudFront entirely (and its `www`→apex function); HTTPS works directly on
the ALB via the ACM cert from step 0. (Rollback the other way by pointing DNS back at CloudFront.)

### 6. Verify
Health **Green** / Status **Ready**; `https://gooberboks.com` serves PHP; DB-backed pages load; a video
generation + playback works (exercises ffmpeg + instance-role S3).

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
