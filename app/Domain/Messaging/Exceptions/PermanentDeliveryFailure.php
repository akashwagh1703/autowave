<?php

namespace App\Domain\Messaging\Exceptions;

use RuntimeException;

/** Thrown by a provider when retrying cannot help. The delivery job fails the message at once. */
class PermanentDeliveryFailure extends RuntimeException {}
