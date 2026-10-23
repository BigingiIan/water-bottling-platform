<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * A business rule stopped an invoicing action; the message is safe to show to the user.
 */
class InvoicingException extends DomainException {}
