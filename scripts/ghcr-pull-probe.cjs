#!/usr/bin/env node
/**
 * Probe every blob (config + layers) of one or more ghcr.io image references, following
 * each blob's redirect off ghcr.io to its actual CDN host and downloading it, to find out
 * which individual layers a corporate proxy (e.g. Netskope) blocks, corrupts, or stalls on
 * — `docker pull` only reports the tag as failed, never which blob or why.
 *
 * Run this from wherever `docker pull` itself fails (the corporate laptop, outside any
 * container) so it takes the same network path. Node has its own TLS trust store separate
 * from the OS one: if you see certificate errors rather than clean HTTP blocks/resets/
 * timeouts, point NODE_EXTRA_CA_CERTS at the corporate root CA (the same cert used for the
 * containers' `.docker/certs` trust — see CLAUDE.md) and re-run before trusting the result.
 *
 * Usage:
 *   node .\scripts\ghcr-pull-probe.cjs [image...] [--platform os/arch] [--mode quick|full]
 *                                      [--quick-bytes N] [--concurrency N] [--retries N]
 *                                      [--timeout ms] [--token <pat>] [--json]
 *
 * Defaults to the three appsec-scout-2 images if none are given. --mode quick (default)
 * range-fetches only the first --quick-bytes of each blob — fast, but a proxy that only
 * blocks past some size threshold will look fine here. --mode full downloads every byte
 * and checks the count against the manifest size, which catches silent truncation too;
 * use it to confirm anything --mode quick flags as OK but you still suspect.
 */

const REGISTRY = 'https://ghcr.io'
const DEFAULT_IMAGES = [
  'metanull/appsec-scout-2/app',
  'metanull/appsec-scout-2/collector',
  'metanull/appsec-scout-2/static-analysis-collector',
]
const MANIFEST_TYPES = [
  'application/vnd.oci.image.index.v1+json',
  'application/vnd.docker.distribution.manifest.list.v2+json',
  'application/vnd.oci.image.manifest.v1+json',
  'application/vnd.docker.distribution.manifest.v2+json',
].join(', ')

const args = process.argv.slice(2)
const flags = { platform: 'linux/amd64', mode: 'quick', quickBytes: 1048576, concurrency: 3, retries: 1, timeout: 30000, token: null, json: false }
const positional = []

for (let i = 0; i < args.length; i++) {
  const a = args[i]
  if (a === '--json') { flags.json = true; continue }
  if (a === '--platform') { flags.platform = args[++i]; continue }
  if (a === '--mode') { flags.mode = args[++i]; continue }
  if (a === '--quick-bytes') { flags.quickBytes = Number(args[++i]); continue }
  if (a === '--concurrency') { flags.concurrency = Number(args[++i]); continue }
  if (a === '--retries') { flags.retries = Number(args[++i]); continue }
  if (a === '--timeout') { flags.timeout = Number(args[++i]); continue }
  if (a === '--token') { flags.token = args[++i]; continue }
  positional.push(a)
}

if (process.env.GITHUB_TOKEN && !flags.token) flags.token = process.env.GITHUB_TOKEN.trim()
const targets = positional.length > 0 ? positional : DEFAULT_IMAGES

// --- image/manifest resolution -------------------------------------------------------

