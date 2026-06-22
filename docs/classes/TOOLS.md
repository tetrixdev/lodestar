# The MCP tool family (`LodestarTool`)

The agent-facing surface. Every MCP tool is a child of the abstract
**`LodestarTool`** (`app/Mcp/Tools/LodestarTool.php`, which extends
`Laravel\Mcp\Server\Tool`) and is registered on `LodestarServer`
(`app/Mcp/Servers/LodestarServer.php`). This doc is the family's contract + the
full roster; `ToolsDocMirrorTest` fails if a tool is added without a row here.

> **How to read:** a tool is one **invokable operation** scoped to the calling
> token's user. The base class gives every tool the tenancy helpers; each child
> declares its params in `schema()` and does its work in `handle()`.

## The contract — what `LodestarTool` gives every child

- **`handle(Request $request): Response`** — the operation (from `laravel/mcp`).
- **`schema(JsonSchema $schema): array`** — the tool's parameters.
- **`#[Name]` / `#[Description]`** — the MCP tool name (snake_case) + its blurb.
- **Tenancy helpers** (the reason the base exists — every read/write is scoped to
  the user, mirroring the web controllers' `accessibleBy` rule):
  - `currentUser(Request): User`
  - `ownedProject(Request, int|string $ref): ?Project` — by id or slug
  - `ownedTask(Request, int): ?Task`
  - `ownedReview(Request, int): ?Review`
  - `ownedSession(Request, int): ?WorkSession`
  - `resolveAppUrl(string): string` — swaps the `<lodestar-url>` placeholder for the deployment URL.

Shared invariants across the family: **atomic claims** (`SKIP LOCKED`), **legal
transitions only** on lifecycle moves, **compare-and-swap** (`base_hash`) on
playbook edits, and **playbook proposals are never made live by an agent**.

## Data tools — read/write the board

| Tool | Purpose | Key params | Writes |
|------|---------|-----------|--------|
| `list_projects` | List accessible projects (owned + team) with repos + the user's GitHub connections. | — | — |
| `upsert_project` | Create/update a project (id to update; slug unique per user). | id, name, slug, description, primary_goal, repos, **stack** | Project |
| `upsert_task` | Create/update a task under a deliverable (deliverable required on create; body vs plan; entry status decided last). | project, id, deliverable, corrective, depends_on, title, body(+summary), plan(+summary), status | Task, TaskDependency |
| `upsert_deliverable` | Create/update a deliverable (scope = concept + body; status derives from its tasks). | project, id, title, category, base_branch, concept(+summary), body(+summary), questions | Deliverable, DeliverableQuestion |
| `get_task` | Read tasks by id (batch) — full contents, status, branch, deps, reviews; unknowns reported in `missing`. | task_ids | — |
| `upsert_session` | Create/update a work-session log; optionally link a task. | project, id, task_id, title, slug, body(+summary), occurred_on | WorkSession |
| `link_repository` | Link a GitHub repo to a project through a connection (captures default branch). | project, repo, connection | Repository, pivot |
| `unlink_repository` | Unlink a repo from a project (the repo row stays). | project, repo | pivot |
| `create_review` | Create/refresh a review + pull the GitHub comparison; returns URL + uncovered files. | project, review_id, scope, deliverable, review_type, title, repo, base_ref, head_ref, intro, task_ids | Review, ReviewFile, ReviewSection |
| `upsert_review_section` | Add/update one walkthrough section (mode + kind + covered files); frozen once handed to a human. | review_id, id, title, mode, kind, position, context, link, checks, manual_steps, status, files | ReviewSection, pivot |
| `add_finding` | Raise one finding in a review section (severity info/minor/major/critical); draft reviews only. | section_id, title, detail, severity | ReviewFinding |
| `get_review` | Read a full review — metadata, ordered sections, files, tasks, findings. | review_id | — |

## Read tools — board-read & navigation (read-only)

The compact-list / single-read pair an interactive agent reaches for: scan what
exists with a `list_*`, then pull the full record with the matching `get_*`. All
are read-only and access-scoped to your projects.

| Tool | Purpose | Key params | Writes |
|------|---------|-----------|--------|
| `list_tasks` | List tasks as COMPACT board rows (id, status, phase, deliverable, blocked, human_gate) — not the body/plan (use `get_task`). | project, deliverable, status, phase | — |
| `list_deliverables` | List a project's deliverables as COMPACT rows (status, phase, branches, open-questions + task counts) — not the concept/body (use `get_deliverable`). | project, status, phase | — |
| `get_deliverable` | Read one deliverable in full: concept/body, branches, open questions, child tasks (compact) and reviews. The `get_task` counterpart. | deliverable_id | — |
| `list_sessions` | List a project's work-session history, newest first (id, title, occurred_on, the task it reports on). | project, task_id, limit | — |
| `get_project` | Read one project's overview: name/goal/repos, deliverable + in-flight task counts by phase, and recent sessions. The single-project view behind `list_projects`. | project | — |

## Loop tools — drive the lifecycle

| Tool | Purpose | Key params | Writes |
|------|---------|-----------|--------|
| `claim_work` | Atomically claim the next unit (task preferred, else deliverable) for the loop. | project, type, agent_id | Task/Deliverable (→ working) |
| `claim_task` | Atomically claim a specific or next `ready_*` task; returns its phase. | task_id, project, phase, agent_id | Task (→ working) |
| `get_playbook` | Compose the phase prompt (plan/develop/ai_review/merge/main) or resolve a named key; returns body + layers. | task_id, deliverable_id, phase, key, project | — |
| `propose_playbook_change` | Propose a new version of a playbook layer (personal/team/project) — **always proposed**, never live. | scope, key, title, summary, body, mode, note, base_hash, team_id, project | PlaybookVersion (proposed) |
| `remember` | Capture a durable learning as a proposed layer entry (personal/project). | learning, scope, key, project, base_hash, work_session_id | PlaybookVersion (proposed) |
| `advance_task` | Move a task along a legal transition; coverage-gates human_review; clears the claim on exit. | task_id, to | Task, Review |
| `advance_deliverable` | Move a deliverable along its funnel (gates: plan approval, child completion, coverage). | deliverable_id, to | Deliverable |
| `report` | Log a work-session for what an agent did on a task/project. | task_id, project, title, body(+summary), occurred_on | WorkSession |

_Family size: 25 tools. The roster is discovered from `LodestarServer::$tools` by
the guard — add a tool, add a row._

### Single-sourced body/plan/summary format

The `upsert_task` (and `upsert_deliverable`) `body` / `body_summary` / `plan` /
`plan_summary` descriptions are NOT written inline — they pull from
`App\Support\TaskSpec` constants, the one source of truth for the format. The
same constants are composed into the seeded `plan` / `develop` playbooks (see
`SystemPlaybookSeeder`), so the tool descriptions and the playbook prose can
never drift. `TaskSpec::ARCHITECTURE_RULE` carries the non-coder rule
(Technical-architecture is written for a reviewer who has not read the code).
`TaskSpecSingleSourceTest` guards both sides.
