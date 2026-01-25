<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication Code</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #F5F7FA;
            margin: 0;
            padding: 0;
            width: 100% !important;
            -webkit-font-smoothing: antialiased;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .email-card {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border-top: 4px solid #009393; /* Tether Green Brand Accent */
        }

        .header-text {
            text-align: center;
            padding-bottom: 20px;
        }

        .brand-text {
            font-size: 28px;
            font-weight: 800;
            color: #009393; /* Tether Green */
            text-transform: uppercase;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .content {
            padding: 40px;
            text-align: center;
            color: #334155;
        }

        /* The Code Box */
        .otp-block {
            background-color: #F0FDFA; /* Very light teal background */
            border: 1px dashed #009393;
            border-radius: 8px;
            padding: 15px 30px;
            margin: 25px 0;
            display: inline-block;
            min-width: 180px;
        }

        .otp-code {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 6px;
            color: #009393;
            font-family: 'Courier New', Courier, monospace; /* Monospace for numbers */
            margin: 0;
        }

        /* Mobile Tweaks */
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; padding: 20px 10px; }
            .content { padding: 25px 20px; }
            .otp-code { font-size: 26px; letter-spacing: 4px; }
        }
    </style>
</head>
<body>

<div class="email-container">

    <div class="header-text">
        <span class="brand-text">{{ $appName }}</span>
    </div>

    <div class="email-card">
        <div class="content">
            <h2 style="margin: 0 0 15px 0; color: #1E293B; font-size: 24px;">Your Login Code</h2>

            <p style="margin: 0 0 10px 0; font-size: 16px; line-height: 1.6; color: #475569;">
                Please use the following code to verify your identity:
            </p>

            <div class="otp-block">
                <span class="otp-code">{{ $code }}</span>
            </div>

            <p style="font-size: 14px; color: #64748b; margin: 0;">
                This code will expire in <strong>{{ $expiryMinutes }} minutes</strong>.
            </p>

            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">

            <div style="text-align: left; background-color: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0;">
                <p style="font-size: 13px; color: #334155; margin: 0; font-weight: bold;">
                    Security Notice
                </p>
                <p style="font-size: 12px; color: #64748b; margin: 5px 0 0 0; line-height: 1.5;">
                    If you did not request this code, please secure your account immediately and ignore this email.
                </p>
            </div>
        </div>
    </div>

    <div style="text-align: center; padding-top: 20px; font-size: 12px; color: #94a3b8;">
        <p style="margin: 5px 0;">&copy; {{ $appName }}</p>
    </div>

</div>

</body>
</html>
