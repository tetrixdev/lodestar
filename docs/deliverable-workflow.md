# Deliverable workflow — design & build log

> Status: **in progress.** This document is the spec + assumptions log for the
> deliverable-centric workflow. It was written during an autonomous build session
> (Jasper away, "build now, make assumptions, I'll check in"). Anything marked
> **ASSUMPTION** is a decision I made to keep moving — flag any you disagree with.

## 1. Why

Today Lodestar is flat: `Project → Task`, one task = one branch = one full
plan→dev→ai_review→human_review→merge cycle = one human review. When you're
prepping a coherent increment (e.g. 0.1 → 1.0) that is really *many* tasks toward
one goal, and the work loop runs sub-agents in parallel, branch-per-task either
serialises everything or makes the human review N disjoint diffs that only make
sense in aggregate.

The fix is a **deliverable**: an optional layer between project and task that owns
a goal, a branch, and a review funnel. Many cheap per-task functional gates →
one batched code/architecture review → one final functional sanity check.

## 2. The shape (funnel)

```
Project
 └─ Deliverable  (raw concept → rewritten spec → plan → decomposed tasks)
     ├─ Task 1 ─┐
     ├─ Task 2 ─┤  built in parallel, each in its own worktree + branch,
     ├─ Task 3 ─┘  each merged into the deliverable branch when approved
     └─ … 
```

**Deliverable lifecycle** (mirrors the task state machine so the board & controls
are familiar):

