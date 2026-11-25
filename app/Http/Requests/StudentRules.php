<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

// Shared by the student form and the import.
class StudentRules
{
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:30'],
            'middle_name' => ['nullable', 'string', 'min:2', 'max:30'],
            'last_name' => ['nullable', 'string', 'min:2', 'max:30'],
            'collage_name' => ['required', 'string', 'min:2', 'max:30'],
            'email' => ['required', 'email', 'regex:/^[\w\.\-]+@([\w\-]+\.)+[\w\-]{2,4}$/', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            'canteen_id' => ['required', 'string', 'min:1', 'max:10', Rule::unique('users', 'canteen_id')->ignore($ignoreId)],
            'phone_number' => ['required', 'regex:/^[0-9]{10}$/'],
            'profile_pic' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif'],
        ];
    }

    public static function messages(): array
    {
        return ['phone_number.regex' => 'Enter all 10 digits.'];
    }

    public static function attributes(): array
    {
        return ['name' => 'first name', 'collage_name' => 'college', 'canteen_id' => 'canteen ID', 'profile_pic' => 'photo'];
    }
}
