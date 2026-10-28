<?php

namespace App\Exceptions\Financial;

use App\Support\Money;
use Exception;

/**
 * A payment was refused on financial grounds — not on authorization grounds.
 *
 * The distinction matters. Stage 4 established that an AuthorizationException
 * means "you may not do this to this record" and renders as 403/404. This means
 * "you are allowed to do this, but the numbers do not permit it", which is a
 * 422: the request was well-formed and permitted, and the state rejected it.
 *
 * A dedicated type also stops these from being swallowed by the generic
 * `catch (Exception)` blocks that surround the controllers, which would flatten
 * a precise financial message into a generic failure.
 */
class PaymentException extends Exception
{
    public static function overpayment($attempted, $available): self
    {
        return new self(
            'المبلغ المطلوب ('.Money::format($attempted).') يتجاوز المتبقي ('.Money::format($available).').'
        );
    }

    public static function nothingOutstanding(): self
    {
        return new self('لا يوجد مبلغ متبقٍ على هذا الطبيب لهذا المورد.');
    }

    public static function notPositive(): self
    {
        return new self('يجب أن يكون المبلغ أكبر من صفر.');
    }

    public static function notAmendable(string $status): self
    {
        return new self("لا يمكن تعديل مدفوعة حالتها [{$status}].");
    }

    public static function notDecidable(string $status): self
    {
        return new self("لا يوجد طلب معلّق على هذه المدفوعة (الحالة الحالية: [{$status}]).");
    }
}
