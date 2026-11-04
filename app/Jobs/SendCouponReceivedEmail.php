<?php

namespace App\Jobs;

use App\Models\MailLog;
use App\Models\UserCoupon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendCouponReceivedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $couponId;
    public $tries = 3;
    public $timeout = 30;
    public $backoff = [60, 300];

    public function __construct(int $couponId)
    {
        $this->couponId = $couponId;
        $this->onQueue('send_email');
    }

    public function handle()
    {
        $coupon = UserCoupon::with(['template', 'user'])->find($this->couponId);
        if (!$coupon || !$coupon->template || !$coupon->user || $coupon->notification_status === 'sent') return;
        if (!config('v2board.coupon_email_notification_enable', 1) || !$coupon->template->email_notify_enabled) {
            $coupon->notification_status = 'disabled';
            $coupon->notification_error = null;
            $coupon->save();
            return;
        }

        $claimed = UserCoupon::where('id', $coupon->id)
            ->whereIn('notification_status', ['pending', 'failed'])
            ->update(['notification_status' => 'sending', 'notification_attempts' => (int)$coupon->notification_attempts + 1, 'notification_error' => null, 'updated_at' => time()]);
        if (!$claimed) return;

        $siteName = config('v2board.app_name', 'V2Board');
        $siteUrl = rtrim((string)config('v2board.app_url'), '/');
        $subject = '您有一张新的优惠券到账 / You’ve received a new coupon';
        $templateName = 'mail.' . config('v2board.email_template', 'default') . '.couponReceived';
        $values = [
            'siteName' => $siteName,
            'couponName' => $coupon->template->name,
            'couponNameEn' => $coupon->template->name_en ?: $coupon->template->name,
            'description' => $coupon->template->description,
            'descriptionEn' => $coupon->template->description_en,
            'discountType' => $coupon->template->discount_type,
            'discountValue' => $coupon->template->discount_value,
            'startsAt' => $coupon->starts_at,
            'expiresAt' => $coupon->expires_at,
            'walletUrl' => $siteUrl . '/#/coupon',
        ];

        try {
            $this->configureMail();
            Mail::send($templateName, $values, function ($message) use ($coupon, $subject) {
                $message->to($coupon->user->email)->subject($subject);
            });
            $coupon->notification_status = 'sent';
            $coupon->notification_sent_at = time();
            $coupon->notification_error = null;
            $coupon->save();
            $this->writeLog($coupon->user->email, $subject, $templateName, null);
        } catch (\Throwable $exception) {
            $coupon->notification_status = 'failed';
            $coupon->notification_error = mb_substr($exception->getMessage(), 0, 2000);
            $coupon->save();
            $this->writeLog($coupon->user->email, $subject, $templateName, $coupon->notification_error);
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $coupon = UserCoupon::find($this->couponId);
        if (!$coupon || $coupon->notification_status === 'sent') return;
        $coupon->notification_status = 'failed';
        $coupon->notification_error = mb_substr($exception->getMessage(), 0, 2000);
        $coupon->save();
    }

    private function configureMail(): void
    {
        if (!config('v2board.email_host')) return;
        Config::set('mail.host', config('v2board.email_host'));
        Config::set('mail.port', config('v2board.email_port'));
        Config::set('mail.encryption', config('v2board.email_encryption'));
        Config::set('mail.username', config('v2board.email_username'));
        Config::set('mail.password', config('v2board.email_password'));
        Config::set('mail.from.address', config('v2board.email_from_address'));
        Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
    }

    private function writeLog(string $email, string $subject, string $templateName, ?string $error): void
    {
        try {
            MailLog::create(['email' => $email, 'subject' => $subject, 'template_name' => $templateName, 'error' => $error]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
