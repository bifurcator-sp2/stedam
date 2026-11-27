<?php

use App\Providers\AppServiceProvider;
use App\Providers\CourseServiceProvider;
use App\Providers\Filament\StedamPanelProvider;
use App\Providers\RequestedActionServiceProvider;
use App\Providers\TestGeneratorServiceProvider;
use App\Providers\WebPushServiceProvider;

return [
    AppServiceProvider::class,
    CourseServiceProvider::class,
    StedamPanelProvider::class,
    RequestedActionServiceProvider::class,
    TestGeneratorServiceProvider::class,
    WebPushServiceProvider::class,
];
