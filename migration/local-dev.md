# Local dev — hybrid stack

How to run the whole strangler setup on this Windows machine. One entry point (the gateway on
`:8080`) routes to the C# API, the legacy PHP app, and (later) the React dev server.

> **Prereq:** Smart App Control must be **Off** (it blocks running locally-built, unsigned C# apps).
> Already disabled on this machine (2026-10-05).

## Components & ports
| Component | Path | Port | Start |
|-----------|------|------|-------|
| Gateway (YARP) | `D:\gooberbox-api\src\GooberBox.Gateway` | **8080** | `dotnet run` |
| C# API | `D:\gooberbox-api\src\GooberBox.Api` | **5100** | `dotnet run` |
| Legacy PHP | `D:\html` | **8000** | `tools\serve.ps1` (also starts MySQL) |
| React (Vite) | `D:\gooberbox-web` | **5173** | `npm run dev` |
| MySQL | data `D:\mysql-data` | 3306 | started by `serve.ps1` (or `mysqld --datadir=D:/mysql-data`) |

`dotnet` is at `C:\Program Files\dotnet\dotnet.exe` (.NET 10). Ports are pinned in each project's
`Properties/launchSettings.json`; override with `ASPNETCORE_URLS=http://localhost:<port>`.

## Run order (typical)
1. **PHP + MySQL:** `powershell -ExecutionPolicy Bypass -File D:\html\tools\serve.ps1` → http://localhost:8000
2. **C# API:** in `D:\gooberbox-api\src\GooberBox.Api` → `dotnet run` → http://localhost:5100/health
3. **Gateway:** in `D:\gooberbox-api\src\GooberBox.Gateway` → `dotnet run` → http://localhost:8080
4. **React (when used):** in `D:\gooberbox-web` → `npm run dev` → http://localhost:5173

Then browse the whole app through **http://localhost:8080**.

## Routing (gateway `appsettings.json`)
- `/api/health` → C# API (rewritten to `/health`) — the template for migrated endpoints.
- `{**catch-all}` → legacy PHP (`:8000`).
- `web` cluster (`:5173`) is defined for when we start routing SPA paths to React.
As endpoints migrate: add a route to the `api` cluster for that path; everything else keeps hitting PHP.

## Verified (2026-10-05)
`GET :8080/api/health` → C# API 200 · `GET :8080/` and `/user/login.php` → PHP 200. Facade works.

## Notes / TODO
- API template adds HTTPS redirect → harmless warning over http; strip `UseHttpsRedirection` for dev.
- React isn't routed through the gateway yet (add a `/` or `/app` route to the `web` cluster when the
  first page is migrated).
- Nothing here is deployed; prod topology is ALB → YARP → {C# API, PHP (EB), React} (see decisions.md).
