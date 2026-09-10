<?php

declare(strict_types=1);

namespace App\Services\Sms;

use RuntimeException;

/** The provider refused or failed. Retried by the queue, never shown to a user. */
final class SmsDeliveryException extends RuntimeException {}
