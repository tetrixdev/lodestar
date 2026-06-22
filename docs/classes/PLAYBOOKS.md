# The system playbooks + composition

Playbooks are the prompts that drive agents. The **system** layer ships seeded
from code (`database/seeders/SystemPlaybookSeeder.php`); higher scopes (team /
project / personal) layer on top. This doc is the contract + the roster of system
playbooks and how they compose; `PlaybooksDocMirrorTest` fails if a seeded system
playbook isn't documented here.

> A `Playbook` is a **slot** at one scope + key; its text lives in versioned
> `PlaybookVersion` rows, one of them `active`. `Playbook::compose()` /
> `resolveNamed()` (`app/Models/Playbook.php`) turn those slots into the prompt an
> agent actually runs.

## How composition works

**Scopes, in composition order:** `system → team → project → personal`. Personal
is last so a person always has the final say — unless the project's team sets
`allow_personal_instructions = false`, which drops the personal layer.

**Two kinds of key:**

- **Phase keys** — `main`, `plan`, `develop`, `ai_review`, `merge` — **compose**:
  every scope's layer for that key is concatenated in order. A layer's `mode` is
  `append` (default) or `overwrite` (discards everything accumulated above it).
- **Named keys** — e.g. `work`, `docs-template`, `laravel` — **resolve to the
  most-specific scope only** (personal → project → team → system); no
  composition. The bootstrap `main` playbook advertises the on-demand named ones.

**Stack packs (framework steering).** A project carries a `stack` field
(`projects.stack`). When `stack = 'laravel'`, composition appends the system
**`laravel`** layer to the `plan` / `develop` / `ai_review` phases — so
Laravel-specific structure guidance reaches those phases automatically, without
polluting non-Laravel projects. This is the per-project attachment seam in
`compose()`.

## The system playbooks

| Key | Title | Kind | When it loads / what it's for |
|-----|-------|------|-------------------------------|
| `main` | Lodestar agent — start here | phase (bootstrap) | Loaded first by every agent (`get_playbook(phase:'main')`): how Lodestar works, interactive-vs-worker mode, workspace setup, routing, the generic structure doctrine. |
| `plan` | Plan a task | phase | A claimed `planning` task — turn it into a reviewable structure map (no code). |
| `develop` | Develop a task | phase | A claimed `developing` task — build on the branch with tests + doc updates. |
| `ai_review` | AI-review a task | phase | A claimed `ai_review` task — make the change reviewable (sections, modes, findings). |
| `merge` | Merge & deploy a task | phase | A claimed `merging` task — ship it (merge, test, deploy, mark done). |
| `work` | Work the backlog (the loop) | named | The autonomous loop: `claim_work`, spawn a worker per unit (tasks parallel in worktrees, deliverables sequential). |
| `docs-template` | Project docs skeleton | named | The skeleton + rules for a project's `DATA-MODEL.md`, `ARCHITECTURE.md`, and `docs/classes/` family docs — loaded when a project lacks them. |
| `laravel` | Laravel structure pack | named (stack pack) | Appended to `plan`/`develop`/`ai_review` when `project.stack = 'laravel'`: the service/action/repository conventions + cohesion-over-sprawl rules from `docs/classes/README.md`. |

## Phase → playbook, around the lifecycle

A claimed task's working state selects its phase playbook (`Task::phaseFor()`):

- `planning` → **plan** · `developing` → **develop** · `ai_review` → **ai_review** · `merging` → **merge**

The same holds for deliverables (`Deliverable::phaseFor()`). The human gates
(`plan_review`, `human_review`, the deliverable architecture/functional reviews)
load no playbook — a person moves them.

_Source of truth: `SystemPlaybookSeeder`. The guard discovers seeded system keys
and asserts each appears above — seed a playbook, add a row._
