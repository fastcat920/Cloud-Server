<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketWithdraw  extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'withdraw_amount' => 'required|integer|min:1',
            'withdraw_method' => 'required',
            'withdraw_account' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'withdraw_amount.required' => __('The withdrawal amount cannot be empty'),
            'withdraw_amount.integer' => __('The withdrawal amount parameter is wrong'),
            'withdraw_amount.min' => __('The withdrawal amount parameter is wrong'),
            'withdraw_method.required' => __('The withdrawal method cannot be empty'),
            'withdraw_account.required' => __('The withdrawal account cannot be empty')
        ];
    }
}
