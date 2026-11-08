<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PayPalApiException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<int, array<string, mixed>>  $links
     */
    public function __construct(
        public readonly string $errorName,
        public readonly string $errorMessage,
        public readonly ?string $debugId = null,
        public readonly array $details = [],
        public readonly int $statusCode = 400,
        public readonly array $links = [],
        ?Throwable $previous = null,
    ) {
        $detailIssues = implode(', ', array_filter(array_column($this->details, 'issue')));
        $summary = sprintf(
            'PayPal API Error [%s]: %s (Status: %d, Debug ID: %s, Issues: [%s])',
            $errorName,
            $errorMessage,
            $statusCode,
            $debugId ?? 'none',
            $detailIssues !== '' ? $detailIssues : 'none',
        );

        parent::__construct($summary, $statusCode, $previous);
    }

    public static function fromResponse(Response $response, ?Throwable $previous = null): self
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        $name = (string) ($data['name'] ?? 'UNKNOWN_ERROR');
        $message = (string) ($data['message'] ?? 'No error message provided by PayPal');
        $debugId = $response->header('PayPal-Debug-Id') ?: ($data['debug_id'] ?? null);
        $details = is_array($data['details'] ?? null) ? $data['details'] : [];
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];

        return new self(
            errorName: $name,
            errorMessage: $message,
            debugId: $debugId ? (string) $debugId : null,
            details: $details,
            statusCode: $response->status(),
            links: $links,
            previous: $previous,
        );
    }

    public function hasIssue(string $issue): bool
    {
        if (strcasecmp($this->errorName, $issue) === 0) {
            return true;
        }

        foreach ($this->details as $detail) {
            if (isset($detail['issue']) && strcasecmp((string) $detail['issue'], $issue) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getIssue(): ?string
    {
        return $this->details[0]['issue'] ?? ($this->errorName !== '' ? $this->errorName : null);
    }

    public function getPayerActionUrl(): ?string
    {
        foreach ($this->details as $detail) {
            if (! empty($detail['links']) && is_array($detail['links'])) {
                foreach ($detail['links'] as $link) {
                    if (($link['rel'] ?? null) === 'payer-action' && isset($link['href'])) {
                        return (string) $link['href'];
                    }
                }
            }
        }

        foreach ($this->links as $link) {
            if (($link['rel'] ?? null) === 'payer-action' && isset($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }
}
