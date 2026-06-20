# Data model — Lodestar

> **How to read:** the **Tables** and **Invariants** are the summary you read
> first; the **Field reference** is the exact, per-column picture of every app
> table; the **Diagram** is the relationship overview (and renders as an ER
> diagram in the UI). This file **mirrors the built schema**: every table that
> exists in `www/database/migrations/`. A test
> (`tests/Feature/SchemaMirrorTest.php`) parses the Field-reference tables and
> asserts they match the live schema column-for-column, so this doc cannot
> silently drift.

Lodestar is the home base for software work: **Projects** hold **Tasks** (kanban
cards that ride a 13-state lifecycle), **WorkSessions** (a work-log), and
**Reviews** (a change reviewed against a base, walked through as ordered
**ReviewSections**). **Playbooks** are layered prompt slots and **PlaybookVersions**
their versioned bodies — a phase prompt is composed across scopes. Everything is
**multi-tenant by ownership** — a Project belongs to a User, and everything else
reaches its owner through that Project; AI agents reach the same data through MCP,
authenticated by a per-machine **PersonalAccessToken** (Sanctum).

## Tables (the nouns)

- **Project** — a group of repos with a shared goal, owned by a `user`. The home
  base its tasks, work-sessions and reviews all hang off. `name` + a per-user
  unique `slug`; an optional `description` and `primary_goal`. Its repositories
  are first-class (the `repositories` table via the `project_repository` pivot).
  A project belongs to a **Team** (`team_id`) or is **personal** (`team_id` null).
- **Team** — a group that shares projects + a managed playbook set. `owner_user_id`;
  `allow_personal_instructions` (when off, the personal playbook layer is dropped so
  changes go through the team). Members + their `can_approve_prompts` rights live
  in the `team_user` pivot (the owner is always a member with approval). The
  `project_user` pivot does the same for project-level playbook approval.
- **Task** — a kanban card. `status` is one of the **13 lifecycle states** (see
  Invariants); `position` orders cards within a single status; `category` is a
  free-text grouping prefix (e.g. `mcp`, `infra`); `body` is the card detail.
  `status_changed_at` records when the card last entered its current status (the
  "Nh in status" timer) and is stamped automatically on every status change.
  `claimed_by` / `claimed_at` record which agent currently holds a working
  (`*-ing`) card and since when — set by the atomic MCP claim, cleared when the
  card moves on. There is no lease column: a stuck card is freed by a human, not
  a reaper (see Invariants).
- **WorkSession** — a work-log entry (the running history a project kept).
  `title` + `slug`, a markdown `body`, and `occurred_on` (the date the work
  happened), and an optional `task_id` (a session can log against a specific
  task). Named `work_sessions` so it never collides with Laravel's framework
  `sessions` table.
- **Review** — a change reviewed against a base (e.g. a branch vs main). Carries
  `base_ref`/`head_ref` (the intended comparison), a `status` (draft / in_review
  / done), an `intro` preamble, and `assigned_to_user_id` — the human currently
  holding the review. A human must **atomically self-assign** a review before
  they may sign off its sections (the human mirror of an agent claiming a task).
  `concluded_at` records when the human applied the outcome (the "responded"
  time, paired with `created_at` as "requested"). A review covers many tasks and
  a task can appear in many reviews (the `review_task` pivot) — but a task may
  have only **one open review** (no `outcome` yet) at a time; create_review
  refuses to open a second while one is still awaiting a verdict.
- **GithubConnection** — one GitHub account/token a user has linked. `label`
  ("work"/"personal"), the resolved `github_login`, and the `token` (stored
  **encrypted**). A user may have several; each Repository is read through one.
- **Repository** — a GitHub repo (`full_name` = "owner/name", `default_branch`),
  read through a `github_connection`. Linked to projects many-to-many via
  `project_repository`. A Review's comparison is within one Repository.
- **ReviewFile** — one file the review's comparison changed, as reported by the
  **GitHub compare API** (`repo` + `base_ref`…`head_ref` on the Review). `path`,
  `status` (added/modified/removed/renamed), `old_path` for renames, and
  `position` (GitHub's own ordering, so the UI file-tree matches GitHub). This set
  is the **ground truth** the coverage guard checks against — the AI never
  supplies it.
- **ReviewSection** — one ordered step of a review walkthrough — the data behind
  the HTML walkthrough screen. `position` orders the sections; `mode` is the
  review mode (`skip` / `behavioural` / `direct` / `direct_doc` / `mirror_guard`);
  `context` rebuilds the reviewer's knowledge; `link` is what to open (a doc /
  file / route); `checks` is a JSON list of "what to confirm"; `status`
  (`open` / `signed_off`) + `note` carry the human's per-section sign-off.

