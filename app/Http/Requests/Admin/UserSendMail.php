<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserSendMail extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => 'required',
            'content' => 'required',
            'send_at' => 'nullable|integer|min:' . (time() + 60) . '|max:' . (time() + 31536000),
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => '主题不能为空',
            'content.required' => '发送内容不能为空',
            'send_at.integer' => '定时发送时间格式错误',
            'send_at.min' => '定时发送时间必须晚于当前时间至少一分钟',
            'send_at.max' => '定时发送时间不能超过一年'
        ];
    }
}
