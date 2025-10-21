<?php

namespace App\Services\Notifaction;

use Google\Client;
use GuzzleHttp\Client as HttpClient;

class FirebaseService
{
    public function send($title, $body, $token)
    {
        // ضبط الاتصال بخدمة Firebase
        $client = new Client();
        $client->setAuthConfig(storage_path('app/firebase/denthub-d6def-firebase-adminsdk-fbsvc-baf382e3a6.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        // جلب access token
        $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];

        // إعداد Guzzle لإرسال الإشعار
        $http = new HttpClient();
        $response = $http->post(
            'https://fcm.googleapis.com/v1/projects/denthub-d6def/messages:send',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],
                    ],
                ],
            ]
        );

        return json_decode($response->getBody(), true);
    }
}
