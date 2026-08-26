#!/usr/bin/env node
/**
 * Enumerate the GitHub repositories owned by the authenticated account, the packages
 * published from each of them, and how much registry storage each package uses.
 *
 * Admin terminal tool — not wired into the web UI. The GitHub PAT is read from the
 * appsec-scout credential vault (`github-repos.token`) via the running `app` container,
 * unless --token or GITHUB_TOKEN is supplied.
 *
 * Usage:
 *   node .\scripts\github-packages.cjs [--repo <name>] [--versions] [--token <pat>] [--json] [--no-sizes]
 *
 * The PAT needs `read:packages` (plus `repo` to see private repositories and packages).
 */

const { execFileSync } = require('child_process')
const { resolve } = require('path')

const API = 'https://api.github.com'
const REGISTRY = 'https://ghcr.io'
const NPM_REGISTRY = 'https://npm.pkg.github.com'
const PACKAGE_TYPES = ['container', 'maven', 'npm', 'nuget', 'rubygems']

const args = process.argv.slice(2)
const flagValue = (name) => (args.indexOf(name) !== -1 ? args[args.indexOf(name) + 1] : null)

const asJson = args.includes('--json')
const withSizes = !args.includes('--no-sizes')
const withVersions = args.includes('--versions')
const repoFilter = flagValue('--repo')
const tokenArg = flagValue('--token')

// --- credentials --------------------------------------------------------------------

function resolveToken() {
  if (tokenArg) return tokenArg.trim()
  if (process.env.GITHUB_TOKEN) return process.env.GITHUB_TOKEN.trim()

  const value = execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'credentials:system:get', 'github-repos.token'],
    { cwd: resolve(__dirname, '..'), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }
  ).trim()

  if (!value) throw new Error('The vault returned an empty value for "github-repos.token".')

  return value
}

// --- GitHub REST --------------------------------------------------------------------

