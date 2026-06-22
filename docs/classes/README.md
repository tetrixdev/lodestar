# Class-family docs

This directory holds **family docs**: one markdown file per family of related
classes (e.g. every MCP tool, every Eloquent model). Each family doc is a single
source-of-truth table that an agent or a human can read to understand *what
exists* in that family without grepping the tree — name, class, role, one-line
purpose.

## Why these exist

A family doc is only useful if it stays honest. So each one is paired with a
**drift guard**: a feature test that parses the doc's table and asserts it
matches the live code BOTH directions — nothing in the code is missing from the
doc, and nothing in the doc is a phantom that no longer exists in the code. This
mirrors the doctrine of `tests/Feature/SchemaMirrorTest.php`, which holds
`docs/DATA-MODEL.md` honest against the live database schema.

The rule: **if you add, rename or remove a class in a documented family, update
its family doc in the same change.** The drift guard will red the suite until you
do — that is the point.

## The families

| Family doc | Covers | Drift guard |
|------------|--------|-------------|
| [`TOOLS.md`](TOOLS.md) | The MCP tools registered on `LodestarServer` | `tests/Feature/Mcp/ToolsDocMirrorTest.php` |

(More families will be added here over time; each gets a row above and its own
drift guard.)
