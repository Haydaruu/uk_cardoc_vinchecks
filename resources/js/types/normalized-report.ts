export type AvailabilitySection<T = unknown> = {
    available: boolean;
    count?: number | null;
    records?: T[];
};

export type SalvageRecord = {
    salvage_auction_record_id:
        | string
        | number
        | null;

    salvage_auction_reference?:
        | string
        | number
        | null;

    salvage_auction_lot_desc:
        | string
        | null;

    salvage_auction_lot_date:
        | string
        | null;

    mileage:
        | number
        | null;

    primary_damage_desc:
        | string
        | null;

    secondary_damage_desc:
        | string
        | null;

    salvage_auction_location:
        | string
        | null;

    external_image_urls:
        string[];

    /*
     * Tetap izinkan vendor menambah field
     * yang belum kita normalize secara eksplisit.
     */
    [key: string]: unknown;
};

export type NormalizedReport = {
    meta: {
        scheme_version: number;
        format: 'normalized';
        provider: string;
        environment?: string;
        generated_at?: string;
    };

    vehicle: {
        vrm: string | null;
        vin: string | null;
        make: string | null;
        model: string | null;
        year: number | null;
        colour: string | null;
        fuel_type: string | null;
        body_type: string | null;
        transmission: string | null;
        engine_capacity_cc: number | null;
        image_url: string | null;
    };

    status: {
        scrapped: boolean | null;
        scrapped_date: string | null;
        certificate_of_destruction_issued: boolean | null;
        imported: boolean | null;
        exported: boolean | null;
    };

    v5c: {
        count: number | null;
        history: {
            issue_date: string | null;
        }[];
        latest_issue_date?: string | null;
        serial_verified: boolean | null;
        verification_available: boolean;
    };

    history: {
        finance: AvailabilitySection;
        stolen: AvailabilitySection;
        write_off: AvailabilitySection;
        high_risk: AvailabilitySection;

        keepers: AvailabilitySection<{
            previous_keepers: number | null;
            change_date: string | null;
        }>;

        plate_changes: AvailabilitySection<{
            current_vrm: string | null;
            previous_vrm: string | null;
            transfer_date: string | null;
            receipt_date: string | null;
            transfer_type: string | null;
        }>;

        colour_changes: AvailabilitySection;
    };

    salvage: {
        available: boolean;
        record_found: boolean | null;
        records: SalvageRecord[];
    };

    specifications: {
        power_bhp: number | null;
        power_ps: number | null;
        power_kw: number | null;
        torque_nm: number | null;
        torque_lbft: number | null;
        euro_standard: string | null;
        co2_gkm: number | null;
        combined_mpg: number | null;
        top_speed_mph: number | null;
        zero_to_sixty_mph: number | null;
        insurance_group: string | number | null;
    };

    dimensions: {
        height_mm: number | null;
        length_mm: number | null;
        width_mm: number | null;
        wheelbase_mm: number | null;
        kerb_weight_kg: number | null;
        gross_weight_kg: number | null;
        seats: number | null;
        doors: number | null;
    };

    valuation: {
        retail: number | null;
        trade_in: number | null;
        private: number | null;
        trade: number | null;
    };

    recalls: {
        available: boolean;
        status: string | null;
        source: string | null;
        count: number | null;
        records: Record<string, unknown>[];
        manufacturer_url: string | null;
    };

    emissions_compliance: {
        fuel_type: string | null;
        euro_standard: string | null;
        meets_minimum_standard: boolean | null;
        assessment_type: string;
        officially_verified: boolean;
        reason: string | null;
    };

    running_costs: {
        road_tax: {
            available: boolean;
            first_year?: number | null;
            annual?: number | null;
            premium_annual?: number | null;
            six_month?: number | null;
            is_premium?: boolean | null;
        };

        insurance: {
            available: boolean;
            age_20?: number | null;
            age_30?: number | null;
            age_40?: number | null;
            age_50?: number | null;
            assessment_type?: string;
        };

        fuel: {
            available: boolean;
            combined_mpg?: number | null;
            assessment_type?: string;
        };
    };

    mot: {
        service_available: boolean;

        current?: {
            mot_status: string | null;

            mot_status_source:
                | 'provider'
                | 'derived'
                | 'unavailable';

            mot_expiry_date: string | null;

            tax_service_available: boolean;

            tax_status: string | null;

            tax_expiry_date: string | null;
        };

        /*
        * Legacy compatibility.
        */
        status?: string | null;
        expiry_date?: string | null;
        summary?: unknown;

        tax_status?: string | null;
        tax_expiry_date?: string | null;

        tests: {
            test_number: string | number | null;
            date: string | null;
            expiry_date: string | null;
            result: string | null;
            mileage: number | null;
            mileage_unit: string | null;

            defects: {
                type: string | null;
                description: string | null;
                dangerous: boolean;
            }[];
        }[];

        mileage_history: {
            date: string | null;
            mileage: number | null;
            result: string | null;
        }[];
    };
};