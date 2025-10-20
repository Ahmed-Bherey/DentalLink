<?php

namespace App\Services\Notifaction;

use Google_Client;
use Illuminate\Support\Facades\Http;

class FirebaseService
{
    protected $client;
    protected $messagingUrl = 'https://fcm.googleapis.com/v1/projects/denthub-d6def/messages:send';

    public function __construct()
    {
        $this->client = new Google_Client();
        $this->client->setAuthConfig(base_path('public/firebase/denthub-d6def-firebase-adminsdk-fbsvc-f4af9caf93.json'));
        $this->client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    }

    protected function getAccessToken()
    {
        if ($this->client->isAccessTokenExpired()) {
            $this->client->fetchAccessTokenWithAssertion();
        }
        return $this->client->getAccessToken()['access_token'];
    }

    public function sendNotification($fcmToken, $title, $body)
    {
        $accessToken = $this->getAccessToken();

        $message = [
            "message" => [
                "token" => $fcmToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body
                ]
            ]
        ];

        return Http::withToken($accessToken)
            ->post($this->messagingUrl, $message)
            ->json();
    }
}
