<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LAB by GenZIITian OTP</title>
</head>
<body style="margin:0;padding:24px;background:#0e1117;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:480px;background:#ffffff;border-radius:14px;padding:32px 28px;">
                    <tr>
                        <td style="font-size:24px;font-weight:700;color:#4f8ef7;padding-bottom:20px;">LAB by GenZIITian</td>
                    </tr>
                    <tr>
                        <td style="font-size:22px;font-weight:700;color:#111827;padding-bottom:12px;">Hi {{ $name }},</td>
                    </tr>
                    <tr>
                        <td style="font-size:16px;color:#374151;padding-bottom:20px;">Your verification code is:</td>
                    </tr>
                    <tr>
                        <td style="font-family:Courier New,monospace;font-weight:700;letter-spacing:0.3em;font-size:2.5rem;color:#4f8ef7;padding:16px 0 18px;">{{ $otp }}</td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;color:#6b7280;padding-bottom:20px;">This code expires in 10 minutes.</td>
                    </tr>
                    <tr>
                        <td style="font-size:13px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:16px;">If you didn't request this, ignore this email.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
