<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Facade\Ignition\Exceptions\ViewException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        // Only the public homepage renders the selected user theme. Admin and
        // other Blade failures must keep their real exception classification.
        if ($exception instanceof ViewException && $request->path() === '/') {
            abort(500, "主题渲染失败。如更新主题，参数可能发生变化请重新配置主题后再试。");
        }
        return parent::render($request, $exception);
    }

    protected function invalidJson($request, ValidationException $exception)
    {
        $errors = $exception->errors();
        $message = collect($errors)->flatten()->first();

        return response()->json([
            'message' => $message ?: __('Invalid parameter'),
            'errors' => $errors,
        ], $exception->status);
    }


    protected function convertExceptionToArray(Throwable $e)
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(function ($trace) {
                return Arr::except($trace, ['args']);
            })->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : __("Uh-oh, we've had some problems, we're working on it."),
        ];
    }
}
