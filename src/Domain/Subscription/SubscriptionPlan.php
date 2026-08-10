<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum SubscriptionPlan: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
}
