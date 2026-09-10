<?php

declare(strict_types=1);

namespace App\Services\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises every phone number the system sees to E.164.
 *
 * Without this, "+919999000011", "09999000011" and "9999000011" become three
 * different users with the same handset. Normalisation happens once, at the
 * boundary, and nothing downstream ever sees a raw input string.
 */
final class PhoneNumberService
{
    private PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    /** Returns E.164 (e.g. +919999000011), or null when the input is not a valid number. */
    public function normalize(string $input, ?string $region = null): ?string
    {
        $region ??= (string) config('otp.default_phone_region', 'IN');

        try {
            $parsed = $this->util->parse(trim($input), $region);
        } catch (NumberParseException) {
            return null;
        }

        if (! $this->util->isValidNumber($parsed)) {
            return null;
        }

        return $this->util->format($parsed, PhoneNumberFormat::E164);
    }

    public function isValid(string $input, ?string $region = null): bool
    {
        return $this->normalize($input, $region) !== null;
    }

    /** Masked form for logs and error messages: +9199XXXXXX11 */
    public function mask(string $e164): string
    {
        $length = strlen($e164);

        if ($length <= 6) {
            return str_repeat('X', $length);
        }

        return substr($e164, 0, 4).str_repeat('X', $length - 6).substr($e164, -2);
    }
}
