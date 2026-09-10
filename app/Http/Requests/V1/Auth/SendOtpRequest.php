<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use App\Services\Phone\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Normalises the phone number to E.164 before validation runs, so every layer
 * below this point deals with exactly one representation of a number.
 */
class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('phone');

        if (is_string($raw)) {
            $this->merge([
                'phone' => app(PhoneNumberService::class)->normalize($raw) ?? $raw,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, callable $fail): void {
                if (! app(PhoneNumberService::class)->isValid((string) $value)) {
                    $fail('Enter a valid mobile number including country code.');
                }
            }],
        ];
    }

    public function phone(): string
    {
        return (string) $this->validated('phone');
    }
}