function parseImage(ref) {
  const stripped = ref.replace(/^ghcr\.io\//, '')
  const [withoutDigest, digest] = stripped.split('@')
  if (digest) return { repository: withoutDigest.toLowerCase(), reference: `sha256:${digest.replace(/^sha256:/, '')}` }

  const lastColon = withoutDigest.lastIndexOf(':')
  const lastSlash = withoutDigest.lastIndexOf('/')
  if (lastColon > lastSlash) return { repository: withoutDigest.slice(0, lastColon).toLowerCase(), reference: withoutDigest.slice(lastColon + 1) }
  return { repository: withoutDigest.toLowerCase(), reference: 'latest' }
}

async function registryToken(pat, repository) {
  const scope = encodeURIComponent(`repository:${repository}:pull`)
  const headers = pat ? { Authorization: `Basic ${Buffer.from(`x:${pat}`).toString('base64')}` } : {}
  const res = await fetch(`${REGISTRY}/token?service=ghcr.io&scope=${scope}`, { headers })
  if (!res.ok) throw new Error(`token request for ${repository} failed: ${res.status} ${res.statusText}`)
  return (await res.json()).token
}

async function fetchManifest(bearer, repository, reference) {
  const res = await fetch(`${REGISTRY}/v2/${repository}/manifests/${reference}`, {
    headers: { Authorization: `Bearer ${bearer}`, Accept: MANIFEST_TYPES },
  })
  if (!res.ok) throw new Error(`manifest ${repository}@${reference} failed: ${res.status} ${res.statusText}`)
  return res.json()
}

async function resolveManifest(bearer, repository, reference, platform) {
  const [os, arch] = platform.split('/')
  let doc = await fetchManifest(bearer, repository, reference)

  while (Array.isArray(doc.manifests)) {
    const match = doc.manifests.find((m) => m.platform?.os === os && m.platform?.architecture === arch) ?? doc.manifests[0]
    if (!match) throw new Error(`no child manifest for platform ${platform} in ${repository}@${reference}`)
    doc = await fetchManifest(bearer, repository, match.digest)
  }

  return doc
}

function blobsOf(manifestDoc) {
  const blobs = [manifestDoc.config, ...(manifestDoc.layers ?? [])].filter((b) => b?.digest)
  return blobs.map((b) => ({ digest: b.digest, size: b.size ?? 0, mediaType: b.mediaType }))
}

// --- probing ---------------------------------------------------------------------------

async function drain(body) {
  if (!body) return 0
  const reader = body.getReader()
  let total = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) return total
    total += value.byteLength
  }
}

function describeError(error) {
  const cause = error.cause ? ` (${error.cause.code ?? error.cause.message ?? error.cause})` : ''
  if (error.name === 'TimeoutError' || error.name === 'AbortError') return `timed out${cause}`
  return `${error.message}${cause}`
}

async function probeOnce(bearer, repository, blob) {
  const blobUrl = `${REGISTRY}/v2/${repository}/blobs/${blob.digest}`
  const started = Date.now()
  let redirectHost = null
  let redirectPrefix = null
  let finalUrl = blobUrl

  try {
    const head = await fetch(blobUrl, {
      headers: { Authorization: `Bearer ${bearer}` },
      redirect: 'manual',
      signal: AbortSignal.timeout(flags.timeout),
    })

    if (head.status === 307 || head.status === 302) {
      const location = head.headers.get('location')
      const parsed = new URL(location)
      redirectHost = parsed.host
      redirectPrefix = parsed.pathname.split('/').filter(Boolean)[0] ?? null
      finalUrl = location
    } else {
      await head.body?.cancel()
    }
  } catch (error) {
    return { redirectHost, redirectPrefix, status: 'ERROR', detail: `resolving redirect: ${describeError(error)}`, elapsedMs: Date.now() - started, bytesReceived: 0 }
  }

  const headers = finalUrl === blobUrl ? { Authorization: `Bearer ${bearer}` } : {}
  const expected = flags.mode === 'quick' ? Math.min(flags.quickBytes, blob.size) : blob.size
  if (flags.mode === 'quick' && blob.size > 0) headers.Range = `bytes=0-${expected - 1}`

  try {
    const res = await fetch(finalUrl, { headers, signal: AbortSignal.timeout(flags.timeout) })
    const contentType = res.headers.get('content-type') ?? ''

    if (!res.ok) {
      const snippet = (await res.text()).slice(0, 800).replace(/\s+/g, ' ').trim()
      return {
        redirectHost, redirectPrefix, status: 'BLOCKED',
        detail: `HTTP ${res.status} ${res.statusText}${snippet ? ` — body: ${snippet}` : ' — empty body'}`,
        elapsedMs: Date.now() - started, bytesReceived: 0,
      }
    }

    const bytesReceived = await drain(res.body)
    const elapsedMs = Date.now() - started

    if (contentType.includes('text/html') || contentType.includes('text/plain')) {
      return { redirectHost, redirectPrefix, status: 'SUSPECT', detail: `content-type "${contentType}" — likely a proxy page substituted for binary data`, elapsedMs, bytesReceived }
    }
    if (bytesReceived !== expected) {
      return { redirectHost, redirectPrefix, status: 'TRUNCATED', detail: `received ${bytesReceived}B, expected ${expected}B`, elapsedMs, bytesReceived }
    }
    return { redirectHost, redirectPrefix, status: 'OK', detail: '', elapsedMs, bytesReceived }
  } catch (error) {
    return { redirectHost, redirectPrefix, status: 'ERROR', detail: describeError(error), elapsedMs: Date.now() - started, bytesReceived: 0 }
  }
}

