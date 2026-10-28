<?php

namespace App\Events\Order;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewOrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    public $supplierId;

    public function __construct($order, $supplierId)
    {
        $this->order = $order;
        $this->supplierId = $supplierId;
    }

    // 🔹 القناة الخاصة بالمورد
    public function broadcastOn()
    {
        return new PrivateChannel('supplier.'.$this->supplierId);
    }

    // 🔹 الحمولة التي تُرسل للفرونت
    public function broadcastWith()
    {
        return [
            'order_id' => $this->order->id,
            'doctor_name' => $this->order->doctor?->name,
            'message' => "تم إنشاء طلب جديد من الطبيب {$this->order->doctor?->name}",
        ];
    }

    // 🔹 اسم الحدث في الواجهة الأمامية (اختياري)
    public function broadcastAs()
    {
        return 'NewOrderCreated';
    }
}
