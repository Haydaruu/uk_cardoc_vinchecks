<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLogs extends Model
{
    protected $fillable = [
        'vin',
        'api_provider',
        'endpoint',
        'response_time_ms',
        'http_status_code',
        'request_payload',
        'response_payload',
        'error_message',
        'status',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