| status | actor | meaning |
|--------|-------|---------|
| `new` | human | raw concept as the user wrote it |
| `ready_for_planning` | queued | queued for the planning agent |
| `planning` | ai | agent rewrites the concept into our spec format, writes the plan, **decomposes into task rows**, and raises **open questions** |
| `plan_review` | human | human reviews description + plan + task breakdown + questions. **If any question is unanswered → the only move is back to planning. If none → may approve.** |
| `building` | (special) | approved. Deliverable branch created. Child tasks become claimable and run in parallel. |
| `ready_for_ai_review` | queued | all child tasks merged → queue the deliverable-level AI review |
| `ai_review` | ai | AI reviews the **whole** deliverable diff (`base_branch...deliverable_branch`) |
| `human_architecture_review` | human | technical review (≈ today's code review). Skippable later via settings; **always on for now.** |
| `human_functional_review` | human | final thin functional sanity check — did any review-driven change break behaviour? |
| `approved` | queued | ready to merge the deliverable branch into its base |
| `merge_deploy` | ai | merge `deliverable_branch → base_branch` |
| `done` | done | shipped |
| `cancelled` | archived | archive |

**Feedback loops** (see §4, open-Q1): AI review or architecture review that finds
issues **spawns corrective task(s)** and sends the deliverable back to `building`.
There is **no separate deliverable-level dev cycle** — corrective work flows
through the normal task loop and re-merges. When no open child tasks remain, the
deliverable auto-returns to `ready_for_ai_review`. Re-reviews reuse sections via
the dirty-watermark (§5).

## 3. Branch & id conventions  (resolves your sub-id question)

- **Global `id`** (DB autoincrement) stays the canonical unique key for every
  entity. Display is zero-padded to 6 (`000077`) but the *number* can grow past
  6 digits without anything breaking — branches use the real number, padding is
  cosmetic. So "running out of 6 digits" is a non-issue.
- **Deliverable branch:** `D{id:06d}-{slug}` — e.g. `D000077-deliverable-workflow`.
- **Task branch under a deliverable:** `D{deliverable_id:06d}/T{sub_id:02d}-{slug}`
  — e.g. `D000077/T03-merge-conflict-resolver`. **`sub_id` is per-deliverable**
  (1,2,3…), 2-digit display, rolls to 3 digits naturally past 99. Nested path
  makes the whole deliverable greppable/groupable and easy to clean up.
- **Standalone task (no deliverable):** flat `T{id:06d}-{slug}` — today's behaviour.

**ASSUMPTION:** deliverables are **optional**. A task can be born standalone
(branch-per-task → base) for hotfixes, and be pulled into a deliverable later.
Not everything is forced under a deliverable.

**ASSUMPTION:** task branch is created lazily at the start of the dev cycle: the
develop phase checks for `D…/T…` and, if absent, cuts it from the **current tip of
the deliverable branch**. Each agent then works in its own `git worktree`.

**`base_branch`** is stored on the deliverable (asked of the user at creation;
default = the repo default branch). The deliverable diff is
`base_branch...deliverable_branch`. Both branch names are shown on the deliverable.

## 4. Open-Q1 decision — corrective tasks, no deliverable dev cycle

**Decision: deliverable-level review feedback creates corrective task(s); there is
no separate deliverable-level development cycle.**

- AI review / architecture review finds issues → create corrective task(s) linked
  to the deliverable (flagged `is_corrective`) → deliverable → `building` →
  corrective tasks run the normal task loop → merge into deliverable branch → when
  no open child tasks remain, deliverable auto-advances to `ready_for_ai_review`.
- **Functional review on a corrective task is conditional** on a per-task
  `needs_functional_review` flag (planner/reviewer sets it). Behaviour-changing
  fix → functional review; pure refactor/doc fix → skip. Same flag powers
  "trivial tasks auto-pass".

**Pros:** reuses *all* task machinery (dev/worktree/merge/review); everything stays
tracked, tested, reviewable; identical merge-conflict logic; preserves worktree
isolation (no agent writes directly to the deliverable branch).
**Cons:** a cross-cutting architectural fix maps a little awkwardly onto "a task"
(mitigation: a corrective task may be deliberately broad; the planner scopes it);
mild ceremony for tiny fixes (mitigation: the functional flag + auto-pass).

The alternative — a dedicated deliverable-level dev state where an agent edits the
deliverable branch directly — was rejected: it duplicates the dev machine and
reintroduces the parallel-write hazard the worktree model removes.

## 5. Reviews — polymorphic, with a dirty-section watermark

- **One `Review` model, polymorphic over a target** (`task` | `deliverable`).
  Same sections / findings / coverage machinery. Only two things vary by scope:
  the **diff source** (task: `deliverable_branch...task_branch`; deliverable:
  `base_branch...deliverable_branch`) and the **approve/reject transition**.
  The MCP review tools branch on `scope`, they are not forked.
- **Review type** (`review_type`): `functional` (per task; behaviour/UX/UI/
  permissions; a non-technical involved person can do it) vs `code` /
  `architecture` (technical). The human-facing functional review is fully
  business-logic/UX/UI focused — permission questions belong here too.
- **Dirty-section watermark** (replaces "diff-only vs reuse-all"): sections
  persist across rounds. When new commits land (a task merging into the
  deliverable, or a re-review after fixes), compute which files they touch →
  **reset only those sections to undecided**, stamp `change_note` ("what changed /
  when"), and flag `stale = true`. Untouched sections keep their prior `approved`.
  **New files** must be allocated to a section (existing coverage guard), and the
  sections they touch are (re)worked. The human only re-walks flagged sections.
  This single mechanism implements both "flag affected sections" and incremental
  re-review.
- **Functional sections carry `manual_steps`** — a *short but complete*
  step-by-step the human follows: exactly what to run and where to look, including
  for invisible outputs. Goal: the human never has to figure out *how* to test,
  only *whether* what they see is right. Keep it concise — cover everything, pad
  nothing.

### Input → Output decomposition (planning rule + section kinds)

Every change is framed as **input → output**, and this must be present in the
plan *and* the review:
- `input_screen` + outcome, `command` (artisan/CLI) + outcome,
- **invisible outputs** — automated mail, 3rd-party sync, webhooks — must ship
  with a **dry-run / preview** so the human sees *what would be sent* without
  firing it (Laravel `Mail::fake` / log driver; a preview endpoint; etc.).
- **Planning rule:** every outbound side-effect must be built previewable, so it
  is reviewable. The functional section's `manual_steps` then say e.g. "run
  `php artisan x:y --pretend`; the rendered mail is written to `storage/logs/…`;
  confirm subject = …".

**ASSUMPTION:** implement section kinds as a new `kind` column on `review_sections`
(`input_screen|command|outbound_effect|other`), orthogonal to the existing
protocol `mode`. (Not built yet — see roadmap.)

## 6. Security / CVE  (your call: deterministic stays out for now)

- **Judgment-based security review = a lens/section inside the AI review** — and
  seeded into the planning playbook so it's built right (authz, injection,
  input-validation, an OWASP-ish checklist from authoritative sources, "no
  credentials in code"). **In scope now.**
- **Deterministic scans** (`composer audit`, `npm audit`, secret scanning) —
  **kept OUT of Lodestar for now** to avoid bloating its responsibilities. If a
  leaked credential is ever detected (by whatever external means), human review
  carries a "rotate/recycle this credential" item. Revisit later as an optional
  integration, not a core feature.

## 7. Parallel worktrees & the test environment  (your Docker concern)

This must live in the **playbook**, not be hard-coded to one project — Lodestar is
generic; we're just Laravel-first initially. Draft instruction (for the
`develop` / `main` playbook) is in §9. Key rules:

- Agents work in **isolated git worktrees** and must **not** bring up the project's
  Compose stack (fixed `container_name`s collide; this is the only real conflict
  class — ports are already dropped in dev via `ports: !override []`).
- Run composer / artisan / tests in an **ephemeral, version-pinned** runtime
  (e.g. PHP `8.3` CLI container matching production), never host tooling — this is
  what keeps "host PHP differs from prod" from biting.
- This project's tests already run on `sqlite :memory:` (`phpunit.xml`:
  `DB_CONNECTION=sqlite`, `MAIL=array`, `QUEUE=sync`, `CACHE=array`) → **zero
  shared state, trivially parallel** across worktrees, no DB container needed.
