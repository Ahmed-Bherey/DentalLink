<?php

namespace App\Rules;

use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Validation\Rule;

class ValidImageOrString implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected int $maxSizeKb = 2048;

    public function passes($attribute, $value)
    {
        // إذا كانت صورة مرفوعة
        if ($value instanceof UploadedFile) {
            // تحقق من الحجم والنوع
            return $value->isValid()
                && in_array($value->getClientOriginalExtension(), ['jpeg', 'jpg', 'png'])
                && $value->getSize() / 1024 <= $this->maxSizeKb;
        }

        // إذا كانت مجرد اسم صورة نصي (قديم)
        if (is_string($value)) {
            return true;
        }

        // غير مقبول
        return false;
    }

    public function message()
    {
        return 'يجب أن تكون صورة بصيغة (jpeg, jpg, png) ولا تتجاوز 2 ميجا، أو اسم صورة نصي.';
    }
}
