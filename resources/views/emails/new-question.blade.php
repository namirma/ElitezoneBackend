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
            font-family: 'Yekan Bakh', Arial, sans-serif;
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
<table>
    <tr>
        <td>
            <div class="container">
                <!-- Header -->
                <div class="header">
                    <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Email.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123326Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=445a478790eb91fcff98ed9b76211e2c8ea7e25cbd729b1093eae4d5a39ee6c2" alt="Welcome Image">
                </div>

                <!-- Content -->
                <div class="content">
                    <h1>پیام جدیدی دریافت شد.</h1>
                    <h3>
                        مشخصات ارسال کننده به شرح زیر است:
                    </h3>
                    <p>
                        نام و نام خانوادگی: {{ $data['name'] }}
                    </p>
                    <p>
                        پست التکترونیکی: {{ $data['email'] }}
                    </p>
                    <p>
                        تلفن همراه: {{ $data['mobile'] }}
                    </p>
                    <p>
                        موضع: {{ $data['subject'] }}
                    </p>
                    <p>
                        پیام: {{ $data['message'] }}
                    </p>
                </div>

                <!-- Footer -->
                <div class="footer">
                    <p>شماره تماس: 34005000-031</p>
                    <p>Info@EliteZone.me</p>
                    <div class="social-icons">
                        <a href="https://www.instagram.com/sazesoftwaregroup" target="_blank">
                            <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Instagram.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123500Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=236fcbb792edf95689c46f8f139c749a02d4a26a89a0304d17125986303be12f" alt="Instagram">
                        </a>

                        <a href="https://t.me/SAZESoftwareGroup" target="_blank">
                            <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Telegram.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123430Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=d83db63039a020acf15a336ded231ab4417cd90da05883a11bc2ba0c3693ac70" alt="Telegram">
                        </a>
                    </div>
                    <p>کلیه حقوق برای شرکت نرم افزاری اسپادانا محفوظ است.</p>
                </div>
            </div>
        </td>
    </tr>
</table>
</body>

</html>
