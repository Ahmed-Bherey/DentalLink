<?php

namespace App\Services\Financial;

use Carbon\Carbon;
use App\Models\Financial\Receipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReceiptService
{
    public function index($user)
    {
        return Receipt::where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc'); // للتأكد لو نفس التاريخ، الأحدث id ييجي الأول
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

            $date = !empty($receiptData['date'])
            ? Carbon::parse($receiptData['date'])->format('Y-m-d')
            : null;

            Receipt::create([
                'user_id' => $user->id,
                'name'    => $receiptData['name'],
                'value'   => $receiptData['price'],
                'img'     => $imagePath,
                'date'    => $date,
            ]);
        }

        // ترتيب حسب التاريخ الأحدث للإيصال نفسه
        return Receipt::where('user_id', $user->id)->orderBy('date', 'desc');
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
            unset($data['img']); // علشان ما يكتبش null مكان الصورة
        }

        if (!empty($data['date'])) {
            $data['date'] = Carbon::parse($data['date'])->format('Y-m-d');
        }

        $receipt->update($data);

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
        $receipts = Receipt::where('user_id', $user->id)
            ->whereDate('date', $date)
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
