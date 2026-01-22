<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Authentication Code</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background-color: #f8fafc; padding: 20px; border-radius: 8px; text-align: center;">
    <h2 style="color: #0f172a; margin-bottom: 20px;">Your Login Code</h2>

    <p style="margin-bottom: 10px;">Please use the following code to verify your identity:</p>

    <div style="background-color: #ffffff; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; display: inline-block; margin: 20px 0;">
        <span style="font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #2563eb;">{{ $code }}</span>
    </div>

    <p style="font-size: 14px; color: #64748b; margin-top: 20px;">
        This code will expire in <strong>{{ $expiryMinutes }} minutes</strong>.
    </p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">

    <p style="font-size: 12px; color: #94a3b8;">
        If you did not request this code, please secure your account and ignore this email.
    </p>
</div>
</body>
</html>
