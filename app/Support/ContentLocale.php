<?php

namespace App\Support;

use Illuminate\Http\Request;

class ContentLocale
{
    public static function isEnglish(Request $request): bool
    {
        $locale = $request->header('X-Locale')
            ?: $request->query('lang')
            ?: $request->header('Accept-Language', 'zh-CN');

        return strpos(strtolower((string)$locale), 'en') === 0;
    }

    public static function localize($model, array $fields, Request $request)
    {
        if (self::isEnglish($request)) {
            foreach ($fields as $field) {
                $translated = $model->getAttribute($field . '_en');
                if ($translated !== null && $translated !== '') {
                    $model->setAttribute($field, $translated);
                }
            }
        }

        foreach ($fields as $field) {
            $model->makeHidden($field . '_en');
        }

        return $model;
    }

    public static function value($default, $english, Request $request)
    {
        if (self::isEnglish($request) && $english !== null && $english !== '') {
            return $english;
        }

        return $default;
    }
}
