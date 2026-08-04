<?php

namespace App\Contracts;

interface VehicleDataProviderInterface
{
    public function perfomeCheck(string $vrm, string $tier): array;
}
