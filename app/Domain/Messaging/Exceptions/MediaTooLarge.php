<?php

namespace App\Domain\Messaging\Exceptions;

use RuntimeException;

/** A file a contact sent is bigger than the inbox accepts; it is not downloaded. */
class MediaTooLarge extends RuntimeException {}
