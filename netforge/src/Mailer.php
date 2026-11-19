<?php
declare(strict_types=1);

namespace NetForge;

/**
 * Sends the few emails NetForge needs (password reset links).
 * config 'mail.transport':
 *   'log'  (default) writes each message to storage/mail/ instead of sending it, for XAMPP and demos;
 *   'mail' uses PHP's mail(), so set SMTP in php.ini (or sendmail) on the real server.
 */
final class Mailer
{
    /** Returns where the message went: 'sent', or the path of the saved file. */
    public static function send(string $to, string $subject, string $body): string
    {
        $from = (string) Config::get('mail.from', 'NetForge <no-reply@netforge.local>');
        if (Config::get('mail.transport', 'log') === 'mail') {
            $headers = "From: $from\r\nContent-Type: text/plain; charset=UTF-8";
            if (!mail($to, $subject, $body, $headers)) {
                throw new GameError('The email couldn’t be sent. Ask your lecturer to check the mail settings.');
            }
            return 'sent';
        }
        $dir = NF_ROOT . '/storage/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = sprintf('%s/%s-%s.txt', $dir, date('Ymd-His'), preg_replace('/[^a-z0-9]+/i', '_', $to));
        file_put_contents($file, "From: $from\nTo: $to\nSubject: $subject\nDate: " . date('r') . "\n\n$body\n");
        return $file;
    }
}
