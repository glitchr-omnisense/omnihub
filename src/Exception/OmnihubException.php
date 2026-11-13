<?php

namespace Omnihub\Exception;

use Omnisense\Contracts\Exception\ExceptionInterface;

/** Every exception omnihub's HTTP calls throw (and omnisense/contracts' interface, so one catch holds every family's). */
interface OmnihubException extends ExceptionInterface
{
}
