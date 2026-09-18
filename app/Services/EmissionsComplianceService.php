<?php

namespace App\Services;

class EmissionsComplianceService
{
    /**
     * Create a new class instance.
     */
    public function assess(?string $fuelType, ?string $euroStandard, ?string $vehicleType = null): array
    {
        $fuel = strtolower(trim($fuelType ?? ''));
        $euro = $this->extractEuroNumber($euroStandard);

        if(str_contains($fuel, 'electric')) {
            return $this->result(true, 'Electric vehicle');
        }

        if(!$euro) {
            return $this->result(null, 'Euro standard unavailable');
        }

        if(str_contains($fuel, 'petrol')) {
            return $this->result($euro >= 4, "Petrol Euro {$euro}");
        }

         if(str_contains($fuel, 'diesel')) {
            return $this->result($euro >= 6, "Diesel Euro {$euro}");
        }

        return $this->result(null, 'Fuel type not supported for automatic assessment');
    }

    private function extractEuroNumber(?string $value): ?int
    {
        if(! $value) return null;

        preg_match('/(\d+)/', $value, $matches);

        return isset($matches[1]) ? (int) $matches[1] :null;
    }

    private function result(?bool $meetsStandard, string $reason): array
    {
        return [
            'meets_minimum_standard' => $meetsStandard,
            'assessment_type' => 'derived',
            'reason' => $reason,
            'officially_verified' => false,
        ];
    }
}
