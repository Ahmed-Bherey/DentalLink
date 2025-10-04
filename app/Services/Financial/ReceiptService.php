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
        // لاحظ: مفيش get() => علشان نقدر نعمل paginate فى الكنترولر
    }

    // انشاء ايصال
    public function store($user, $data)
    {
        foreach ($data['receipts'] as $receiptData) {
            $imagePath = null;

            if (isset($receiptData['img']) && $receiptData['img'] instanceof \Illuminate\Http\UploadedFile) {
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

        // رجّع Query عشان نعمل paginate بعدين
        return Receipt::where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');
    }

    public function show($id, $user)
    {
        return Receipt::where('user_id', $user->id)->findOrFail($id);
    }

    public function update($id, $user, $data)
    {
        $receipt = Receipt::where('user_id', $user->id)->findOrFail($id);

        $updateData = [
            'name'  => $data['name'],
            'value' => $data['price'],
            'date'  => $data['date'],
        ];

        if (isset($data['img']) && $data['img'] instanceof UploadedFile) {
            if ($receipt->img && Storage::disk('public')->exists($receipt->img)) {
                Storage::disk('public')->delete($receipt->img);
            }

            $updateData['img'] = $data['img']->store('receipts', 'public');
        }

        $receipt->update($updateData);

        $totalPrice = Receipt::where('user_id', $user->id)
            ->whereYear('date', $receipt->date->year)
            ->whereMonth('date', $receipt->date->month)
            ->sum('value');
        $receipt->total_price = (float) $totalPrice;

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
