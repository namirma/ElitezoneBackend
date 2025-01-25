<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EliteZone</title>
    <style type="text/css">
        a { color: inherit; text-decoration: none; }
        a:visited { color: inherit; }
        @font-face {
            font-family: 'Yekan Bakh';
            src: url('https://yekanbakh-font.s3.ir-thr-at1.arvanstorage.ir/YekanBakh%2Fwoff%2FYekanBakh-Regular.woff?versionId=') format('woff'),
                 url('https://yekanbakh-font.s3.ir-thr-at1.arvanstorage.ir/YekanBakh%2Fwoff2%2FYekanBakh-Regular.woff2?versionId=') format('woff2'),
                 url('https://yekanbakh-font.s3.ir-thr-at1.arvanstorage.ir/YekanBakh%2Fttf%2FYekanBakh-Regular.ttf?versionId=') format('truetype');
            font-weight: 300;
            font-style: normal;
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #ffffff; font-family: 'Yekan Bakh', Tahoma, Arial, sans-serif; direction: rtl; color: #024376;">
    <table style="border-spacing: 0; border-collapse: collapse; width: 100%; background-color: #ffffff; margin: 0; padding: 0;">
        <tr>
            <td align="center">
                <table style="width: 100%; max-width: 600px; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; padding: 0;">
                            <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Email.jpg?versionId=" alt="Welcome Image" style="display: block; width: 100%; height: auto;">
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 24px; text-align: right;">
                            <h1 style="font-size: 16px; margin-bottom: 15px; line-height: 2; text-align: center;">عرض سلام و ادب،<br>با تشکر از پشتیبانی شما در الیت زون</h1>
                            <p style="font-size: 14px; margin-bottom: 20px; line-height: 2;">پیام درج شده کاربر در وبسایت با مشخصات زیر دریافت گردید. لطفا با کاربر تماس بگیرید و موضوع مربوطه را پیگیری نمائید.</p>
                            <p style="font-size: 12px; margin-bottom: 20px; line-height: 2; text-align: center;">با تشکر،<br>مدیریت الیت زون</p>
                            <table style="width: 100%; margin: 0 auto; text-align: center;">
                                <tr>
                                    <td style="padding: 8px 0; border-bottom: 1px solid #ddd; word-wrap: break-word; overflow-wrap: break-word; text-align: right;">
                                        <strong style="font-size: 14px; display: inline-block; margin-bottom: 6px;">نام و نام خانوادگی:</strong>
                                        <span style="font-size: 14px; line-height: 2;">{{ $data['name'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; border-bottom: 1px solid #ddd; word-wrap: break-word; overflow-wrap: break-word; text-align: right;">
                                        <strong style="font-size: 14px; display: inline-block; margin-bottom: 6px;">پست الکترونیکی:</strong>
                                        <span style="font-size: 14px; line-height: 2;">{{ $data['email'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; border-bottom: 1px solid #ddd; word-wrap: break-word; overflow-wrap: break-word; text-align: right;">
                                        <strong style="font-size: 14px; display: inline-block; margin-bottom: 6px;">تلفن همراه:</strong>
                                        <span style="font-size: 14px; line-height: 2;">{{ $data['mobile'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; border-bottom: 1px solid #ddd; word-wrap: break-word; overflow-wrap: break-word; text-align: right;">
                                        <strong style="font-size: 14px; display: inline-block; margin-bottom: 6px;">موضوع:</strong>
                                        <span style="font-size: 14px; line-height: 2;">{{ $data['subject'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; word-wrap: break-word; overflow-wrap: break-word; text-align: right;">
                                        <strong style="font-size: 14px; display: inline-block; margin-bottom: 6px;">پیغام:</strong>
                                        <span style="font-size: 14px; line-height: 2; text-align: justify;">{{ $data['message'] }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #99BFED; padding: 15px; text-align: center; font-size: 12px; color: #ffffff; border-radius: 0 0 8px 8px;">
                            <p style="margin: 5px 0;">شماره تماس: 34005000-031</p>
                            <p style="margin: 5px 0;">ایمیل: Info@EliteZone.me</p>
                            <div style="margin: 10px 0;">
                                <a href="https://www.instagram.com/sazesoftwaregroup" target="_blank" style="text-decoration: none;">
                                    <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Instagram.png?versionId=" alt="Instagram" style="width: 32px; margin: 0 5px;">
                                </a>
                                <a href="https://t.me/SAZESoftwareGroup" target="_blank" style="text-decoration: none;">
                                    <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Telegram.png?versionId=" alt="Telegram" style="width: 32px; margin: 0 5px;">
                                </a>
                            </div>
                            <p style="margin: 5px 0; line-height: 2;">کلیه حقوق برای شرکت نرم افزاری اسپادانا <a href="https://saze90.com" style="color: #ffffff; text-decoration: none;">(SAZE90.COM)</a> محفوظ است.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
