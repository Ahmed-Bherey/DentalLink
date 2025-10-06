<?php

namespace App\Exports;

use App\Models\Financial\Payment;
use Maatwebsite\Excel\Concerns\FromCollection;
use App\Http\Resources\Financial\PaymentResource;

class PaymentsExport implements FromCollection
{
    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function collection()
    {
        $query = Payment::orderBy('created_at', 'desc')
            ->where('status', 'confirmed');

        if ($this->user->department->code == 'doctor') {
            $query->where('doctor_id', $this->user->id);
        } elseif ($this->user->department->code == 'supplier') {
            $query->where('supplier_id', $this->user->id);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Paid',
            'Remaining',
            'Date',
        ];
    }

    public function map($payment): array
    {
        $resource = new PaymentResource($payment);

        return [
            $payment->id,
            $resource->name ?? '-',
            $payment->amount,
            $resource->remaining ?? '-',
            $payment->date ?? $payment->created_at->format('Y-m-d'),
        ];
    }
}
