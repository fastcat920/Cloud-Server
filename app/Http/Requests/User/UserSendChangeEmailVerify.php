<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserSendChangeEmailVerify extends FormRequest
{
    public function rules()
    {
        return [
            'password' => 'required|string',
            'new_email' => 'required|email:strict|max:64'
        ];
    }

    public function messages()
    {
        return [
            'password.required' => __('Password can not be empty'),
            'new_email.required' => __('Email can not be empty'),
            'new_email.email' => __('Email format is incorrect'),
            'new_email.max' => __('Email format is incorrect')
        ];
    }
}
