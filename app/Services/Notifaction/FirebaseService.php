<?php

namespace App\Services\Notifaction;

use Google\Client;
use GuzzleHttp\Client as HttpClient;

class FirebaseService
{
    public function send($title, $body, $token)
    {
        $client = new Client();
        $client->setAuthConfig(base_path('public/firebase/ecmpp-17004-firebase-adminsdk-k21e4-bba085f055.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $http = new HttpClient();
        $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];

        $response = $http->post(
            'https://fcm.googleapis.com/v1/projects/ecmpp-17004/messages:send',
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
