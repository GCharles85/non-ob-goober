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
