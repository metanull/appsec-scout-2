## Payments API: payments-service: Secret (1 alert, 1 file)

- Severity: Critical
- Type: Secret
- State: Open
- Rule: GHAS-SECRET-1
- Source Event ID: secret-101
- First Seen: 2026-05-01 08:00:00
- Last Seen: 2026-05-20 09:30:00

### Description

The repository contains a committed personal access token.

### Remediation

Rotate the token and move it into the credential vault.

### Occurrences
- Payments API/payments-service/config/secrets.php:18 ([alert](https://example.test/alerts/secret-101))