- **Playbook** — a *slot*: one addressable layer of a composed prompt, identified by
  (`scope`, `owner`, `key`). `scope` is `system` (ours, `owner` null), `team`,
  `personal` (owner = a User) or `project`. `key` is a phase (`main` / `plan` /
  `develop` / `ai_review` / `merge`) or an arbitrary named key. The slot is pure
  identity — one per (scope, owner, key); all content lives on its versions.
- **PlaybookVersion** — one version of a slot's content. A slot keeps its full
  history; exactly one version is `active` (the one composition uses). It carries
  `title`, `summary`, `mode` (`append` = add onto the layers above it, or
  `overwrite` = discard them and start from this body) and `body` — so a proposal
  changes all of them together and the diff shows it. `status` is `proposed`
  (awaiting a human approver), `active`, `archived` (a superseded active) or
  `rejected`. `author_user_id` + `proposed_by_ai` record who wrote it; `note` is
  the proposal message. Approving a proposed version archives the prior active one.
- **ProjectSecretRequirement** — a `key` a project needs in its environment to
  run (the manifest), with a `description` and `is_secret` flag. Not sensitive —
  just the name; managed by project approvers.
- **PersonalSecret** — a user's own `value` for a `key`, **encrypted at rest**
  (`encrypted` cast). `project_id` null = applies to any project; a project-scoped
  row overrides the global one there. Delivered only to the owning user via the
  out-of-MCP secrets endpoint, never through an MCP tool.
