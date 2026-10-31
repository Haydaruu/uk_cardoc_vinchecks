<?php

namespace App\Contracts;

interface VehicleDataProviderInterface
{
    /** @return array<string, mixed> */
    public function performCheck(string $vrm, string $tier): array;

    /** @return array<string, mixed> */
    public function getRegistrationDetails(string $vrm): array;

    /** @return array<string, mixed> */
    public function getHistoryCheck(string $vrm): array;

    /** @return array<string, mixed> */
    public function getMotHistory(string $vrm): array;

    /** @return array<string, mixed> */
    public function getMileageHistory(string $vrm): array;

    /** @return array<string, mixed> */
    public function getVehicleImage(string $vrm): array;

    /** @return array<string, mixed> */
    public function getVehicleValuation(string $vrm): array;
}
