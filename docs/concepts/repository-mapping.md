# Repository Mapping

A **Repository Mapping** associates a `SoftwareSystem`, `SecurityContainer`, or
`SoftwareAsset` with a code repository — a `RepositoryProvider` (Azure Repos or
GitHub), a repository name, a default branch, and an optional monorepo
`path_prefix`. Its job is to let AppSec Scout build **code links** (repository,
source file, commit) and jump from a finding to its source.

## Two ways a finding resolves to code

AppSec Scout resolves the repository behind a finding through
`RepositoryCodeIdentityResolver`, which produces a `RepositoryCodeIdentity`
(provider + browse URL + default branch + optional prefix) from one of two
sources, in this order:

1. **An operator `RepositoryMapping` override**, if one exists on the container
   or its system.
2. **The container's own native identity** — its `url`, `source.provider`, and
   `code.default_branch` metadata.

`RepositoryCodeUrlGenerator` formats the actual URLs from that identity, so the
alert and Local Finding "Links & References" sections, and the "Code location"
readiness indicator, all work off whichever source is available. **A
`RepositoryMapping` is therefore an optional override, never a prerequisite.**

## When a mapping is the *only* way (its real value)

The mapping matters most where a finding has **no code identity of its own**:

- **App-based sources (ASoC DAST, Detectify).** These scan running
  applications and domains, not repositories. Their container has no repository
  `url` and no `source.provider` — so a `RepositoryMapping` is the only way to
  connect the finding to the code where the fix lives.
- **Overrides even for code sources:** a monorepo sub-path (`path_prefix`), code
  hosted on a different provider than the source, or a manual URL when the
  source exposes none.

## When a mapping is redundant (and why we stopped auto-creating it)

**Azure DevOps Advanced Security is code-native.** A synced (or SBOM/static
-analysis imported) AzDO repository already records its own browse URL,
provider, and default branch on the `SecurityContainer`. A mapping for such a
container would only restate what the row already knows, while adding:

- an editable duplicate row in the RepositoryMappings manager that can silently
  diverge from the source of truth,
- a redundant readiness row, and
- an audit entry per machine-generated row.

So `AzDoProjectLinker` **only backfills a mapping when the container lacks a
native identity**; for a normal AzDO repository it does nothing, and the link
machinery uses the container's identity directly. A one-off migration
(`prune_redundant_azdo_repository_mappings`) removes the machine-generated AzDO
mappings created before this rule; operator-authored mappings
(`created_by_user_id` set) and mappings for identity-less containers are kept.

## What it does *not* drive

- **Readiness.** The context-quality indicator is **"Code location"**, not
  "Repository mapping": it is green when a finding can be linked to code —
  through a native identity *or* a mapping. An AzDO alert is ready without any
  mapping; an ASoC/Detectify finding correctly shows "Code location missing"
  until an operator maps it, which is an honest, actionable prompt.
- **Code search.** `triage:codesearch` resolves its scope from `project:` /
  `repo:` strings and the Azure DevOps Repos credential — it does not read
  `RepositoryMapping` at all.

## Summary

| Source | Container has native code identity? | Mapping needed for code links? |
|--------|:-----------------------------------:|:------------------------------:|
| Azure DevOps Advanced Security | Yes | No — identity is authoritative |
| ASoC (DAST) / Detectify | No (runs on live apps) | Yes — the mapping is the code bridge |
| Any source, non-standard layout | Partial | Optional override (monorepo prefix, different provider, manual URL) |
