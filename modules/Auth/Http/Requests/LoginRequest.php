<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Auth\Application\DTOs\LoginData;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function toData(): LoginData
    {
        return new LoginData(
            email: (string) $this->string('email'),
            password: (string) $this->string('password'),
            deviceName: (string) $this->string('device_name') ?: 'api',
        );
    }
}
