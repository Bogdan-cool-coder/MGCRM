# Realtime contract (Reverb) — Phase 7a

Server-side foundation for live updates over WebSockets. This document is the
handoff to **deploy-engineer** (run the `reverb` server + env) and the
**frontend** (Laravel Echo config + channel subscriptions).

- Package: `laravel/reverb` **v1.10.2**.
- Broadcaster: `reverb` connection (`config/broadcasting.php`), driven by
  `BROADCAST_CONNECTION=reverb`.
- Transport: `ShouldBroadcast` events fan out **over the existing Redis queue**
  (`QUEUE_CONNECTION=redis`, `default` queue) — the existing `queue-worker`
  container already consumes `default`. No `ShouldBroadcastNow` is used, so a
  slow socket never blocks the request.
- Auth endpoint: `POST /broadcasting/auth`, behind `auth:sanctum` (the SPA sends
  its Bearer token). Registered in `bootstrap/app.php` via `withBroadcasting()`.

---

## 1. Channels

All channels are **private** (`private-` prefix on the wire; subscribe without
the prefix via Echo `.private('...')`). Authorization lives in
`routes/channels.php` and reuses the existing Policy `view` gate +
`VisibilityResolver` department subtree — a subscription can never leak a record
the REST layer would 403/404.

| Channel pattern | Purpose | Authorized when |
|---|---|---|
| `user.{userId}` | personal: my tasks + notifications | `userId === auth id` |
| `deal.{dealId}` | live deal-card feed + patches | `DealPolicy::view` (owner / dept-peer / All) |
| `company.{companyId}` | live company-card feed + patches | `CompanyPolicy::view` |
| `contact.{contactId}` | live contact-card feed + patches | `ContactPolicy::view` |
| `dept.{departmentId}.deals` | live board / kanban list | scope All **or** dept in user's subtree |
| `dept.{departmentId}.tasks` | live team task list | scope All **or** dept in user's subtree |
| `dept.{departmentId}.contacts` | live contact/company list | scope All **or** dept in user's subtree |

Notes:
- **Department anchor.** Deal, Company and Activity carry `department_id` and
  broadcast on their own department. **Contact has no department column** — its
  list channel is anchored on the **owner user's department** (resolved
  server-side at dispatch and carried in the payload). A contact whose owner has
  no department broadcasts only on its `contact.{id}` entity channel.
- **All-scope roles** (admin / director / lawyer) may subscribe to any
  `dept.*` list channel. Own-scope roles (accountant / cfo) and a
  department-less manager match only their own subtree (empty → nothing foreign).

---

## 2. Events

Every event: `broadcastAs` = the wire name below; `broadcastWith` = a lean,
PII-safe payload (ids + type + minimal fields — **no note/body text, no names**).
The frontend refetches the record or patches from these fields.

### Activity events — fan out to `deal|company|contact.{targetId}` + `user.{responsibleId}` + `dept.{deptId}.tasks`

| `broadcastAs` | When | Payload keys |
|---|---|---|
| `activity.created` | note/task/meeting created | `id, kind, status, target_type, target_id, responsible_id, department_id` |
| `activity.status_changed` | completed / reopened / rejected | above **+** `from, to` (status values) |
| `activity.updated` | field edit (title/kind/due/priority/responsible) | same as `activity.created` |
| `activity.deleted` | deleted | `id, target_type, target_id, responsible_id, department_id` |

A **standalone** (target-less) personal task has no entity channel — it only
hits `user.{responsibleId}` (+ dept.tasks if it has a department).

### Deal events — fan out to `deal.{id}` + `dept.{deptId}.deals`

| `broadcastAs` | When | Payload keys |
|---|---|---|
| `deal.created` | created (incl. inbound) | `id, pipeline_id, stage_id, company_id, owner_user_id, department_id, amount` |
| `deal.updated` | field/amount/owner edit (NOT a stage move) | same as `deal.created` |
| `deal.stage_changed` | stage move committed | above **+** `from_stage_id, to_stage_id` |
| `deal.deleted` | soft-deleted | `id, department_id` |

`amount` is **integer kopecks** (format on the frontend). A deal with no
department only broadcasts on `deal.{id}`.

### Company events — fan out to `company.{id}` + `dept.{deptId}.contacts`

| `broadcastAs` | When | Payload keys |
|---|---|---|
| `company.created` | created | `id, owner_user_id, department_id` |
| `company.updated` | edited | same |
| `company.deleted` | soft-deleted | `id, department_id` |

### Contact events — fan out to `contact.{id}` + `dept.{ownerDeptId}.contacts`

| `broadcastAs` | When | Payload keys |
|---|---|---|
| `contact.created` | created | `id, owner_id, department_id` |
| `contact.updated` | edited | same |
| `contact.deleted` | soft-deleted | `id, department_id` |

`department_id` here is the **owner's** department (Contact has no column of its
own).

---

## 3. Environment variables

### Server (`src/.env` — main writes the real secret values)

| Key | Example / note |
|---|---|
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID` | app id — **secret**, generate |
| `REVERB_APP_KEY` | app key — public (goes to the browser), generate |
| `REVERB_APP_SECRET` | signs HTTP-publish + channel auth — **secret**, generate |
| `REVERB_HOST` | host the **app** uses to reach the reverb server (docker service name, e.g. `reverb`) |
| `REVERB_PORT` | `8080` inside the compose network |
| `REVERB_SCHEME` | `http` inside the network (TLS terminated by the proxy) |

The reverb **server** container additionally reads `REVERB_SERVER_HOST`
(`0.0.0.0`) / `REVERB_SERVER_PORT` (`8080`) and the `REDIS_*` block (only when
`REVERB_SCALING_ENABLED=true`, for multi-node fan-out).

### Browser-facing (frontend build — **frontend agent consumes**)

| Key | Example / note |
|---|---|
| `VITE_REVERB_APP_KEY` | mirrors `REVERB_APP_KEY` (public) |
| `VITE_REVERB_HOST` | public wss host, e.g. `mgcrm.macroglobal.tech` |
| `VITE_REVERB_PORT` | `443` (through the proxy) |
| `VITE_REVERB_SCHEME` | `https` |

**Never expose** `REVERB_APP_SECRET` / `REVERB_APP_ID` to the browser.

---

## 4. Ops / deploy notes (deploy-engineer)

- Add a **`reverb` container** running `php artisan reverb:start --host=0.0.0.0
  --port=8080` (long-running, like the existing bot/scheduler containers). It
  terminates the client WebSocket and must be reachable by the app at
  `REVERB_HOST:REVERB_PORT` (HTTP publish) and by the browser via the proxy at
  `wss://VITE_REVERB_HOST` (typically a Traefik/nginx route to the container's
  `/app` + `/apps` WS paths).
- The existing **`queue-worker`** already consumes the `default` queue that
  broadcasts ride — no queue change needed. If broadcast volume grows, a
  dedicated worker on `default` is an option.
- End-to-end delivery (event → queue → reverb → browser) needs the running
  server and is a **deploy/QA** verification step (not covered by the PHP unit
  suite, which forces `BROADCAST_CONNECTION=null`).

---

## 5. Tests (server side)

- `tests/Unit/Realtime/BroadcastEventContractTest.php` — every priority event
  implements `ShouldBroadcast`, exposes the agreed `broadcastAs`, and routes to
  the exact channel set + PII-safe payload above.
- `tests/Feature/Realtime/ChannelAuthorizationTest.php` — the `channels.php`
  callbacks grant owner / dept-peer / All-scope and deny outsiders, via the real
  Policy + `VisibilityResolver`.
