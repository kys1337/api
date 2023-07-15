<?php
$config = json_decode(file_get_contents("../config/config.json"), true);
$clientId = $config["spotify"]["clientid"];
$clientSecret = $config["spotify"]["clientsecret"];

class TokenGetter
{
    private $clientId;
    private $clientSecret;

    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function tokengetir()
    {
        $url = "https://accounts.spotify.com/api/token";
        $data = "grant_type=client_credentials";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded",
            "Authorization: Basic " .
            base64_encode($this->clientId . ":" . $this->clientSecret),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($response, true);
        $accessToken = $response["access_token"];

        return $accessToken;
    }
}
