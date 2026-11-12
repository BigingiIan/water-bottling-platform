<?php

namespace App\Services\Forecasting;

enum ForecastStatus: string
{
    case Generated = 'generated';
    case InvalidDate = 'invalid_date';
    case UnknownSku = 'unknown_sku';
    case MissingCoveredDays = 'missing_covered_days';
    case MissingContext = 'missing_context';
    case ServiceUnreachable = 'service_unreachable';
    case Unauthorized = 'unauthorized';
    case NoModelForSku = 'no_model_for_sku';
    case ServiceError = 'service_error';
    case InvalidResponse = 'invalid_response';
    case NotStored = 'not_stored';
    case NotGenerated = 'not_generated';

    public function isAvailable(): bool
    {
        return $this === self::Generated;
    }
}
