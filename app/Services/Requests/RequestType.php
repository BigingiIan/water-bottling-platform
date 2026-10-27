<?php

namespace App\Services\Requests;

enum RequestType: string
{
    case StockCheck = 'stock_check';
    case ProductionRequest = 'production_request';
    case LeakageReview = 'leakage_review';
    case DataCorrection = 'data_correction';
    case AccessRequest = 'access_request';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::StockCheck => 'Stock check',
            self::ProductionRequest => 'Production request',
            self::LeakageReview => 'Leakage review',
            self::DataCorrection => 'Data correction',
            self::AccessRequest => 'Access request',
            self::General => 'General',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::StockCheck => 'archive-box',
            self::ProductionRequest => 'play-circle',
            self::LeakageReview => 'beaker',
            self::DataCorrection => 'pencil-square',
            self::AccessRequest => 'key',
            self::General => 'chat-bubble-left-right',
        };
    }

    /**
     * Types a person can pick when writing a request. Access requests come only from the 403 page.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type !== self::AccessRequest));
    }
}
