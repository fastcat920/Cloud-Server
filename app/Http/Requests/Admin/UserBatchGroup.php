<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserBatchGroup extends FormRequest
{
    public function rules()
    {
        return [
            'group_id' => 'required|integer|exists:v2_server_group,id',
            'source_query' => 'nullable|string|max:20000',
        ];
    }

    public function messages()
    {
        return [
            'group_id.required' => '请选择目标权限组',
            'group_id.exists' => '目标权限组不存在',
            'source_query.max' => '筛选条件过长，请减少筛选项后重试',
        ];
    }
}
