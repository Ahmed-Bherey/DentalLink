<?php

namespace App\Exceptions\Financial;

use Exception;

/**
 * An order operation was refused because the order's state does not permit it.
 *
 * Sits beside PaymentException and renders the same way (422): the caller was
 * authorized and the request was well-formed, but the state of the order or its
 * lines does not allow the operation — stock is short, the line was already
 * fulfilled, the goods were never delivered, nothing is left to return.
 *
 * A distinct type matters for the same reason PaymentException needed one: the
 * controllers are wrapped in generic `catch (Exception)` blocks that would
 * otherwise flatten a precise, actionable reason into "something went wrong".
 */
class FulfilmentException extends Exception
{
    public static function nothingToFulfil(): self
    {
        return new self('لا توجد أصناف بانتظار التسليم من جانبك في هذا الطلب.');
    }

    public static function insufficientStock(string $productName, int $available, int $required): self
    {
        return new self(
            "الكمية المتاحة من المنتج \"{$productName}\" غير كافية. "
            ."المتاح: {$available}، المطلوب: {$required}."
        );
    }

    public static function notFulfilled(): self
    {
        return new self('لا يمكن إرجاع صنف لم يتم تسليمه بعد.');
    }

    public static function nothingReturnable(int $available): self
    {
        return new self("الكمية المطلوبة غير صالحة. المتاح للإرجاع: {$available}.");
    }

    public static function noPriceSnapshot(): self
    {
        return new self(
            'لا يمكن إرجاع هذا الصنف لأنه جزء من باقة ولا يوجد له سعر مفرد مسجل. '
            .'برجاء التواصل مع المورد لإرجاع الباقة بالكامل.'
        );
    }

    public static function quantityLockedAfterFulfilment(): self
    {
        return new self('لا يمكن تعديل الكمية بعد تسليم الصنف.');
    }

    public static function orderHasFinancialHistory(): self
    {
        return new self('لا يمكن حذف طلب تم تسليمه أو تسجيل حركات مالية عليه.');
    }
}