- **ProjectTool** — a `program` the agent installs/verifies (`check` detects it,
  `run` installs it) or a `command` (a small reusable script — `run` is the body)
  the agent writes into its workspace `bin/`. Approver-managed (it runs shell on
  the agent's machine); fetched via the out-of-MCP tools manifest.
- **PersonalAccessToken** — Sanctum's API token (one per machine/agent). The MCP
  server authenticates each request from the `Authorization: Bearer` token and
  resolves the tenant (`tokenable` = the User); `name` is the machine label
  (also the default `claimed_by`), `abilities` carries the `agent` scope.

**Pivots**

- **review_task** — the many-to-many link between a Review and the Tasks it
  covers. Unique `(review_id, task_id)`; both sides cascade-delete, so the link
  disappears with either end.
- **project_repository** — the many-to-many link between a Project and the
  Repositories it spans (its "stack"). Unique `(project_id, repository_id)`; both
  sides cascade-delete.
- **review_file_section** — the many-to-many link between a ReviewFile and the
  ReviewSections that cover it. A file may be covered by several sections and must
  be covered by at least one (the coverage guard). Unique
  `(review_file_id, review_section_id)`; both sides cascade-delete.

(Laravel scaffolding — `sessions`, `cache`, `jobs`, etc. — is standard and
omitted here, except `users` (the ownership root) and `personal_access_tokens`
(the MCP auth boundary), which the diagram draws.)

## Invariants

These are the rules the column list alone won't tell you:

- **Multi-tenant by access, through the Project.** Tasks, WorkSessions and
  Reviews reach their tenant through their Project. A project is reachable by its
  **owner** or by a **member of its team** — the single rule lives in
  `Project::isAccessibleBy(User)` / `scopeAccessibleBy`, which every controller
  and MCP tool uses (403 otherwise). Personal projects (`team_id` null) are
  owner-only, so this is backward-compatible with the original `user_id` check.
  `(user_id, slug)` is unique, so a slug is unique *per user*, not globally.
- **A Task's status is one of 13 — 12 live + `cancelled`.** The live pipeline,
  in order: `new → ready_for_planning → planning → plan_review → ready_for_dev →
  developing → ready_for_ai_review → ai_review → human_review → approved →
  merge_deploy → done`. `cancelled` is the archive (a soft-delete; there is no
  hard delete). The board groups the 12 live states into **5 phase columns**
  (Backlog · Plan · Build · Review · Ship) and colours each card by the **actor**
  it waits on (needs-human / queued / ai-working / done / archived).
- **Status moves are legal-only.** A Task may only move to a status in its
  allowed-transition set (`Task::TRANSITIONS`); an illegal jump is rejected (422
  / validation error). The transition map is forward · back · cancel per state,
  with `cancelled` restoring to `new`. The lists, phases, actors, labels and
  transition map all live as constants on `App\Models\Task`.
- **`status_changed_at` is stamped automatically.** A `saving` model hook stamps
  it whenever `status` is dirty (or on first save), so every code path — the
  board, tinker, a future agent loop — keeps the timer honest. A non-status edit
  never re-stamps it.
- **`position` orders within a single status, not across the board.** A new card
  lands at `max(position) + 1` within its status; intra-status drag rewrites
  `position` for exactly the cards in that status. Reordering never changes
  status — lifecycle moves go through the transition path so only legal moves
  are allowed.
- **A task is claimed by a single agent at a time, atomically.** An agent claims
  the next `ready_*` card over MCP via a **conditional UPDATE guarded on the old
  status** (`WHERE status = :queue_state`) — the same check-and-set shape as the
  review claim — so two concurrent agents can never both win a card. On Postgres
  the claim query also uses `FOR UPDATE SKIP LOCKED` so concurrent claimers skip
  past each other's rows instead of contending. The claim flips `ready_* → *-ing`
  and stamps `claimed_by` + `claimed_at`.
- **No lease, no reaper — a human frees a stuck card.** We deliberately did *not*
  add a lease / heartbeat / auto-reclaim. The happy flow is expected; if an agent
  crashes mid-task, a human presses **Release** on the board, which returns the
  `*-ing` card to its `ready_*` queue and clears the claim so the loop re-picks it.
- **Playbook composition is server-side.** `get_playbook` composes a phase prompt from
  the active version of each scope's slot, in order system → team → project →
  personal (append, or an `overwrite` layer wiping everything above it). Personal
  is last so a person always has the final say — and can test a change locally —
  unless the team sets `allow_personal_instructions = false`, which drops the
  personal layer. Named (non-phase) keys don't compose — they resolve to the
  most-specific scope. So editing the system layer updates every loop on its next
  call, with no client change.
- **Every changed file must be covered by a section (the coverage guard).** A
  review built from a GitHub comparison stores its changed files as `review_files`
  (fetched server-side from GitHub — never the AI's claim). Each ReviewSection
  declares which files it covers (`review_file_section`); a file may sit in several
  sections but must sit in **at least one**. `Review::coverage()` computes the
  uncovered set, and `advance_task → human_review` is **rejected** while any linked
  review has uncovered files — so a review can only reach a human once it provably
  accounts for every changed file. A review with no files (doc-only) is trivially
  complete.
- **A review is claimed by a single human at a time.** `assigned_to_user_id` is
  set by a **conditional UPDATE guarded on `WHERE assigned_to_user_id IS NULL`**
  (`Review::claimFor`), making claim a single atomic check-and-set — no
  read-then-write race, no double-assignment. Release is guarded the same way
  (`WHERE assigned_to_user_id = :holder`), so a non-holder can never clear
  another reviewer's claim. Section sign-off is gated on holding the review.
- **The walkthrough is the section list, top-to-bottom.** A Review's sections
  are ordered by `position`; the screen rebuilds context as the reviewer
  descends, and the progress bar / "ready" banner are driven by how many
  sections are `signed_off`.
- **The review outcome drives the task.** Each ReviewSection carries a human
  `decision` (`approved` / `changes_requested`), distinct from its sign-off. Once
  **every** section is decided the human applies the outcome (`reviews.conclude`):
  the verdict is `changes_requested` if any section requests changes, else
  `approved`. On **approve** the review's `outcome` is set to `approved`, its
  `status` to `done`, and each linked task at `human_review` is transitioned to
  `approved`. On **changes** the `outcome` is `changes_requested`, the rework brief
  (each changes_requested section's note + every `must_fix` finding, as markdown)
  is written to each linked task's `rework_notes`, and each linked task at
  `human_review` is transitioned to `ready_for_dev` (a legal transition added for
  exactly this rework loop). Both the conclude action and per-finding triage are
  gated on holding the review.

## Field reference

The **per-table column reference** — the exact, column-for-column picture of
every app table. **This is the source of truth the drift test checks**: the
`### \`table_name\`` heading is the live table name, and the **Field** column
lists every real column (the test asserts both directions — nothing missing,
nothing phantom). The **Diagram** below is the relationship overview, kept in
sync as a convenience but the tables here are authoritative.

Three things are **deliberately** omitted from every table (same omissions the
diagram lists) and ignored by the drift test: **timestamps**
(`created_at` / `updated_at`, on every table); **indexes / FK indexes** (the
load-bearing ones are stated in *Invariants*); and **`unsigned`** integer
qualifiers (this app runs on Postgres, which has no unsigned int type, so
`unsignedBigInteger` etc. show as their base type). Type / Constraints columns
are informational — the test checks Field **names** only.

### `projects`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| user_id | bigint | FK → users | The owning account. `(user_id, slug)` is unique, so a slug is unique per user, not globally. |
| name | string | not null | The project's display name. |
| slug | string | not null · unique per user | URL/identity slug, unique within the owner's projects. |
| description | text | nullable | Optional longer description of the project. |
| primary_goal | text | nullable | The project's headline goal — what the stack of tasks is driving toward. |
| team_id | bigint | FK → teams · nullable | The owning team, or null for a **personal** project (owner-only access). |

### `teams`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| name | string | not null | The team's display name. |
| owner_user_id | bigint | FK → users | The team owner (always a member, always able to approve prompts). |
| allow_personal_instructions | boolean | not null · default true | When false, the personal playbook layer is dropped from composition so changes route through the team. |

### `team_user`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| team_id | bigint | FK → teams | The team. Pivot keeps the singular Laravel name. |
| user_id | bigint | FK → users | The member. |
| role | string | not null · default `member` | `owner` or `member`. |
| can_approve_prompts | boolean | not null · default false | Whether this member may approve proposed playbook versions for the team. |

### `project_user`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The project. |
| user_id | bigint | FK → users | The member. |
| can_approve_prompts | boolean | not null · default false | Whether this member may approve project-scope playbook versions. |

### `tasks`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The owning project (the tenant root). |
| title | string | not null | The card title. |
| category | string | nullable | Free-text grouping prefix (e.g. `mcp`, `infra`). |
| body | text | nullable | The card detail, markdown. |
| status | string | not null | One of the **13 lifecycle states** (see Invariants). |
| position | integer | not null · default 0 | Orders cards **within a single status** (not across the board). |
| status_changed_at | timestamp | nullable | When the card last entered its current status (the "Nh in status" timer); stamped automatically on every status change. |
| claimed_by | string | nullable | The agent currently holding a working (`*-ing`) card; set by the atomic claim, cleared when the card moves on. |
| claimed_at | timestamp | nullable | When the card was claimed. |
| priority | string | not null · default `normal` | `low` / `normal` / `high` / `urgent`. |
| start_date | date | nullable | Gantt start date. |
| due_date | date | nullable | Deadline. |
| branch | string | nullable | The development branch for the card. |
| plan | text | nullable | The planning artifact (markdown), produced in the plan phase. |
| rework_notes | text | nullable | What a review sent back — the change request brief written on `changes_requested`. |
| body_summary | text | nullable | TL;DR of `body`; required when `body` is set. |
| plan_summary | text | nullable | TL;DR of `plan`; required when `plan` is set. |

### `work_sessions`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The owning project. |
| title | string | not null | The session title. |
| slug | string | not null | URL/identity slug. |
| body | text | nullable | The work-log entry, markdown. |
| occurred_on | date | nullable | The date the work actually happened. |
| task_id | bigint | FK → tasks · nullable | The task this session logs against, if any. |
| body_summary | text | nullable | TL;DR of `body`; required when `body` is set. |

> Named `work_sessions` so it never collides with Laravel's framework `sessions` table.

### `reviews`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The owning project. |
| title | string | not null | The review's display title. |
| base_ref | string | nullable | The base of the comparison (e.g. `main`). |
| head_ref | string | nullable | The head of the comparison (e.g. `feat/x`). |
| status | string | not null · default `draft` | `draft` / `in_review` / `done`. |
| intro | text | nullable | The review preamble. |
| assigned_to_user_id | bigint | FK → users · nullable | The human currently holding the review (atomic self-assign before sign-off). |
| repository_id | bigint | FK → repositories · nullable | The repository the comparison runs within. |
| outcome | string | nullable | `approved` / `changes_requested`, set when the human concludes the review. |
| concluded_at | timestamp | nullable | When the human applied the outcome (the "responded" time). Null while the review is still open; `created_at` is the "requested" time. |
| base_sha | string | nullable | The resolved commit SHA of `base_ref`. |
| head_sha | string | nullable | The resolved commit SHA of `head_ref`. |

### `review_files`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| review_id | bigint | FK → reviews | The owning review. |
| path | string | not null | The changed file's path. |
| status | string | not null | `added` / `modified` / `removed` / `renamed`, per the GitHub compare API. |
| old_path | string | nullable | The previous path, for renames. |
| position | integer | not null · default 0 | GitHub's own ordering, so the UI file-tree matches GitHub. |
| patch | text | nullable | The unified diff; null for binary / oversized files. |
| additions | integer | not null · default 0 | Lines added. |
| deletions | integer | not null · default 0 | Lines removed. |

> The set of `review_files` is the **ground truth** the coverage guard checks against — fetched server-side from GitHub, never supplied by the AI.

### `review_file_section`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| review_file_id | bigint | FK → review_files | The changed file. Pivot keeps the singular name. |
| review_section_id | bigint | FK → review_sections | A section that covers the file. Unique `(review_file_id, review_section_id)`; a file may be covered by several sections and must be covered by at least one. |

### `review_sections`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| review_id | bigint | FK → reviews | The owning review. |
| position | integer | not null · default 0 | Orders the sections in the walkthrough (top-to-bottom). |
| title | string | not null | The section title. |
| mode | string | not null | The review mode: `skip` / `behavioural` / `direct` / `direct_doc` / `mirror_guard`. |
| context | text | nullable | Rebuilds the reviewer's knowledge for this step. |
| link | string | nullable | What to open (a doc / file / route). |
| checks | jsonb | nullable | A JSON list of "what to confirm". |
| status | string | not null · default `open` | `open` / `signed_off` — the human's per-section sign-off. |
| note | text | nullable | The human's comment / change request for the section. |
| decision | string | nullable | The human verdict: `approved` / `changes_requested`, distinct from sign-off; drives the review outcome. |

### `review_task`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| review_id | bigint | FK → reviews | The review. Pivot keeps the singular name. |
| task_id | bigint | FK → tasks | A task the review covers. Unique `(review_id, task_id)`; both sides cascade-delete. |

### `review_findings`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| review_section_id | bigint | FK → review_sections | The section the finding was raised within. |
| title | string | not null | The finding's headline. |
| detail | text | nullable | The realistic scenario + impact — what makes a review a conversation, not a checklist. |
| severity | string | not null · default `minor` | `info` / `minor` / `major` / `critical`. |
| status | string | not null · default `open` | Human triage: `open` / `must_fix` / `approved` / `dismissed`. `must_fix` findings feed the rework brief. |
| position | integer | not null · default 0 | Orders findings within a section. |

### `users`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key — the ownership root every tenant reaches through. |
| name | string | not null | Display name. |
| email | string | not null · unique | Login email. |
| email_verified_at | timestamp | nullable | When the email was verified. |
| password | string | not null | Hashed password. |
| remember_token | string | nullable | Laravel "remember me" token. |

### `playbooks`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| key | string | not null | A phase (`main` / `plan` / `develop` / `ai_review` / `merge`) or an arbitrary named key. |
| title | string | not null | The slot's display title. |
| scope | string | not null | `system` (ours, owner null) / `team` / `personal` / `project`. |
| owner_type | string | nullable | The polymorphic owner class (`Team` / `User` / `Project`); null = system. |
| owner_id | bigint | nullable | The polymorphic owner id; null = system. One slot per `(scope, owner, key)`. |

> The slot is pure identity — all content lives on its versions.

### `playbook_versions`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| playbook_id | bigint | FK → playbooks | The slot this version belongs to. |
| version | integer | not null | The version number within the slot's history. |
| title | string | not null | The version's title (a proposal changes title/summary/mode/body together). |
| body | text | not null | The prompt body. |
| status | string | not null · default `proposed` | `proposed` (awaiting a human approver) / `active` / `archived` (a superseded active) / `rejected`. |
| author_user_id | bigint | FK → users · nullable | Who authored the version. |
| proposed_by_ai | boolean | not null · default false | Whether an MCP (AI) caller authored the proposal. |
| note | text | nullable | The proposal message. |
| work_session_id | bigint | FK → work_sessions · nullable | The session the version was proposed from. |
| summary | string | nullable | One-line summary; named playbooks appear in the main catalog by it. |
| mode | string | not null · default `append` | `append` (add onto the layers above) or `overwrite` (discard them and start from this body). |

> Exactly one version per slot is `active` (the one composition uses); approving a proposed version archives the prior active one.

### `project_secret_requirements`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The project that needs the key. |
| key | string | not null | The env key the project needs (the manifest entry — just the name, not sensitive). |
| description | string | nullable | What the key is for. |
| is_secret | boolean | not null · default true | Whether the value is sensitive. |

### `personal_secrets`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| user_id | bigint | FK → users | The owning user. |
| project_id | bigint | FK → projects · nullable | null = applies to any project; a project-scoped row overrides the global one there. |
| key | string | not null | The env key this value is for. |
| value | text | not null · **encrypted at rest** | The user's value; delivered only via the out-of-MCP secrets endpoint, never through an MCP tool. |

### `project_tools`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The owning project. |
| kind | string | not null | `program` (install/verify) or `command` (a reusable script written into the workspace `bin/`). |
| name | string | not null | The tool / command name. |
| description | string | nullable | What it is / does. |
| check | text | nullable | For a program: the presence-detection command. |
| run | text | not null | For a program: the install command; for a command: the script body. |
| last_status | string | nullable | Agent-reported: `ok` / `missing` / `error` / `unknown`. |
| last_checked_at | timestamp | nullable | When the agent last verified the tool. |

> Approver-managed (it runs shell on the agent's machine); fetched via the out-of-MCP tools manifest.

### `github_connections`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| user_id | bigint | FK → users | The owning user; a user may link several. |
| label | string | not null | "work" / "personal" — a human label for the account. |
| github_login | string | nullable | The resolved GitHub account login. |
| token | text | not null · **encrypted** | The GitHub token, stored encrypted. |

### `repositories`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| github_connection_id | bigint | FK → github_connections | The connection the repo is read through. |
| full_name | string | not null | "owner/name". |
| default_branch | string | nullable | The repo's default branch. |

### `project_repository`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| project_id | bigint | FK → projects | The project. Pivot keeps the singular name. |
| repository_id | bigint | FK → repositories | A repository in the project's stack. Unique `(project_id, repository_id)`; both sides cascade-delete. |

### `task_comments`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| task_id | bigint | FK → tasks | The task being commented on. |
| user_id | bigint | FK → users · nullable | Set for human comments; null for an agent's comment. |
| author | string | not null | Display label (the human's name, or an agent id) so an agent's note renders sensibly. |
| body | text | not null | The comment body — the async thread between a human and an agent. |

### `task_dependencies`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| task_id | bigint | FK → tasks | The dependent task. Unique `(task_id, depends_on_task_id)`. |
| depends_on_task_id | bigint | FK → tasks | The blocker. Drives the Gantt's dependency lines and a "blocked" indicator. |

### `task_events`

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Primary key. |
| task_id | bigint | FK → tasks | The task this activity belongs to. |
| type | string | not null | `status_changed` / `claimed` / `released` / `review_created` / `commented` / … |
| actor | string | nullable | Who did it — a human name or an agent id. |
| description | text | nullable | A human-readable line for the timeline. |

> Append-only; rendered as a timeline on the task detail page.

## Diagram

Every migrated app table in full (fields + types). Types are the migration types
(`jsonb` = JSON cast to array). Laravel scaffolding tables other than `users`
are omitted. Three things are **deliberately** left out of the boxes and ignored
by the schema-match test: **timestamps** (`created_at` / `updated_at`, on every
table); **indexes / FK indexes**; and **`unsigned`** integer qualifiers (this
app runs on Postgres, which has no unsigned int type).

```mermaid
erDiagram
    USER ||--o{ PROJECT : owns
    USER ||--o{ TEAM : "owns (nullable)"
    TEAM ||--o{ PROJECT : "owns (nullable)"
    USER }o--o{ TEAM : "team_user (member)"
    USER }o--o{ PROJECT : "project_user (member)"
    PROJECT ||--o{ TASK : has
    PROJECT ||--o{ WORK_SESSION : "work log"
    PROJECT ||--o{ REVIEW : has
    REVIEW ||--o{ REVIEW_SECTION : "walkthrough steps"
    REVIEW ||--o{ REVIEW_FILE : "changed files (GitHub)"
    REVIEW_FILE }o--o{ REVIEW_SECTION : "review_file_section (covered by)"
    REVIEW }o--o{ TASK : "review_task (covers)"
    USER ||--o{ REVIEW : "assigned (nullable)"
    PLAYBOOK ||--o{ PLAYBOOK_VERSION : "versioned by"
    PROJECT ||--o{ PROJECT_SECRET_REQUIREMENT : "needs (manifest)"
    USER ||--o{ PERSONAL_SECRET : "owns values"
    PROJECT ||--o{ PERSONAL_SECRET : "scoped to (nullable)"
    PROJECT ||--o{ PROJECT_TOOL : "provides"
    USER ||--o{ PLAYBOOK : "owns personal-scope (polymorphic)"
    TEAM ||--o{ PLAYBOOK : "owns team-scope (polymorphic)"
    PROJECT ||--o{ PLAYBOOK : "owns project-scope (polymorphic)"
    USER ||--o{ PLAYBOOK_VERSION : "authored (nullable)"
    USER ||--o{ PERSONAL_ACCESS_TOKEN : authenticates
    USER ||--o{ GITHUB_CONNECTION : connects
    GITHUB_CONNECTION ||--o{ REPOSITORY : reads
    PROJECT }o--o{ REPOSITORY : "project_repository (stack)"
    REPOSITORY ||--o{ REVIEW : "compared in"
    REVIEW_SECTION ||--o{ REVIEW_FINDING : raises
    TASK ||--o{ WORK_SESSION : "logged on (nullable)"
    TASK ||--o{ TASK_COMMENT : has
    TASK ||--o{ TASK_EVENT : "activity log"
    TASK }o--o{ TASK : "task_dependencies (blocked by)"
    USER ||--o{ TASK_COMMENT : "authored (nullable)"

    PROJECT {
        bigint id PK
        bigint user_id FK
        bigint team_id FK "nullable, null = personal"
        string name
        string slug "unique per user"
        text description "nullable"
        text primary_goal "nullable"
    }

    TEAM {
        bigint id PK
        string name
        bigint owner_user_id FK
        boolean allow_personal_instructions
    }

    TEAM_USER {
        bigint id PK
        bigint team_id FK
        bigint user_id FK
        string role "owner|member"
        boolean can_approve_prompts
    }

    PROJECT_USER {
        bigint id PK
        bigint project_id FK
        bigint user_id FK
        boolean can_approve_prompts
    }

    TASK {
        bigint id PK
        bigint project_id FK
        string title
        string category "nullable, grouping prefix"
        string priority "low|normal|high|urgent"
        date start_date "nullable, Gantt start"
        date due_date "nullable, deadline"
        string branch "nullable, the dev branch"
        text body "nullable, markdown detail"
        text body_summary "nullable, TL;DR of body; required when body is set"
        text plan "nullable, planning artifact (markdown)"
        text plan_summary "nullable, TL;DR of plan; required when plan is set"
        text rework_notes "nullable, what a review sent back"
        string status "one of 13 lifecycle states"
        timestamp status_changed_at "nullable, entered-current-status time"
        string claimed_by "nullable, agent holding a *-ing card"
        timestamp claimed_at "nullable, when it was claimed"
        integer position "order within status"
    }

    WORK_SESSION {
        bigint id PK
        bigint project_id FK
        bigint task_id FK "nullable, the task it logs"
        string title
        string slug
        text body "nullable, markdown detail"
        text body_summary "nullable, TL;DR of body; required when body is set"
        date occurred_on "nullable, when the work happened"
    }

    REVIEW {
        bigint id PK
        bigint project_id FK
        string title
        bigint repository_id FK "nullable, the compared repo"
        string base_ref "nullable, e.g. main"
        string base_sha "nullable, resolved commit of base_ref"
        string head_ref "nullable, e.g. feat/x"
        string head_sha "nullable, resolved commit of head_ref"
        string status "draft|in_review|done"
        string outcome "nullable, approved|changes_requested"
        timestamp concluded_at "nullable, when the human applied the outcome (responded time)"
        bigint assigned_to_user_id FK "nullable, current holder"
        text intro "nullable, preamble"
    }

    REVIEW_FILE {
        bigint id PK
        bigint review_id FK
        string path
        string status "added|modified|removed|renamed"
        string old_path "nullable, for renames"
        integer position "GitHub ordering"
        text patch "nullable, unified diff; null for binary/oversized"
        integer additions "lines added"
        integer deletions "lines removed"
    }

    REVIEW_FILE_SECTION {
        bigint id PK
        bigint review_file_id FK
        bigint review_section_id FK
    }

    REVIEW_SECTION {
        bigint id PK
        bigint review_id FK
        integer position "order in the walkthrough"
        string title
        string mode "skip|behavioural|direct|direct_doc|mirror_guard"
        text context "nullable, rebuilds reviewer knowledge"
        string link "nullable, what to open"
        jsonb checks "nullable, [what to confirm]"
        string status "open|signed_off"
        string decision "nullable, approved|changes_requested"
        text note "nullable, human comment / change request"
    }

    REVIEW_TASK {
        bigint id PK
        bigint review_id FK
        bigint task_id FK
    }

    USER {
        bigint id PK
        string name
        string email "unique"
        timestamp email_verified_at "nullable"
        string password
        string remember_token "nullable"
    }

    PLAYBOOK {
        bigint id PK
        string scope "system|team|personal|project"
        string owner_type "nullable, Team|User|Project; null = system"
        bigint owner_id "nullable, the owner; null = system"
        string key "phase (main|plan|develop|ai_review|merge) or a named key"
        string title
    }

    PLAYBOOK_VERSION {
        bigint id PK
        bigint playbook_id FK
        integer version
        string title
        string summary "nullable, one-line; named playbooks appear in the main catalog"
        string mode "append|overwrite (how this version composes)"
        text body "the prompt"
        string status "proposed|active|archived|rejected"
        bigint author_user_id FK "nullable, who authored it"
        bigint work_session_id FK "nullable, the session it was proposed from"
        boolean proposed_by_ai "an MCP (AI) authored the proposal"
        text note "nullable, proposal note"
    }

    PROJECT_SECRET_REQUIREMENT {
        bigint id PK
        bigint project_id FK
        string key "the env key the project needs"
        string description "nullable"
        boolean is_secret "is the value sensitive"
    }

    PERSONAL_SECRET {
        bigint id PK
        bigint user_id FK
        bigint project_id FK "nullable, null = any project"
        text value "the value, encrypted at rest"
        string key "the env key this value is for"
    }

    PROJECT_TOOL {
        bigint id PK
        bigint project_id FK
        string kind "program|command"
        string name
        string description "nullable"
        text check "nullable, program presence check"
        text run "install command, or the command script body"
        string last_status "nullable, ok|missing|error|unknown (agent-reported)"
        timestamp last_checked_at "nullable, when the agent last verified it"
    }

    PERSONAL_ACCESS_TOKEN {
        bigint id PK
        string tokenable_type
        bigint tokenable_id
        text name "machine/agent label"
        string token "unique, hashed"
        text abilities "nullable"
        timestamp last_used_at "nullable"
        timestamp expires_at "nullable"
    }

    GITHUB_CONNECTION {
        bigint id PK
        bigint user_id FK
        string label "work|personal"
        string github_login "nullable, resolved account"
        text token "encrypted"
    }

    REPOSITORY {
        bigint id PK
        bigint github_connection_id FK
        string full_name "owner/name"
        string default_branch "nullable"
    }

    PROJECT_REPOSITORY {
        bigint id PK
        bigint project_id FK
        bigint repository_id FK
    }

    REVIEW_FINDING {
        bigint id PK
        bigint review_section_id FK
        string title
        text detail "nullable, scenario + impact"
        string severity "info|minor|major|critical"
        string status "open|must_fix|approved|dismissed"
        integer position
    }

    TASK_COMMENT {
        bigint id PK
        bigint task_id FK
        bigint user_id FK "nullable, human author"
        string author "display label"
        text body
    }

    TASK_DEPENDENCY {
        bigint id PK
        bigint task_id FK "the dependent"
        bigint depends_on_task_id FK "the blocker"
    }

    TASK_EVENT {
        bigint id PK
        bigint task_id FK
        string type
        string actor "nullable"
        text description "nullable"
    }
```
