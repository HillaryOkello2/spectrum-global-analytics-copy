<?php

namespace App\Exceptions\Domain;

class ProductNotProofreadException extends DomainException
{
    public function __construct(string $message = 'This product cannot be approved until a proofreader has submitted the document.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'product_not_proofread';
    }
}
