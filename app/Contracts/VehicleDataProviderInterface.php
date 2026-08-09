<?php

namespace App\Contracts;

interface VehicleDataProviderInterface
{
    public function performCheck(string $vrm, string $tier): array;
    public function getRegistrationDetails(string $vrm): array;
    public function getHistoryCheck(string $vrm): array;
    public function getMotHistory(string $vrm): array;
}
