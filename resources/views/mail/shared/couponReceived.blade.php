<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f3f5f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#263238">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px;background:#f3f5f8"><tr><td>
    <table width="600" align="center" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;margin:auto;background:#fff;border-radius:12px;overflow:hidden">
        <tr><td style="padding:24px 32px;background:#4566ae;color:#fff;font-size:22px;font-weight:600">{{ $siteName }}</td></tr>
        <tr><td style="padding:32px">
            <div style="font-size:22px;font-weight:600;margin-bottom:8px">您有一张新的优惠券到账</div>
            <div style="font-size:16px;color:#718096;margin-bottom:26px">You’ve received a new coupon</div>
            <div style="border:1px solid #e7ebf0;border-radius:10px;padding:22px;background:#fafbfc">
                <div style="font-size:20px;font-weight:600">{{ $couponName }}</div>
                <div style="font-size:14px;color:#718096;margin-top:4px">{{ $couponNameEn }}</div>
                <div style="margin-top:14px">{{ $description ?: '' }}</div>
                <div style="margin-top:4px;color:#718096">{{ $descriptionEn ?: '' }}</div>
                <div style="font-size:30px;font-weight:700;color:#4566ae;margin:20px 0 8px">
                    {{ $discountType === 'fixed' ? '¥'.number_format($discountValue / 100, 2) : $discountValue.'% OFF' }}
                </div>
                <div style="font-size:14px;line-height:1.8;color:#59636e">
                    生效时间 / Valid from：{{ date('Y-m-d H:i', $startsAt) }}<br>
                    到期时间 / Expires：{{ date('Y-m-d H:i', $expiresAt) }}
                </div>
            </div>
            <div style="text-align:center;margin-top:28px"><a href="{{ $walletUrl }}" style="display:inline-block;padding:12px 28px;border-radius:6px;background:#4566ae;color:#fff;text-decoration:none;font-weight:600">立即使用 / Use now</a></div>
            <div style="font-size:12px;color:#98a1ab;text-align:center;margin-top:24px">优惠券已自动存入您的账户，无需输入优惠码。<br>The coupon is already in your account; no code is required.</div>
        </td></tr>
    </table>
</td></tr></table>
</body>
</html>