async function api(token, path) {
  const res = await fetch(`${API}${path}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'appsec-scout-github-packages',
    },
  })

  if (!res.ok) {
    const body = (await res.text()).slice(0, 300)
    const error = new Error(`GET ${path} failed: ${res.status} ${res.statusText} — ${body}`)
    error.status = res.status
    throw error
  }

  return res.json()
}

async function apiPaged(token, path) {
  const items = []

  for (let page = 1; ; page++) {
    const separator = path.includes('?') ? '&' : '?'
    const batch = await api(token, `${path}${separator}per_page=100&page=${page}`)
    items.push(...batch)

    if (batch.length < 100) return items
  }
}

// --- GHCR (container) sizes ----------------------------------------------------------

const MANIFEST_TYPES = [
  'application/vnd.oci.image.index.v1+json',
  'application/vnd.docker.distribution.manifest.list.v2+json',
  'application/vnd.oci.image.manifest.v1+json',
  'application/vnd.docker.distribution.manifest.v2+json',
].join(', ')

async function registryToken(pat, repository) {
  const scope = encodeURIComponent(`repository:${repository}:pull`)
  const res = await fetch(`${REGISTRY}/token?service=ghcr.io&scope=${scope}`, {
    headers: { Authorization: `Basic ${Buffer.from(`x:${pat}`).toString('base64')}` },
  })

  if (!res.ok) throw new Error(`ghcr.io token request for ${repository} failed: ${res.status} ${res.statusText}`)

  return (await res.json()).token
}

async function manifest(bearer, repository, reference) {
  const res = await fetch(`${REGISTRY}/v2/${repository}/manifests/${reference}`, {
    headers: { Authorization: `Bearer ${bearer}`, Accept: MANIFEST_TYPES },
  })

  if (!res.ok) throw new Error(`ghcr.io manifest ${repository}@${reference} failed: ${res.status} ${res.statusText}`)

  return res.json()
}

/**
 * Per-version blob totals plus the package total. Blobs (config + layers) are deduplicated
 * by digest: within a version across architectures, and across versions for the package
 * total — which is why the package total is usually far below the sum of its versions.
 */
async function containerSizes(pat, owner, packageName, versions) {
  const repository = `${owner}/${packageName}`.toLowerCase()
  const bearer = await registryToken(pat, repository)
  const packageBlobs = new Map()
  const sized = []

  for (const version of versions) {
    const blobs = new Map()
    const seen = new Set()

    const walk = async (reference) => {
      if (seen.has(reference)) return
      seen.add(reference)

      const document = await manifest(bearer, repository, reference)

      for (const child of document.manifests ?? []) await walk(child.digest)
      for (const blob of [document.config, ...(document.layers ?? [])]) {
        if (blob && blob.digest) blobs.set(blob.digest, blob.size ?? 0)
      }
    }

    await walk(version.name)
    for (const [digest, size] of blobs) packageBlobs.set(digest, size)

    sized.push({
      name: version.name,
      tags: version.metadata?.container?.tags ?? [],
      createdAt: version.created_at,
      bytes: [...blobs.values()].reduce((total, size) => total + size, 0),
    })
  }

  return {
    bytes: [...packageBlobs.values()].reduce((total, size) => total + size, 0),
    versions: sized,
  }
}

// --- npm sizes -----------------------------------------------------------------------

async function packument(pat, owner, packageName) {
  // A scoped name is a single path segment, so the slash separating scope from name has
  // to be percent-encoded. The segment is assembled from already-encoded parts rather
  // than escaped after the fact, which would leave any further separator untouched.
  const candidates = [
    {
      name: `@${owner}/${packageName}`,
      segment: `@${encodeURIComponent(owner)}%2F${encodeURIComponent(packageName)}`,
    },
    { name: packageName, segment: encodeURIComponent(packageName) },
  ]

  for (const { name, segment } of candidates) {
    const res = await fetch(`${NPM_REGISTRY}/${segment}`, {
      headers: { Authorization: `Bearer ${pat}`, Accept: 'application/json' },
    })

    if (res.ok) return res.json()
    if (res.status !== 404) throw new Error(`npm packument ${name} failed: ${res.status} ${res.statusText}`)
  }

  return null
}

/** The registry rejects HEAD, so read Content-Length off the GET and drop the body. */
async function tarballSize(pat, url) {
  const res = await fetch(url, { headers: { Authorization: `Bearer ${pat}` } })

  if (!res.ok) {
    await res.body?.cancel()
    throw new Error(`npm tarball ${url} failed: ${res.status} ${res.statusText}`)
  }

  const length = res.headers.get('content-length')
  await res.body?.cancel()

  return length === null ? null : Number(length)
}

/** npm versions are independent tarballs — nothing is shared, so the total is their sum. */
async function npmSizes(pat, owner, packageName, versions) {
  const document = await packument(pat, owner, packageName)

  if (document === null) return { bytes: null, versions: [] }

  const sized = []

  for (const version of versions) {
    const tarball = document.versions?.[version.name]?.dist?.tarball ?? null
    sized.push({
      name: version.name,
      tags: [],
      createdAt: version.created_at,
      bytes: tarball === null ? null : await tarballSize(pat, tarball),
    })
  }

  const known = sized.filter((version) => version.bytes !== null)

  return {
    bytes: known.length === 0 ? null : known.reduce((total, version) => total + version.bytes, 0),
    versions: sized,
  }
}

// --- formatting ---------------------------------------------------------------------

function human(bytes) {
  if (bytes === null || bytes === undefined) return 'n/a'

  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  let value = bytes
  let unit = 0

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

function totals(packages) {
  return {
    public: packages.filter((p) => p.visibility === 'public').reduce((total, p) => total + (p.bytes ?? 0), 0),
    private: packages.filter((p) => p.visibility !== 'public').reduce((total, p) => total + (p.bytes ?? 0), 0),
    unsized: packages.filter((p) => p.bytes === null).length,
  }
}

function row(name, type, visibility, bytes, suffix = '') {
  return `  ${name.padEnd(44)} ${type.padEnd(10)} ${visibility.padEnd(9)} ${human(bytes).padStart(10)}${suffix}`
}

function versionRows(pkg) {
  const lines = []
  const sorted = [...pkg.versions].sort((a, b) => (b.bytes ?? 0) - (a.bytes ?? 0))

  for (const version of sorted) {
    const label = version.name.startsWith('sha256:') ? version.name.slice(7, 19) : version.name
    const date = (version.createdAt ?? '').slice(0, 10)
    const tags = version.tags.length > 0 ? `  [${version.tags.join(', ')}]` : ''
    lines.push(`      ${label.padEnd(40)} ${date.padEnd(12)} ${human(version.bytes).padStart(10)}${tags}`)
  }

  const sum = sorted.reduce((total, version) => total + (version.bytes ?? 0), 0)
  if (pkg.bytes !== null && sum !== pkg.bytes) {
    lines.push(`      ${'(sum of versions, before dedup)'.padEnd(40)} ${''.padEnd(12)} ${human(sum).padStart(10)}`)
  }

  return lines
}

// --- main ---------------------------------------------------------------------------

async function sizesFor(token, owner, type, pkg) {
  if (!withSizes || (type !== 'container' && type !== 'npm')) return { bytes: null, versions: [] }

  const versions = await apiPaged(token, `/user/packages/${type}/${encodeURIComponent(pkg.name)}/versions`)

  return type === 'container'
    ? containerSizes(token, owner, pkg.name, versions)
    : npmSizes(token, owner, pkg.name, versions)
}

async function collect(token, owner) {
  const repos = (await apiPaged(token, '/user/repos?affiliation=owner&sort=full_name'))
    .filter((repo) => repoFilter === null || repo.name === repoFilter)
    .map((repo) => ({ name: repo.name, private: repo.private, packages: [] }))

  if (repoFilter !== null && repos.length === 0) {
    throw new Error(`No owned repository named "${repoFilter}".`)
  }

  const byName = new Map(repos.map((repo) => [repo.name, repo]))
  const unmatched = []

  for (const type of PACKAGE_TYPES) {
    let packages

    try {
      packages = await apiPaged(token, `/user/packages?package_type=${type}`)
    } catch (error) {
      if (error.status === 403 || error.status === 404) {
        console.error(`! skipping package_type=${type}: HTTP ${error.status} (token lacks read:packages, or the type is unavailable)`)
        continue
      }

      throw error
    }

    for (const pkg of packages) {
      const repo = pkg.repository ? byName.get(pkg.repository.name) : null
      if (repoFilter !== null && !repo) continue

      const { bytes, versions } = await sizesFor(token, owner, type, pkg)
      const entry = { name: pkg.name, type, visibility: pkg.visibility, bytes, versions }

      if (repo) repo.packages.push(entry)
      else unmatched.push({ ...entry, repository: pkg.repository?.full_name ?? null })
    }
  }

  return { repos, unmatched }
}

function report(owner, repos, unmatched) {
  const scope = repoFilter === null ? `${repos.length} owned repositories` : `repository ${repoFilter}`
  console.log(`GitHub account: ${owner} — ${scope}\n`)

  const all = []

  for (const repo of repos) {
    if (repo.packages.length === 0) continue

    all.push(...repo.packages)
    console.log(`${repo.name} [${repo.private ? 'private' : 'public'} repo]`)

    for (const pkg of repo.packages) {
      const count = pkg.versions.length > 0 ? `  ${pkg.versions.length} version(s)` : ''
      console.log(row(pkg.name, pkg.type, pkg.visibility, pkg.bytes, count))
      if (withVersions) for (const line of versionRows(pkg)) console.log(line)
    }

    const total = totals(repo.packages)
    const note = total.unsized > 0 ? `  (+${total.unsized} package(s) of unreported size)` : ''
    console.log(row('-> total', '', 'public', total.public))
    console.log(row('', '', 'private', total.private))
    console.log(row('', '', 'all', total.public + total.private, note))
    console.log()
  }

  const empty = repos.filter((repo) => repo.packages.length === 0)
  if (empty.length > 0) {
    console.log(`Repositories without packages (${empty.length}): ${empty.map((repo) => repo.name).join(', ')}\n`)
  }

  if (unmatched.length > 0) {
    console.log(`Packages not attached to an owned repository (${unmatched.length}):`)
    for (const pkg of unmatched) {
      console.log(row(pkg.name, pkg.type, pkg.visibility, pkg.bytes, `  ${pkg.repository ?? '(none)'}`))
    }
    console.log()
  }

  const grand = totals(all)
  const note = grand.unsized > 0 ? `  (+${grand.unsized} package(s) of unreported size)` : ''
  console.log(repoFilter === null ? 'Grand total across owned repositories' : `Total for ${repoFilter}`)
  console.log(`  public  ${human(grand.public).padStart(10)}  (public packages are not billed)`)
  console.log(`  private ${human(grand.private).padStart(10)}  (counts against the packages storage quota)`)
  console.log(`  all     ${human(grand.public + grand.private).padStart(10)}${note}`)
}

async function main() {
  const token = resolveToken()
  const owner = (await api(token, '/user')).login
  const { repos, unmatched } = await collect(token, owner)

  if (asJson) {
    const payload = {
      owner,
      repositories: repos.map((repo) => ({ ...repo, totals: totals(repo.packages) })),
      unmatchedPackages: unmatched,
    }
    console.log(JSON.stringify(payload, null, 2))

    return
  }

  report(owner, repos, unmatched)
}

main().catch((error) => {
  console.error(`Error: ${error.message ?? error}`)
  process.exit(1)
})
