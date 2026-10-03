<?php

namespace App\Domain\Billing\Gateways;

use RuntimeException;

/** The provider could not be reached or refused the request. The message is safe to log, never to show. */
class GatewayException extends RuntimeException {}
