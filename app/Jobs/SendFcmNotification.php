<?php

namespace App\Jobs;

use App\Services\Notifaction\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $token;

    public $title;

    public $body;

    public $data;

    public function __construct($token, $title, $body, $data = [])
    {
        $this->token = $token;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    public function handle()
    {
        $firebase = new FirebaseService;
        $firebase->sendNotification($this->token, $this->title, $this->body, $this->data);
    }
}
