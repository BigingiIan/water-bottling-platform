<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewAsReadOnlyException extends HttpException
{
    public const MESSAGE = 'view_as_read_only';

    public function __construct()
    {
        parent::__construct(403, self::MESSAGE);
    }
}
