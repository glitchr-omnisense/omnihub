<?php

namespace Omnihub\Exception;

/**
 * A provider answered, and not with what was asked: an error status (a tarball's address
 * answering 404, GitHub's API 500), a body that could not be read. The status is its code.
 */
final class ProviderException extends \Omnisense\Contracts\Exception\ProviderException implements OmnihubException
{
}
