<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppSecScoutPanelProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    AppSecScoutPanelProvider::class,
    FortifyServiceProvider::class,
];
