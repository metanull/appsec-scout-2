# Repository Mapping

A **Repository Mapping** associates a `SoftwareSystem`, `SecurityContainer`, or
`SoftwareAsset` with a code repository — a `RepositoryProvider` (Azure Repos or
GitHub), a repository name, a default branch, and an optional monorepo
`path_prefix`. It lets AppSec Scout build **code links** (repository, source
file, commit) and jump from a finding to its source.

## Two ways a finding resolves to code

AppSec Scout resolves the repository behind a finding through
`RepositoryCodeIdentityResolver`, which produces a `RepositoryCodeIdentity`
(provider + browse URL + default branch + optional prefix) from one of two
sources, in this order:

1. **An operator `RepositoryMapping` override**, if one exists on the container
   or its system.
2. **The container's own native identity** — its `url`, `source.provider`, and
   `code.default_branch` metadata.

`RepositoryCodeUrlGenerator` formats the URLs from that identity, so the alert
and Local Finding "Links & References" sections and the "Code location"
readiness indicator all work off whichever source is available. A
`RepositoryMapping` is an optional override, not a prerequisite.

## When a mapping is required

A mapping is the only way to resolve code when a finding has **no code identity
of its own**:

- **App-based sources (ASoC DAST, Detectify)** scan running applications and
  domains, not repositories. Their container has no repository `url` and no
  `source.provider`, so a `RepositoryMapping` is what connects the finding to
  the code where the fix lives.
- **Non-standard layouts**, as an override: a monorepo sub-path (`path_prefix`),
  code hosted on a different provider than the source, or a manual URL when the
  source exposes none.

## When a mapping is not used

**Azure DevOps Advanced Security is code-native.** A synced or imported AzDO
repository records its own browse URL, provider, and default branch on the
`SecurityContainer`, and the link machinery reads that identity directly.
`AzDoProjectLinker` backfills a mapping only for a repository container that
lacks a native identity; a container that carries its own identity gets none,
because its identity is authoritative.

## What it does not drive

- **Readiness.** The context-quality indicator is **"Code location"**: it is
  green when a finding can be linked to code — through a native identity *or* a
  mapping. An AzDO alert is ready from its container identity; an
  ASoC/Detectify finding shows "Code location missing" until an operator maps
  it.
- **Code search.** `triage:codesearch` resolves its scope from `project:` /
  `repo:` strings and the Azure DevOps Repos credential; it does not read
  `RepositoryMapping`.

## Summary

| Source | Container has native code identity? | Mapping needed for code links? |
|--------|:-----------------------------------:|:------------------------------:|
| Azure DevOps Advanced Security | Yes | No — identity is authoritative |
| ASoC (DAST) / Detectify | No (runs on live apps) | Yes — the mapping is the code bridge |
| Any source, non-standard layout | Partial | Optional override (monorepo prefix, different provider, manual URL) |
