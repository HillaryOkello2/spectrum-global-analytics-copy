<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway would not start a payment: it was unreachable, answered with an
 * error, or was given something it cannot charge. PaymentInitiator catches it
 * and fails the payment so the payer can retry.
 */
class PaymentInitiationFailedException extends RuntimeException {}