- **`php artisan test --parallel`** (Laravel/paratest): spins up N PHP processes
  and, for DB-backed tests, **clones a separate test database per process token**
  (`…_test_1`, `…_test_2`), migrates each, tears down — purpose-built DB isolation.
  Within a worktree it parallelises across cores; across worktrees the sqlite
  `:memory:` per-process isolation already does the job.
- **Stacks that need a real DB** (e.g. **pgvector** once embeddings land — #71;
  Jasper also uses **Qdrant** elsewhere, a separate vector stack we must support):
  fall back to a **single shared Postgres with a unique database per task**, or
  lean on `--parallel`'s per-token DBs. The playbook must state: if the project's
  test environment can't be satisfied this way, **stop and suggest creating a
  separate task to fix the test environment first**, before any feature work.
- General catch-all in the playbook: *if your situation is outside these cases,
  keep the principles in mind — isolate state, pin the runtime, never collide on
  fixed container names/ports, and fix the environment before building on it.*

## 8. UX surface (not built yet — roadmap)

- **Deliverable kanban card** in a special Dev/Review column shows a one-liner per
  child task, each task's actions reachable **without** opening the deliverable
  show page.
- **Deliverable show page** with task actions easily reachable.
- **Plan-review screen** renders description + plan + the decomposed tasks (like
  review sections) + open questions (answer inline; gate on all-answered).
- **Human review screens** get a **"copy test instructions"** button: a short
  snippet telling the reviewer to pull the right branch (task or deliverable) and
  bring the app up, so they can start testing. Keep it short.

## 9. Draft playbook instruction — test environment (for human approval)

> Proposed addition to the `develop` (and a condensed note in `main`) playbook.
> Playbooks live server-side and changes go through `propose_playbook_change`
> (always recorded as *proposed*, awaiting a human approver) — this is the text to
> propose, not yet applied.

```
## Test environment & isolation (parallel work)

You work in an isolated git worktree alongside other agents. Before relying on
the project's test/run environment:

- Do NOT bring up the project's full Compose stack — sibling agents will collide
  on fixed container names. Run composer/build/tests in an ephemeral, version-
  pinned runtime that matches production (don't trust host tooling).
- Prefer a test setup with no shared state (e.g. in-memory SQLite, array mail/
  cache, sync queue) so parallel runs can't corrupt each other. If the suite
  supports it, `php artisan test --parallel` isolates DB-backed tests per worker.
- If the project needs a real datastore for tests (e.g. pgvector / Qdrant / a
  Postgres-only feature), use an isolated database per task (unique name) or the
  parallel runner's per-token databases — never a shared mutable dev database.

If the test environment is not in a state where you can run tests in isolation,
STOP: do not hack around it. Create a separate task to fix the test environment
first, and surface that to the user before continuing other work.
```

## 10. Build roadmap & status

- [x] Design + assumptions (this doc)
- [ ] **Foundation (this session):** `deliverables`, `deliverable_questions`
      tables; task fields (`deliverable_id`, `sub_id`, `is_corrective`,
      `needs_functional_review`); review polymorphism (`scope`, `deliverable_id`,
      `review_type`, `base_branch`); section dirty-watermark + `manual_steps`;
      models + lifecycle + tests.
- [ ] MCP tools: `get_task` (#75, read tasks); `upsert_deliverable`;
      deliverable-aware `claim_task` (skip blocked deps; only claim child tasks
      whose deps are done+approved); polymorphic review tools.
- [ ] Agent work loop / worktree runner: parallel sub-agents in worktrees; lazy
      task-branch creation off the deliverable branch; merge-back + conflict
      resolution (merge deliverable→task, resolve, re-test, then task→deliverable;
      flag affected sections).
- [ ] Playbook rewrites (intake/description-format → playbook; test-env §9;
      security lens; "minimise human work / unit-test everything testable";
      input→output + dry-run rule).
- [ ] UI: deliverable column + card one-liners; deliverable show page; plan-review
      screen; functional/architecture review screens; "copy test instructions".
- [ ] Fold board redesign (#69/#70) in; regroup subsumed tasks
      (#30 revived, #63/#65/#73/#74/#75) under this deliverable.

## 11. Tasks subsumed / related (from the current board)

- Building blocks → fold into this deliverable: **#63** (manual-test checklist =
  functional sections), **#74** (structured plan-review = plan-review screen),
  **#73** (linked reviews ordering/dedup), **#65** (multi-repo reviews), **#75**
  (`get_task`), **revive #30** (technical-stakeholder skip).
- Sequence *with* this: **#69** (unified board), **#70** (delete old dashboard).
- Parallel/independent: #71 (pgvector), #59, #72, #76, #61, #58, #66.
- Out of scope: #32 (SaaS/pricing).
```