async function probeWithRetries(bearer, repository, blob) {
  const attempts = []
  for (let i = 0; i < flags.retries; i++) attempts.push(await probeOnce(bearer, repository, blob))
  return attempts
}

async function pool(items, limit, worker) {
  const results = new Array(items.length)
  let next = 0

  async function run() {
    while (next < items.length) {
      const i = next++
      results[i] = await worker(items[i], i)
    }
  }

  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, run))
  return results
}

// --- reporting ---------------------------------------------------------------------------

function human(bytes) {
  const units = ['B', 'KiB', 'MiB', 'GiB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++ }
  return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

function worstStatus(attempts) {
  const order = ['OK', 'TRUNCATED', 'SUSPECT', 'BLOCKED', 'ERROR']
  return attempts.map((a) => a.status).sort((a, b) => order.indexOf(b) - order.indexOf(a))[0]
}

function printReport(results) {
  const sorted = [...results].sort((a, b) => b.blob.size - a.blob.size)

  console.log(`mode=${flags.mode} platform=${flags.platform} retries=${flags.retries}${flags.mode === 'quick' ? ` quick-bytes=${flags.quickBytes}` : ''}\n`)
  console.log('STATUS     SIZE        ELAPSED  SHARD                 HOST                                  DIGEST            IMAGES')

  for (const r of sorted) {
    const status = worstStatus(r.attempts)
    const flaky = new Set(r.attempts.map((a) => a.status)).size > 1 ? ' (flaky)' : ''
    const best = r.attempts[r.attempts.length - 1]
    console.log(
      `${status.padEnd(10)} ${human(r.blob.size).padStart(10)}  ${String(best.elapsedMs).padStart(6)}ms  ${(best.redirectPrefix ?? '-').padEnd(20)}  ${(best.redirectHost ?? '-').padEnd(36)}  ${r.blob.digest.slice(7, 19)}  ${[...r.images].join(',')}${flaky}`
    )
    if (status !== 'OK') for (const a of r.attempts) if (a.detail) console.log(`             -> ${a.detail}`)
  }

  const byStatus = {}
  for (const r of sorted) { const s = worstStatus(r.attempts); byStatus[s] = (byStatus[s] ?? 0) + 1 }
  console.log(`\nsummary: ${Object.entries(byStatus).map(([s, n]) => `${s}=${n}`).join('  ')}`)

  const bad = sorted.filter((r) => worstStatus(r.attempts) !== 'OK')
  const good = sorted.filter((r) => worstStatus(r.attempts) === 'OK')
  if (bad.length > 0 && good.length > 0) {
    console.log(`smallest failing blob: ${human(Math.min(...bad.map((r) => r.blob.size)))}  largest passing blob: ${human(Math.max(...good.map((r) => r.blob.size)))}`)
    const badShards = new Set(bad.map((r) => r.attempts[0].redirectPrefix))
    const goodShards = new Set(good.map((r) => r.attempts[0].redirectPrefix))
    console.log(`shards seen only on failures: ${[...badShards].filter((s) => !goodShards.has(s)).join(', ') || 'none'}`)
  }
}

// --- main ---------------------------------------------------------------------------

async function main() {
  const perDigest = new Map() // digest -> { blob, images:Set, attempts, sourceRepository }

  for (const ref of targets) {
    const { repository, reference } = parseImage(ref)
    process.stderr.write(`resolving ${repository}@${reference} ...\n`)
    const bearer = await registryToken(flags.token, repository)
    const manifestDoc = await resolveManifest(bearer, repository, reference, flags.platform)
    const blobs = blobsOf(manifestDoc)

    const toProbe = []
    for (const blob of blobs) {
      const entry = perDigest.get(blob.digest)
      if (entry) { entry.images.add(ref); continue }
      const fresh = { blob, images: new Set([ref]), attempts: null, sourceRepository: repository }
      perDigest.set(blob.digest, fresh)
      toProbe.push(fresh)
    }

    process.stderr.write(`  ${blobs.length} blobs (${toProbe.length} not already probed via another image)\n`)

    await pool(toProbe, flags.concurrency, async (entry) => {
      entry.attempts = await probeWithRetries(bearer, repository, entry.blob)
    })
  }

  const results = [...perDigest.values()]

  if (flags.json) {
    console.log(JSON.stringify(results.map((r) => ({ ...r.blob, images: [...r.images], sourceRepository: r.sourceRepository, attempts: r.attempts })), null, 2))
    return
  }

  printReport(results)
}

main().catch((error) => {
  console.error(`Error: ${error.message ?? error}`)
  process.exit(1)
})
