<?php

namespace App\Assets;

/**
 * The C# compiler's own diagnostics (CS0246, CS0518, ...), which Roslynator passes through in
 * its SARIF report next to its analyzer findings. They say whether the build worked (a missing
 * reference, an unrestored package), not anything about the code's quality or security, and a
 * solution that did not build reports thousands of them. They are never stored as local findings.
 */
final class CompilerDiagnostics
{
    public static function isCompilerDiagnostic(string $reportKind, string $ruleId): bool
    {
        return $reportKind === AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET
            && strlen($ruleId) === 6
            && str_starts_with($ruleId, 'CS')
            && ctype_digit(substr($ruleId, 2));
    }
}
