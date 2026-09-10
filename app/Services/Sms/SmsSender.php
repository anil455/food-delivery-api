<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Plain transactional SMS.
 *
 * Deliberately narrower than OtpChannel: no purpose, no template id, just a
 * message. Swapping providers is a config change and touches nothing outside
 * this namespace.
 */
interface SmsSender
{
    /**
     * @param  string  $phone  E.164
     *
     * @throws SmsDeliveryException
     */
    public function send(string $phone, string $message): void;
}
