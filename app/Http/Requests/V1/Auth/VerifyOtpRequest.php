<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use App\Services\Phone\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
        $length = (int) config('otp.length');

        return [
            'verification_id' => ['required', 'uuid'],
            'phone' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'string', "digits:{$length}"],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.digits' => 'The code must be :digits digits.',
        ];
    }
}
