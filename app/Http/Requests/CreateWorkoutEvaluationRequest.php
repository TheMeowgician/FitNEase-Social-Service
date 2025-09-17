<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWorkoutEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => 'required|integer|exists:groups,group_id',
            'workout_id' => 'required|integer',
            'evaluation_type' => 'required|in:like,unlike',
            'comment' => 'nullable|string|max:1000|min:3'
        ];
    }

    public function messages(): array
    {
        return [
            'group_id.required' => 'Group ID is required',
            'group_id.exists' => 'Selected group does not exist',
            'workout_id.required' => 'Workout ID is required',
            'evaluation_type.required' => 'Evaluation type is required',
            'evaluation_type.in' => 'Evaluation type must be either like or unlike',
            'comment.min' => 'Comment must be at least 3 characters',
            'comment.max' => 'Comment cannot exceed 1000 characters'
        ];
    }
}