<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which scan report (attachment kind) a local finding comes from, so a re-scan only
 * resolves the findings of its own tool: Roslynator, SpotBugs and Opengrep all store
 * code_quality findings on the same repository.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->string('report_kind', 64)->nullable()->after('kind');
        });

        DB::table('local_findings')->where('kind', 'vulnerability')->update(['report_kind' => 'vulnerabilities']);
        DB::table('local_findings')->where('kind', 'secret')->update(['report_kind' => 'secrets']);

        foreach (['code-quality-dotnet', 'code-quality-java', 'code-quality-opengrep'] as $reportKind) {
            DB::table('local_findings')
                ->where('kind', 'code_quality')
                ->whereIn('attachment_id', DB::table('attachments')->select('id')->where('kind', $reportKind))
                ->update(['report_kind' => $reportKind]);
        }
    }

    public function down(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropColumn('report_kind');
        });
    }
};
