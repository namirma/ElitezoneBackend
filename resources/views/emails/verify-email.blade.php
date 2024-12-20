<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> EliteZone </title>
    <style>
        @font-face {
            font-family: 'Yekan Bakh';
            src: url('fonts/YekanBakh-RegularFaNum.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        body {
            direction: rtl;
            margin: 0;
            padding: 0;
            background-color: #f4f4f9;
            color: rgb(2, 67, 118);
            font-family: 'Yekan Bakh', Tahoma, Arial, sans-serif;
        }

        .container {
            max-width: 600px;
            margin: 50px auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header img {
            max-width: 100%;
            height: auto;
        }

        .content {
            padding: 24px;
            text-align: center;
        }

        .content h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .content p {
            font-size: 16px;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .button-container {
            text-align: center;
            margin: 24px 0;
        }

        .button {
            background-color: rgb(10, 105, 214);
            color: white !important;
            padding: 12px 24px;
            box-sizing: border-box;
            text-decoration: none;
            font-size: 16px;
            border-radius: 5px;
            display: inline-block;
            width: 100%;
        }

        .footer {
            background-color: rgba(10, 105, 214, .2);
            padding: 12px 24px;
            text-align: center;
            font-size: 12px;
            color: white;
        }


        .footer-content {
            align-self: flex-start;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
        }

        .footer-content>img {
            max-width: 100%;
        }

        .footer-content>.text {
            margin: 8px 0 0 0;
            font-size: 16px;
            color: white;
        }

        .footer-content>.description {
            display: flex;
            align-items: center;
            font-size: 14px;
        }

        .footer-content>.description>.wrapper {
            display: flex;
            flex-direction: row;
        }

        .footer-content>.description>.wrapper>.title {
            color: white;
            margin-left: 8px;
        }

        .footer-content>.description>.wrapper>.value {
            color: white;
        }

        .footer-incos {
            margin-top: 12px;
            direction: rtl;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .footer-incos>.title {
            color: white;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .footer-incos>.icons {
            cursor: pointer;
            display: flex;
            flex-direction: row;
            justify-content: center;
            margin-bottom: 12px;
        }

        .footer-incos>.icons>img {
            max-width: 32px;
            margin: 0 8px;
        }

        .final-text {
            direction: rtl;
            margin: 48px 0 0 0;
            display: flex;
            flex-direction: row-reverse;
            justify-content: center;
            text-align: center;
        }

        .final-text p {
            font-family: 'YekanBakhFaNum-Light';
            font-size: 14px;
            color: var(--Divider-Background-Color);
            margin: 0;
        }
    </style>
</head>

<body>
<div class="container">
    <div class="header">
        <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Email.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123326Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=445a478790eb91fcff98ed9b76211e2c8ea7e25cbd729b1093eae4d5a39ee6c2"
             alt="Welcome Image">
    </div>
    <div class="content">
        <h1> به اِلیت زون خوش آمدید</h1>
        <p>
            سلام <br>
            از ثبت نام شما ممنونیم. با کلیک بر روی دکمه زیر ایمیل خود تایید نمایید
        </p>
        <div class="button-container">
            <a href="{{ Request::root() . '/profile/verify-email/' .$link }}" class="button">تایید ایمیل</a>
        </div>
    </div>

    <div class="footer">
        <div className='footer-content'>
            <div className='description'>
                    <span className='wrapper'>
                        <span className='title'>شماره تماس : </span>
                        <span className='value'> 34005000-031</span>
                    </span>
                <span className='divider'> | </span>
                <span className='wrapper'>
                        <span className='title'>ایمیل : </span>
                        <span className='value'> Info@EliteZone.me </span>
                    </span>
            </div>
            <div class="footer-incos">
                <div class="title">همراه ما باشید</div>
                <div class="icons">
                    <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Instagram.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123500Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=236fcbb792edf95689c46f8f139c749a02d4a26a89a0304d17125986303be12f"
                         alt="img"
                         onclick="window.open('https://www.instagram.com/sazesoftwaregroup?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==', '_blank')">

                    <img src="https://s3.ir-thr-at1.arvanstorage.ir/emailimg/Telegram.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=8900bd10-6b0a-44ad-b504-3dc7e151d41b%2F20241220%2Fir-thr-at1%2Fs3%2Faws4_request&X-Amz-Date=20241220T123430Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&versionId=&X-Amz-Signature=d83db63039a020acf15a336ded231ab4417cd90da05883a11bc2ba0c3693ac70"
                         alt="img" onclick="window.open('https://t.me/SAZESoftwareGroup', '_blank')">

                </div>
            </div>
            <div className='final-text'>
                <p> کلیه حقوق برای شرکت نرم افزاری اسپادانا (SAZE90.COM) محفوظ است. </p>
            </div>
        </div>
    </div>
</div>
</body>

</html>
