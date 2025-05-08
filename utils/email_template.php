<?php
function reminderTemplate($name, $title, $message) {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Email Verification</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #fffcfd; }
            .mail-container { width: 28rem; background-color: white; padding: 50px 20px; text-align: center; margin: auto; box-shadow: 0 0px 10px rgba(0, 0, 0, 0.02); }
            .logo-img { width: 200px; margin: 20px auto; display: block; }
            .hi-user { font-size: 20px; color: #621d1f; text-align: center; }
            .hi-user-span { font-weight: 700; }
            .code-number { font-size: 28px; font-weight: 600; background-color:#fbf3f4; color: #ac1d21; padding: 10px; border-radius: 3px; border: 1px solid #ac1d21; margin: 20px auto; display: inline-block; }
            .footer { background-color: #621d1f; padding: 50px 20px; text-align: center; color: white; }
        </style>
    </head>
    <body>
        <img src='https://mahjon-db.goldenrootscollectionsltd.com/images/email-image.png' alt='Welcome' class='logo-img'>
        <div class='hi-user'>Congratulations <span class='hi-user-span'>$name</span>,</div>
        <div class='hi-user'>Your Mahjong Clinic App profile has been created</div>
        <div class='mail-container'>
            <img src='https://mahjon-db.goldenrootscollectionsltd.com/images/splash-logo.png' alt='Logo' class='logo-img'/>
            <p>$message</p>
            <div class='code-number'>$title</div>
        </div>
        <div class='footer'>
            <p>This is an automated message, please do not reply directly to this email.</p>
            <p>© 2025 Mahjong Clinic Nigeria. All rights reserved.</p>
            <p>Developer | iphysdynamix</p>
        </div>
    </body>
    </html>";
}

?>
