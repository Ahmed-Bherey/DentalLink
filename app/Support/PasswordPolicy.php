<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The single definition of what counts as an acceptable password.
 *
 * Deliberately moderate rather than extreme: length plus a letter and a number.
 * Composition rules beyond that push users toward predictable substitutions and
 * buy little, so they are not imposed.
 *
 * Length is configurable via AUTH_PASSWORD_MIN_LENGTH so it can be raised
 * without a code change.
 */
final class PasswordPolicy
{
    public static function minLength(): int
    {
        return (int) config('auth.password_policy.min_length', 8);
    }

    /**
     * The strength rule, without `required` or `confirmed` — callers add those
     * so each flow can decide (registration has a legacy contract to honour;
     * change/reset require confirmation).
     */
    public static function rules(): Password
    {
        return Password::min(self::minLength())
            ->letters()
            ->numbers();
    }

    /**
     * Human-readable summary, safe to show a user.
     *
     * Describes the requirements only. It never reflects any part of the value
     * the user submitted.
     */
    public static function description(): string
    {
        return sprintf(
            'كلمة المرور يجب أن تتكون من %d أحرف على الأقل وتحتوي على حرف ورقم.',
            self::minLength()
        );
    }
}
