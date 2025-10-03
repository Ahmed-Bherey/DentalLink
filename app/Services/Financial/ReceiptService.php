<?php

namespace App\Services\Financial;

use App\Models\Financial\Receipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReceiptService
{
    public function index($user)
    {
        return Receipt::where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');
        // لاحظ: مفيش get()
    }

    // انشاء ايصال
    public function store($user, $data)
    {
        foreach ($data['receipts'] as $receiptData) {
            $imagePath = null;

            if (isset($receiptData['img']) && $receiptData['img'] instanceof UploadedFile) {
                $file = $receiptData['img'];
                $imagePath = $file->store('receipts', 'public');
            }

            Receipt::create([
                'user_id' => $user->id,
                'name'    => $receiptData['name'],
                'value'   => $receiptData['price'],
                'img'     => $imagePath,
                'date'    => $receiptData['date'],
            ]);
        }

        // ترتيب حسب التاريخ الأحدث للإيصال نفسه
        return Receipt::where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    public function show($id, $user)
    {
        return Receipt::where('user_id', $user->id)->findOrFail($id);
    }

    public function update($id, $user, $data)
    {
        $receipt = Receipt::where('user_id', $user->id)->findOrFail($id);

        if (isset($data['img']) && $data['img'] instanceof UploadedFile) {
            // لو فيه صورة قديمة امسحها
            if ($receipt->img && Storage::disk('public')->exists($receipt->img)) {
                Storage::disk('public')->delete($receipt->img);
            }

            $data['img'] = $data['img']->store('receipts', 'public');
        } else {
            unset($data['img']);
        }

        $receipt->update([
            'name'    => $data['name'],
            'value'   => $data['price'],
            'date'    => $data['date'],
        ]);

        if (isset($data['img'])) {
            $updateData['img'] = $data['img'];
        }

        return $receipt;
    }

    public function delete($id, $user)
    {
        $receipt = Receipt::where('user_id', $user->id)->findOrFail($id);

        if ($receipt->img && Storage::disk('public')->exists($receipt->img)) {
            Storage::disk('public')->delete($receipt->img);
        }

        $receipt->delete();

        return true;
    }

    public function deleteByDate($user, $date)
    {
        // نفصل السنة والشهر من القيمة المرسلة "2025-09"
        [$year, $month] = explode('-', $date);
        $receipts = Receipt::where('user_id', $user->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        if ($receipts->isEmpty()) {
            return false;
        }

        foreach ($receipts as $receipt) {
            if ($receipt->img && Storage::disk('public')->exists($receipt->img)) {
                Storage::disk('public')->delete($receipt->img);
            }
            $receipt->delete();
        }

        return true;
    }
}
