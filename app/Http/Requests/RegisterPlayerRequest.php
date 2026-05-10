<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discord_id'       => 'required|string|max:20',
            'username'         => 'required|string|max:80',
            'age'              => 'nullable|integer|min:13|max:99',
            'country_code'     => 'nullable|string|size:2',
            'experience_level' => 'nullable|integer|min:1|max:5',
            'verbosity_level'  => 'nullable|integer|min:1|max:5',
            'nationality'      => 'nullable|string|max:100',
            'schedule_raw'     => 'nullable|string|max:200',
            'schedule'         => 'nullable|array',
            'raw_red_lines'    => 'nullable|string|max:500',
            'raw_yellow_lines' => 'nullable|string|max:500',
            'raw_preferences'  => 'nullable|string|max:500',
            'narrative_style'  => 'nullable|string|max:2000',
            'about_me'         => 'nullable|string|max:1000',
        ];
    }
}
