<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EliteZone</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f9;
            direction: rtl;
            color: #024376;
        }

        table {
            border-spacing: 0;
            border-collapse: collapse;
            width: 100%;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header img {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .content {
            padding: 20px;
            text-align: center;
        }

        .content h1 {
            font-size: 20px;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .content p {
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .button-container {
            margin: 20px 0;
        }

        .button {
            display: inline-block;
            background-color: #0a69d6;
            color: #ffffff !important;
            padding: 12px 20px;
            font-size: 14px;
            text-decoration: none;
            border-radius: 5px;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
        }

        .footer {
            background-color: #0a69d633;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #ffffff;
        }

        .footer p {
            margin: 5px 0;
            line-height: 1.5;
        }

        .social-icons img {
            width: 32px;
            margin: 0 5px;
            vertical-align: middle;
        }

    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Email.jpg" alt="Welcome Image">
        </div>


        <div class="content">
            <h1>به اِلیت زون خوش آمدید</h1>
            <p>برای تایید آدرس ایمیل خود، لطفا روی دکمه زیر کلیک کنید.</p>
            <a href="{{ $link }}" class="button">تایید ایمیل</a>
        </div>

        
        <div class="footer">
            <p>تماس: 34005000-031</p>
            <p>ایمیل: Info@EliteZone.me</p>
            <div class="social-icons">
                <a href="https://www.instagram.com/sazesoftwaregroup">
                    <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Instagram.png" alt="Instagram">
                </a>
                <a href="https://t.me/SAZESoftwareGroup">
                    <img src="https://emailverification.s3.ir-thr-at1.arvanstorage.ir/Telegram.png" alt="Telegram">
                </a>
            </div>
        </div>
    </div>
</body>
</html>
