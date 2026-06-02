## Payments API: payments-service: Secret, Vulnerability (3 alerts, 3 files)

| Severity | Count |
| --- | ---: |
| Critical | 1 |
| High | 2 |

## Secret

### Description

The repository contains a committed personal access token.

### Remediation

Rotate the token and move it into the credential vault.

### Occurrences
- Payments API/payments-service/config/secrets.php:18 ([source alert](https://example.test/alerts/secret-101))
- Payments API/payments-service/database/seeders/DemoSeeder.php:44 ([source alert](https://example.test/alerts/secret-102))

## Vulnerability

### Description

Unsanitized input reaches a SQL query.

### Remediation

Use parameterized queries for every database call.

### Occurrences
- Payments API/payments-service/src/Repositories/UserRepository.php:71 ([source alert](https://example.test/alerts/vuln-201))