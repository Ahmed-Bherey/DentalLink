<?php

namespace App\Services\Notifaction;

use Google_Client;
use Illuminate\Support\Facades\Http;

class FirebaseService
{
    protected $client;
    protected $messagingUrl = 'https://fcm.googleapis.com/v1/projects/YOUR_FIREBASE_PROJECT/messages:send';

    public function __construct()
    {
        $this->client = new Google_Client();
        $this->client->setAuthConfig(base_path('public/firebase/denthub-e6b7a-firebase-adminsdk-fbsvc-c84a0b9631.json'));
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
