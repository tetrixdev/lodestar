# MCP tools

The agent-facing surface of Lodestar: the tools registered on
`App\Mcp\Servers\LodestarServer` and exposed at `POST /mcp` behind
`auth:sanctum`. This doc is the family roster — one row per registered tool. It
is held honest by the drift guard `tests/Feature/Mcp/ToolsDocMirrorTest.php`,
which asserts this table matches `LodestarServer::$tools` both directions.

## The shared contract

Every tool extends the abstract `App\Mcp\Tools\LodestarTool`, which holds the
tenancy helpers (`currentUser`, `ownedProject`, `ownedTask`, `ownedReview`,
`ownedSession`) plus `Project::accessibleBy`. **Tenancy is enforced in the tool
layer, identical to the web controllers:** a token resolves to one user, and a
tool only ever sees data reachable from that user (their own projects, or a
project of a team they are on). A tool that addresses someone else's data gets
nothing back (reads) or a rejection (writes) — never a leak.

Each concrete tool carries `#[Name(...)]` + `#[Description(...)]` attributes and
implements `handle(Request): Response` and `schema(JsonSchema): array`.

## Client-roster-refresh caveat

An MCP client caches the tool roster it fetched when the session opened. A tool
that is freshly deployed (newly added to `$tools`) will therefore be **absent
from an already-open agent session's roster** until that client reconnects and
re-lists. That is a client cache effect, **not** a registration gap — if the tool
is in `$tools` and this table, it is registered. Reconnect the session to see it.

## The roster

Groups: **data** (read/write the board), **read** (read-only navigation), **repo**
(link repos), **loop** (drive the lifecycle).

| Tool | Class | Group | Purpose |
|------|-------|-------|---------|
| `list_projects` | `ListProjectsTool` | data | List your projects, their linked repos, and your GitHub connections. |
| `upsert_project` | `UpsertProjectTool` | data | Create or update a project. |
| `upsert_task` | `UpsertTaskTool` | data | Create or update a task (a deliverable is required on create). |
| `upsert_deliverable` | `UpsertDeliverableTool` | data | Create or update a deliverable. |
| `get_task` | `GetTaskTool` | read | Read one or more tasks in full by id (batch). |
| `list_tasks` | `ListTasksTool` | read | List tasks as compact board rows, filterable by project/deliverable/status/phase. |
| `list_deliverables` | `ListDeliverablesTool` | read | List a project's deliverables as compact rows, filterable by status/phase. |
| `get_deliverable` | `GetDeliverableTool` | read | Read one deliverable in full (concept/body, questions, child tasks, reviews). |
| `list_sessions` | `ListSessionsTool` | read | List a project's work-session history, newest first. |
| `get_project` | `GetProjectTool` | read | Read one project's overview (repos, deliverable/task counts by phase, recent sessions). |
| `upsert_session` | `UpsertSessionTool` | data | Create or update a work-session log entry. |
| `link_repository` | `LinkRepositoryTool` | repo | Attach a GitHub repo to a project through a connection. |
| `unlink_repository` | `UnlinkRepositoryTool` | repo | Detach a repo from a project. |
| `create_review` | `CreateReviewTool` | data | Create a review and hand back the URL a human opens. |
| `upsert_review_section` | `UpsertReviewSectionTool` | data | Append or update a section of a review walkthrough. |
| `add_finding` | `AddFindingTool` | data | Record a finding against a review section. |
| `get_review` | `GetReviewTool` | data | Read a review back (sections, findings, decisions). |
| `claim_work` | `ClaimWorkTool` | loop | Atomically claim the next available unit of work (a deliverable or a task). |
| `claim_task` | `ClaimTaskTool` | loop | Atomically claim a task (next queued, or by id). |
| `get_playbook` | `GetPlaybookTool` | loop | Compose the phase prompt for a claimed unit (incl. the `main` bootstrap). |
| `propose_playbook_change` | `ProposePlaybookChangeTool` | loop | Propose a new playbook version (always proposed, never live). |
| `remember` | `RememberTool` | loop | Capture a durable learning as a proposed playbook-layer edit. |
| `advance_task` | `AdvanceTaskTool` | loop | Move a task along a legal transition. |
| `advance_deliverable` | `AdvanceDeliverableTool` | loop | Move a deliverable along a legal transition. |
| `report` | `ReportTool` | loop | Log a work-session against the task it reports on. |
