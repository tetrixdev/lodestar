# Class-family docs — the doctrine

Some structures in a codebase aren't single classes — they're **families**: an
abstract base plus its children, all honouring one contract (the MCP tools), or a
set of seeded records that only make sense together (the system playbooks). Prose
scattered through `ARCHITECTURE.md` can't keep a family legible, and a reader
can't tell at a glance whether every member still fits the contract.

So we apply the **same discipline we already use for the schema** — `DATA-MODEL.md`
mirrors the tables and `SchemaMirrorTest` fails the build on drift — to class
families:

> For each **abstract base / shared family**, keep **one doc** that states the
> family's **contract** and lists **every member**, and back it with a **drift
> guard** (a test) that fails when a member is added without documenting it.

The family docs live here, in `docs/classes/`. Today:

- [`TOOLS.md`](TOOLS.md) — the `LodestarTool` family (the MCP tool surface), guarded by `ToolsDocMirrorTest`.
- [`PLAYBOOKS.md`](PLAYBOOKS.md) — the system playbooks + composition, guarded by `PlaybooksDocMirrorTest`.

`DATA-MODEL.md` stays where it is — it is the **model family's** contract doc, just
named for history.

## The pattern taxonomy

These are the shapes to reach for — and the vocabulary the family docs use. The
goal is **cohesion over class-sprawl**: one well-named thing that owns a
responsibility, not a scatter of narrow classes that each do a sliver of it.

| shape | what it is | when to use it |
|-------|-----------|----------------|
| **model** | owns a table; thin — logic lives as small helpers + model events | persistence + the rules that travel with a row |
| **service** | one cohesive class that owns a use-case or a boundary, and **hides its internals** (private methods, not new top-level classes) | a chat flow, a third-party integration, a multi-step operation |
| **action** | a single invokable operation | one discrete "do this" with no sibling operations |
| **abstract-family** | an abstract base + children sharing a contract | a set of interchangeable things (tools, agents) |
| **repository** | a query/persistence seam over one or more models | **only** when logic spans multiple models **and** is used from multiple places — otherwise keep it in the one controller/service |

**The anti-pattern to avoid:** splitting one responsibility into many narrow
top-level classes (`AiMessageParser`, `UserMessageParser`, `DiceRollParser`…)
when it should be **one** `ChatService` that owns the flow and keeps those steps
as private methods or internal helpers. If a helper is only ever used by one
service, it belongs *inside* that service.

## Writing a family doc

1. State the **contract** — what every member must provide (the abstract methods /
   shared responsibilities).
2. List **every member**, with the contract fields filled in (a table is ideal).
3. Add a **drift-guard test** that discovers the real members (from the
   filesystem / registration / seeder) and asserts each appears in the doc —
   skipping loudly if `docs/` isn't mounted, exactly like `SchemaMirrorTest`.

When a project lacks these docs, the `docs-template` playbook carries the
skeleton + rules.
