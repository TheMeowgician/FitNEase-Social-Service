<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_name' => 'required|string|max:100|min:3',
            'description' => 'nullable|string|max:500',
            'max_members' => 'nullable|integer|min:2|max:50',
            'is_private' => 'nullable|boolean',
            'group_image' => 'nullable|string|max:255'
        ];
    }

    public function messages(): array
    {
        return [
            'group_name.required' => 'Group name is required',
            'group_name.min' => 'Group name must be at least 3 characters',
            'group_name.max' => 'Group name cannot exceed 100 characters',
            'max_members.min' => 'Group must allow at least 2 members',
            'max_members.max' => 'Group cannot exceed 50 members',
            'description.max' => 'Description cannot exceed 500 characters'
        ];
    }
}