<?php

namespace App\Filament\Exports;

use App\Models\StaticAnalysisRun;

class StaticAnalysisRunExporter extends CollectionRunExporter
{
    protected static ?string $model = StaticAnalysisRun::class;
}
