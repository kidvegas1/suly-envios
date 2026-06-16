# Suly Envios — Master Plan

**Created:** 2026-06-16  
**Goal:** Multi-store report portal for Suly (admin) + store managers, on **Supabase** (DB + auth + file storage), deployed via **GitHub → Vercel**, ready for 3-month historical report upload.

**Prior decisions:** [Vision alignment design](file:///Users/gregdiaz/.gstack/projects/suly-envios-website/vision-alignment-design-2026-06-16.md) — D1-A: **Suly creates stores and invites managers** (no self-service store registration).

---

## 0. Pre-flight checks (done 2026-06-16)

### 0.1 Supabase MCP

**Status:** **Connected and authenticated** (server: `project-0-WEBSITE-supabase` → `https://mcp.supabase.com/mcp`).

Config: `.cursor/mcp.json` in this project.

**CLI note:** `supabase login` may still point at the old InterGlobal account unless you re-login. MCP and CLI auth are separate.

### 0.2 Supabase — can you create another project on Free?

**2026-06-16 — verified via Supabase MCP (authenticated):**

| Item | Value |
|------|--------|
| Account org | **Suly Multiservicios** (`aepnufmzxszazsvbxnzi`) |
| Active projects on this account | **0 / 2** |
| Can create `suly-envios`? | **Yes** — up to **2** new projects on Free |

**Previous InterGlobal account (CLI only, separate login):** still has 2/2 active (`aide-advanced`, `greg@intrglobal.com's Project`). That limit does **not** apply to the new Suly account.

**Recommended:** Create dedicated project `suly-envios` in org **Suly Multiservicios**, region `us-west-2` (or `us-east-1`).

**Free tier constraints that matter for Suly:**

- 500 MB database (enough for reports metadata + transactions; monitor growth)
- 1 GB **Storage** (PDF uploads should use **Supabase Storage**, not local disk)
- 50k MAU auth (more than enough for store managers)
- Projects **pause after 1 week inactivity** (upgrade to Pro for 24/7 production)

**Decision (resolved):** Use **Suly Multiservicios** org on the new account — create fresh `suly-envios` project when ready.

### 0.3 Vercel MCP

**Connected.** Team: `gregdiazg1-6735's projects` (`team_CUG4JmVBwGMgfsrldaQfC3pe`).

Existing Vercel projects: `aideadv`, `carmatch-ai-advanced`, `new-style-roofing-website`. **No `suly-envios` project yet.**

### 0.4 GitHub

**No repo found** for this codebase (`git status`: not a git repository). GitHub account: `kidvegas1`.

### 0.5 Vercel + current PHP stack

Today the app is **PHP 8.x + MySQL PDO + PHP sessions + local file uploads** (`index.php` router, 19 `api/*.php` files).

| Concern | Impact |
|---------|--------|
| Vercel native PHP | Not first-class; community `vercel-php` exists per-file only |
| Monolithic router + sessions | Poor fit for serverless; sessions need external store |
| Local `move_uploaded_file()` | **Breaks** on Vercel (read-only FS) → must use Supabase Storage |
| `ASSETS/` vs `assets/` casing | **Breaks on Linux** (Vercel/Railway) — rename before deploy |

**Recommended deploy architecture (honest):**

```
┌─────────────────────────────────────────────────────────────┐
│  Phase 1 (ship fast): PHP host + Supabase backend          │
│  GitHub → Railway or Render (PHP) + env vars                │
│  Supabase: Postgres + Auth + Storage                        │
│  Custom domain via GoDaddy (existing MCP)                   │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  Phase 2 (optional): Vercel front door                      │
│  Static pages on Vercel OR Next.js rewrite                  │
│  API proxy to PHP backend OR full Next.js API routes        │
└─────────────────────────────────────────────────────────────┘
```

If you **require Vercel-only** in phase 1, budget a **Next.js migration** (multi-week), not a config change.

---

## 1. Product scope (what we are building)

### Roles

| Role | Capabilities |
|------|----------------|
| **Suly (`admin`)** | Create stores + agency numbers; create/invite managers; see **all** stores; fix unassigned reports |
| **Manager (`manager`)** | Login locked to `store_id`; upload reports for that store only; view that store's data |
| **Employee (`employee`)** | Optional: upload and/or clock-in; same store lock |

### Core workflows

1. **Onboard:** Suly creates store → sets Barri/Viamericas/Intermex/Intercambio agency #s → creates manager user with email + `store_id`.
2. **Upload:** Manager drops PDF/XLS → client parsers (`pages/reports.html`) → POST `/api/barri-reports` → server **auto-matches agency #** → saves to store → creates `transfers` for matched clients.
3. **Review:** Suly opens Reports Center → all stores (filter optional) → drill into transactions, FinCEN flags, exports.
4. **Bulk history:** Admin queue for multi-file / multi-month upload (phase 4).

### Out of scope for v1 (defer)

- Managers self-registering stores
- Full LLM document parsing (keep rule-based parsers)
- Replacing caja/accounting unless needed for launch

---

## 2. Current codebase gaps (NOVA summary)

**Not production-ready.** Must fix before 3-month upload.

### P0 blockers

1. `api/import.php` — caja INSERT into generated column `total`; accounting uses wrong ENUM values
2. No store lock — `switch_store` allows any user → any store
3. No admin guard on `api/stores.php` writes
4. Auto-assign bypassed when UI sends explicit `store_id`
5. `ASSETS/` vs `assets/` — deploy 404 on Linux
6. MySQL-only; no env-based config; default admin password in seed
7. Uploads on local disk; public paths
8. No user invite/create API

### P1 (vision)

9. Admin god-view in dashboard + reports-center (explicit, not accidental)
10. `manager` role in schema
11. Bulk upload endpoint
12. Report delete orphaning `transfers`

---

## 3. Target architecture

### 3.1 Supabase services

| Supabase feature | Use for Suly Envios |
|------------------|---------------------|
| **Postgres** | All app tables (migrate from MySQL `schema.sql` + migrations) |
| **Auth** | Email/password login; JWT for API (replace PHP sessions) **or** keep PHP sessions + Supabase DB only (simpler) |
| **Storage** | `barri-reports/`, `client-ids/`, `imports/`, etc. (replace `assets/uploads/`) |
| **RLS** | Row-level security: managers see own `store_id`; admin bypass via service role on server |

**Auth recommendation:** **Supabase Auth on frontend** + PHP verifies JWT **OR** migrate API to Next.js.  
**Pragmatic v1:** Keep PHP session auth initially, **Supabase Postgres + Storage only** — swap auth in phase 3.

### 3.2 Application hosting

| Layer | v1 choice | v2 choice |
|-------|-----------|-----------|
| PHP API + router | **Railway** or **Render** (Docker or Nixpacks PHP) | Same or Next.js API |
| Frontend static HTML | Served by PHP host | Vercel static or Next.js |
| DNS | GoDaddy → host | Same |

### 3.3 Environment variables

```bash
# Database (Supabase Postgres — connection pooler)
DATABASE_URL=postgresql://postgres.[ref]:[password]@aws-0-us-west-2.pooler.supabase.com:6543/postgres

# Supabase (if using Auth + Storage from PHP/JS)
SUPABASE_URL=https://[ref].supabase.co
SUPABASE_ANON_KEY=...
SUPABASE_SERVICE_ROLE_KEY=...   # server only, never client

# App
APP_URL=https://envios.yourdomain.com
SESSION_SECRET=...              # if keeping PHP sessions
```

---

## 4. Implementation phases

### Phase 0 — Repo & hygiene (1 day)

- [ ] `git init`, `.gitignore` (`.env`, `assets/uploads/*`, `node_modules/`)
- [ ] Rename `ASSETS/` → `assets/` (all HTML refs already lowercase)
- [ ] Extract `config.php` → env vars + `.env.example`
- [ ] Fix P0 data bugs: caja import, accounting ENUM, `current_store_id()` fallback
- [ ] Create GitHub repo `suly-envios` (or your preferred name)
- [ ] **Do not push secrets**

**Acceptance:** `php -l` clean on changed files; local smoke login works.

### Phase 1 — Store-locked RBAC (3–5 days)

Aligns with vision + D1-A.

- [ ] Add `manager` to `users.role`
- [ ] `auth_require_admin()` on store CRUD, user CRUD
- [ ] New `api/users.php` — admin creates manager (email, password, store_id, role)
- [ ] Disable `switch_store` for non-admin (or remove from UI)
- [ ] Force `store_id` from session for managers on all write APIs
- [ ] Admin: reports-center + dashboard without store filter lock
- [ ] Fix barri-reports auto-assign: always run agency match; unassigned queue for Suly

**Acceptance:** Manager A cannot read/write store B (403). Suly sees all stores in one view.

### Phase 2 — Supabase Postgres migration (5–7 days)

**Prerequisite:** Supabase project decision (Section 0.2).

- [ ] Convert `schema.sql` + migrations → Postgres (`supabase/migrations/001_initial.sql`)
- [ ] Swap `includes/db.php` to `pgsql:` DSN via `DATABASE_URL`
- [ ] Fix MySQL-isms: `ON UPDATE CURRENT_TIMESTAMP` triggers, ENUM → CHECK or PG ENUM
- [ ] Seed Suly admin via migration (strong password, not `admin123`)
- [ ] Run migrations: `supabase db push` or SQL editor
- [ ] Data smoke test: login, create store, import one Barri PDF

**Acceptance:** Full happy path on Supabase Postgres, not localhost MySQL.

### Phase 3 — Supabase Auth + Storage (5–7 days)

- [ ] Move uploads to Supabase Storage buckets (private; signed URLs for download)
- [ ] Replace PHP session login with Supabase Auth **or** sync Supabase users ↔ `users` table
- [ ] Map `auth.users.id` → `users.id` / profile with `store_id`, `role`
- [ ] CSRF + cookie hardening (`secure`, `httponly`)

**Acceptance:** Login via Supabase; PDF stored in bucket; no local disk writes in production.

### Phase 4 — Deploy pipeline (2–3 days)

- [ ] **Railway/Render:** connect GitHub repo, set env vars, deploy PHP
- [ ] Health check URL (`/api/auth` GET or `/login`)
- [ ] **Optional Vercel:** static mirror or landing only; API stays on PHP host
- [ ] GoDaddy DNS A/CNAME to production host
- [ ] Disable Supabase free-tier pause risk → Pro if production 24/7

**Acceptance:** Production URL login works; one real PDF import end-to-end.

### Phase 5 — Bulk historical upload (3–5 days)

- [ ] Admin bulk UI: multi-select PDFs, progress bar
- [ ] `action=bulk_import` on barri-reports (array of parsed payloads)
- [ ] Dedup by report hash / date range
- [ ] Suly review screen for failed/unassigned rows

**Acceptance:** Upload 10+ files in one session; transaction counts match manual spot-check.

---

## 5. GitHub → deploy workflow

```bash
# One-time (after Phase 0)
gh repo create suly-envios --private --source=. --remote=origin
git add .
git commit -m "Initial commit: Suly Envios PHP app"
git push -u origin main

# Railway example (Phase 4)
# Connect repo in dashboard → set DATABASE_URL, SUPABASE_* → deploy on push

# Vercel (if/when Next.js or static)
# vercel link --project suly-envios
# vercel env pull
# vercel deploy --prod
```

**Branch strategy:** `main` = production; feature branches + PRs for RBAC and Supabase work.

---

## 6. Verification checklist (before 3-month upload)

- [ ] 3 test stores with real agency numbers
- [ ] 3 test managers, each locked to one store
- [ ] Suly admin sees aggregated Reports Center
- [ ] Sample PDF per company per store imports with expected txn count
- [ ] `node scripts/test-ria-parser.mjs` passes on fixture PDF
- [ ] No default passwords in production
- [ ] Uploads in Supabase Storage, not web-root
- [ ] Backups enabled (Pro) or export routine documented

---

## 7. Open decisions (need your input)

| # | Question | Options |
|---|----------|---------|
| **D-SB-1** | Supabase project | **Resolved:** new account **Suly Multiservicios**, 0/2 projects — create `suly-envios` when ready |
| **D-HOST-1** | Primary host | A) Railway/Render PHP + Supabase (recommended v1) B) Full Next.js on Vercel (longer) |
| **D-AUTH-1** | Auth timing | A) Supabase Auth in phase 3 B) PHP sessions until after bulk upload |
| **D-DOM-1** | Domain | e.g. `envios.suly…` or subdomain on existing site |

---

## 8. Immediate next steps (this week)

1. **You decide D-SB-1** (Supabase slot strategy).
2. **You decide D-HOST-1** (Railway vs Vercel rewrite).
3. **We execute Phase 0 + Phase 1** in repo (RBAC before cloud migration).
4. **Prepare onboarding spreadsheet:** stores + agency numbers + manager emails (from vision doc assignment).

---

## 9. References

- Vision design: `~/.gstack/projects/suly-envios-website/vision-alignment-design-2026-06-16.md`
- Supabase billing (2 free projects): https://supabase.com/docs/guides/platform/billing-on-supabase
- Supabase pricing: https://supabase.com/pricing
- Current schema: `schema.sql`, migrations `migrate-*.sql`
- Report parsers: `pages/reports.html`, `api/barri-reports.php`

---

*Subagent audits (2026-06-16): deployment stack map + NOVA gap list incorporated into Sections 0 and 2.*
