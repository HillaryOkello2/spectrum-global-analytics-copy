<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
    PaymentServiceProvider::class,
];
