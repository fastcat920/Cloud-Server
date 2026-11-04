<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthRegister extends FormRequest
{
    protected function prepareForValidation()
    {
        $data = [];
        $email = $this->input('email');
        $emailCode = $this->input('email_code');

        if (is_string($email)) {
            $data['email'] = strtolower(trim($email));
        }

        // 兼容旧版客户端将验证码作为 JSON 数字提交的情况。
        if (is_int($emailCode) || is_float($emailCode)) {
            $data['email_code'] = str_pad((string)$emailCode, 6, '0', STR_PAD_LEFT);
        } elseif (is_string($emailCode)) {
            $data['email_code'] = trim($emailCode);
        }

        if ($data) {
            $this->merge($data);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'email' => 'required|email:strict',
            'password' => 'required|min:8'
        ];

        if ((int)config('v2board.email_verify', 0)) {
            $rules['email_code'] = 'required|string|digits:6';
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
            'email_code.required' => __('Email verification code cannot be empty'),
            'email_code.string' => __('Incorrect email verification code'),
            'email_code.digits' => __('Incorrect email verification code')
        ];
    }
}
