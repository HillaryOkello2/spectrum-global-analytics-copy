<?php

namespace App\Enums;

/**
 * Why a payment ended up `failed`. PGW only ever calls back on success, so most
 * failures are decided on this side: the gateway refused to start the payment,
 * nobody paid before the timeout, or the amount paid fell short.
 */
enum PaymentFailureReason: string
{
    case GatewayError = 'gateway_error';
    case Expired = 'expired';
    case Declined = 'declined';
    case AmountMismatch = 'amount_mismatch';
}
