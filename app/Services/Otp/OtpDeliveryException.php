<?php

declare(strict_types=1);

namespace App\Services\Otp;

use RuntimeException;

/** A provider refused or failed to deliver. Retried by the queue, never shown to the client. */
final class OtpDeliveryException extends RuntimeException {}
