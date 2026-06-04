## Payments API: payments-service: Dependency + 2 more (10 alerts, 10 files)

| Severity | Count |
| --- | ---: |
| Critical | 1 |
| High | 6 |
| Medium | 3 |

## Secret

### Description

A plaintext credential was committed to the repository.

### Remediation

Rotate the secret and remove it from git history.

### Occurrences
- Payments API/payments-service/config/secret-1.php:11 ([source alert](https://example.test/alerts/secret-001))
- Payments API/payments-service/config/secret-2.php:12 ([source alert](https://example.test/alerts/secret-002))
- Payments API/payments-service/config/secret-3.php:13 ([source alert](https://example.test/alerts/secret-003))
- Payments API/payments-service/config/secret-4.php:14 ([source alert](https://example.test/alerts/secret-004))

## Vulnerability

### Description

Unsanitized input reaches a database call.

### Remediation

Use prepared statements and validated identifiers.

### Occurrences
- Payments API/payments-service/src/Query/Builder1.php:41 ([source alert](https://example.test/alerts/vuln-001))
- Payments API/payments-service/src/Query/Builder2.php:42 ([source alert](https://example.test/alerts/vuln-002))
- Payments API/payments-service/src/Query/Builder3.php:43 ([source alert](https://example.test/alerts/vuln-003))

## Dependency

### Description

The package version is affected by a published CVE.

### Remediation

Upgrade the affected package to a patched release.

### Occurrences
- Payments API/payments-service/package-lock-1.json:6 ([source alert](https://example.test/alerts/dep-001))
- Payments API/payments-service/package-lock-2.json:7 ([source alert](https://example.test/alerts/dep-002))
- Payments API/payments-service/package-lock-3.json:8 ([source alert](https://example.test/alerts/dep-003))
