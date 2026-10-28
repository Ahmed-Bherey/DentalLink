<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset mail for an API-only backend.
 *
 * Laravel's default notification builds a link to a server-rendered `password.reset`
 * route, which does not exist here. This one points at the frontend application
 * instead, which collects the new password and posts it back to
 * POST /api/password/reset.
 *
 * The frontend base URL comes from config('auth.frontend_reset_url'). If it is
 * not configured the mail falls back to stating the token, so the flow degrades
 * to "usable but manual" rather than sending a broken link.
 */
class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable)
    {
        $expiresInMinutes = config('auth.passwords.users.expire', 60);

        $frontendUrl = config('auth.frontend_reset_url');

        $message = (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور')
            ->line('لقد تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك.');

        if (! empty($frontendUrl)) {
            $link = rtrim($frontendUrl, '/')
                .'?token='.urlencode($this->token)
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());

            $message->action('إعادة تعيين كلمة المرور', $link);
        } else {
            // Deployment has not configured APP_FRONTEND_RESET_URL yet.
            $message->line('رمز إعادة التعيين الخاص بك:')
                ->line($this->token);
        }

        return $message
            ->line("صلاحية هذا الرابط تنتهي خلال {$expiresInMinutes} دقيقة.")
            ->line('إذا لم تطلب إعادة تعيين كلمة المرور، فلا حاجة لاتخاذ أي إجراء.');
    }
}
