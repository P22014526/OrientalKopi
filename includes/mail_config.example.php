<?php
/*
 * Copy this file to: includes/mail_config.php
 * Then replace the example values with your SMTP details.
 *
 * Do not upload the completed mail_config.php file to GitHub.
 */
return [
    // Gmail example. Other SMTP providers can also be used.
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls', // tls, ssl, or none
    'smtp_username' => 'your-email@gmail.com',
    'smtp_password' => 'your-16-character-app-password',

    'from_email' => 'your-email@gmail.com',
    'from_name' => 'Oriental Kopi Smart Booking',

    // Change this if your localhost folder name or deployed URL is different.
    'app_url' => 'http://localhost/oriental-kopi-booking',
];
