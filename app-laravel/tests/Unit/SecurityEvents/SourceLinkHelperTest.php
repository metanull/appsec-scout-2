<?php

use App\SecurityEvents\SourceLinkHelper;

describe('SourceLinkHelper', function () {
    describe('cveLinkUrl', function () {
        it('returns NVD URL for a valid CVE ID', function () {
            expect(SourceLinkHelper::cveLinkUrl('CVE-2023-12345'))
                ->toBe('https://nvd.nist.gov/vuln/detail/CVE-2023-12345');
        });

        it('normalises lower-case CVE IDs to upper case', function () {
            expect(SourceLinkHelper::cveLinkUrl('cve-2021-44228'))
                ->toBe('https://nvd.nist.gov/vuln/detail/CVE-2021-44228');
        });

        it('returns null for null input', function () {
            expect(SourceLinkHelper::cveLinkUrl(null))->toBeNull();
        });

        it('returns null for an empty string', function () {
            expect(SourceLinkHelper::cveLinkUrl(''))->toBeNull();
        });

        it('returns null for a malformed CVE ID without year', function () {
            expect(SourceLinkHelper::cveLinkUrl('CVE-12345'))->toBeNull();
        });

        it('returns null for arbitrary text', function () {
            expect(SourceLinkHelper::cveLinkUrl('not-a-cve'))->toBeNull();
        });
    });

    describe('cweLinkUrl', function () {
        it('returns MITRE URL for a numeric CWE integer', function () {
            expect(SourceLinkHelper::cweLinkUrl(79))
                ->toBe('https://cwe.mitre.org/data/definitions/79.html');
        });

        it('returns MITRE URL for a CWE-prefixed string', function () {
            expect(SourceLinkHelper::cweLinkUrl('CWE-79'))
                ->toBe('https://cwe.mitre.org/data/definitions/79.html');
        });

        it('returns MITRE URL for a CWE string without prefix', function () {
            expect(SourceLinkHelper::cweLinkUrl('79'))
                ->toBe('https://cwe.mitre.org/data/definitions/79.html');
        });

        it('returns null for null input', function () {
            expect(SourceLinkHelper::cweLinkUrl(null))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(SourceLinkHelper::cweLinkUrl(''))->toBeNull();
        });

        it('returns null for non-numeric CWE string', function () {
            expect(SourceLinkHelper::cweLinkUrl('CWE-abc'))->toBeNull();
        });

        it('returns null for zero', function () {
            expect(SourceLinkHelper::cweLinkUrl(0))->toBeNull();
        });
    });

    describe('isSafeUrl', function () {
        it('accepts https URLs', function () {
            expect(SourceLinkHelper::isSafeUrl('https://example.com/path'))->toBeTrue();
        });

        it('accepts http URLs', function () {
            expect(SourceLinkHelper::isSafeUrl('http://example.com'))->toBeTrue();
        });

        it('rejects javascript: URLs', function () {
            expect(SourceLinkHelper::isSafeUrl('javascript:alert(1)'))->toBeFalse();
        });

        it('rejects file: URLs', function () {
            expect(SourceLinkHelper::isSafeUrl('file:///etc/passwd'))->toBeFalse();
        });

        it('rejects null', function () {
            expect(SourceLinkHelper::isSafeUrl(null))->toBeFalse();
        });

        it('rejects empty string', function () {
            expect(SourceLinkHelper::isSafeUrl(''))->toBeFalse();
        });
    });
});
