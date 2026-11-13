<?php

namespace Omnihub\Exception;

/** A provider could not be reached: a network error, a timeout. */
final class UnreachableException extends \Omnisense\Contracts\Exception\UnreachableException implements OmnihubException
{
}
