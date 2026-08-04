<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'provider' => ['required', 'string'],

            'display_name' => ['required', 'string', 'max:255'],

            'status' => ['required', 'boolean'],

        ];
    }
}
