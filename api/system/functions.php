<?php

/* 
 *15.07.2023
 *
 * Author:  kys1337
 * Discord: kys1000
 * Github:  github.com/kys1337
 * 
 * Bu dosya, sistemde kullanılan fonksiyonları içerir.
 * MIT Lisansı ile lisanslanmıştır.
 */

 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
 error_reporting(E_ALL);
$action = isset($_GET["action"]) ? $_GET["action"] : "";
switch ($action) {
    case "upload":
        handleUploadRequest();
        break;
    case "getTrackInfo":
        handleTrackInfoRequest();
        break;
    case "getArtistInfo":
        handleArtistInfoRequest();
        break;
    case "register":
        handleRegisterRequest();
        break;
    case "login":
        handleLoginRequest();
        break;
    case "downloader":
        handleDownloaderRequest();
        break;
    case "getMonthlyData":
        $artistid = isset($_GET["artistid"]) ? $_GET["artistid"] : "";
        getMonthlyData($artistid);
        break;
    default:
        $data = [
            "success" => false,
            "message" => "parameter_missing",
        ];
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
}

/**
 * Dosya yükleme isteğini işler.
 */
function handleUploadRequest()
{
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $targetDir = "uploads/";
        $allowedExtensions = ["jpg", "jpeg", "png"];

        $file = $_FILES["file"];
        $fileName = generateUniqueFileName($file["name"]);
        $targetFile = $targetDir . $fileName;
        $imageFileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($imageFileType, $allowedExtensions)) {
            $data = [
                "success" => false,
                "message" => "unknown_extension",
            ];
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit();
        }

        if (move_uploaded_file($file["tmp_name"], $targetFile)) {
            $newWidth = filter_input(INPUT_POST, "width", FILTER_VALIDATE_INT);
            $newHeight = filter_input(
                INPUT_POST,
                "height",
                FILTER_VALIDATE_INT
            );

            if (
                $newWidth === false ||
                $newHeight === false ||
                $newWidth > 5000 ||
                $newHeight > 5000
            ) {
                $data = [
                    "success" => false,
                    "message" => "invalid_dimensions",
                ];
                echo json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit();
            }

            resizeImage($targetFile, $newWidth, $newHeight);
            $data = [
                "success" => true,
                "message" => "Dosya başarıyla yeniden boyutlandırıldı.",
                "data" => [
                    "url" => "https://localhost/" . $targetFile,
                    "width" => $newWidth,
                    "height" => $newHeight,
                ],
            ];
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $data = [
                "success" => false,
                "message" => "file_upload_error",
            ];
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}

/**
 * Spotify şarkı bilgisi isteğini işler.
 */
function handleTrackInfoRequest()
{
    $trackid = $_GET["trackid"];
    require_once "../server/class/TokenGetter.php";

    $tokenGetter = new TokenGetter($clientId, $clientSecret);
    $token = $tokenGetter->tokengetir();

    $url = "https://api.spotify.com/v1/tracks/" . $trackid;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
    $response = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($response, true);

    $data = [];

    if ($json !== null) {
        // Şarkının adı
        $trackName = $json["name"];
        $data["track_name"] = $trackName;

        // Şarkının ISRC'si
        $isrc = $json["external_ids"]["isrc"];
        $data["isrc"] = $isrc;

        // UPC'yi çekmek için yeni bir API çağrısı
        $albumUrl = $json["album"]["external_urls"]["spotify"];
        $albumId = getAlbumIdFromUrl($albumUrl);
        if ($albumId) {
            $upcUrl = "https://api.spotify.com/v1/albums/" . $albumId;
            $ch = curl_init($upcUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $token,
            ]);
            $upcResponse = curl_exec($ch);
            curl_close($ch);
            $upcJson = json_decode($upcResponse, true);

            if ($upcJson !== null) {
                // Şarkının UPC'si
                $upc = $upcJson["external_ids"]["upc"];
                $data["upc"] = $upc;
            } else {
                // UPC çözümlenemediyse veya hata oluştuysa
                $data["upc"] = null;
            }
        } else {
            // Albüm ID'si alınamazsa
            $data["upc"] = null;
        }

        // Tüm sanatçı isimleri
        $artistNames = [];
        foreach ($json["artists"] as $artist) {
            $artistNames[] = $artist["name"];
        }
        $data["artists"] = $artistNames;

        // Cover art linki
        $coverArtUrl = $json["album"]["images"][0]["url"];
        $data["cover_art_url"] = $coverArtUrl;

        // Albüm linki
        $data["album_url"] = $albumUrl;

        // Success değeri
        $data["success"] = true;
    } else {
        // JSON çözümlenemediyse veya hata oluştuysa
        $data["success"] = false;
    }

    // JSON olarak yazdırma
    $jsonData = json_encode($data);
    echo $jsonData;
}

/**
 * Spotify sanatçı bilgisi isteğini işler.
 */
function handleArtistInfoRequest()
{
    require_once "../server/class/Spotify.php";
    $artistid = $_GET["artistid"];

    #if artistid null
    if ($artistid == null) {
        $data = [
            "success" => false,
            "message" => "parameter_missing",
        ];
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit();
    }



    $spotify = new Spotify();
    $cookie =
        "sp_m=tr-tr; sp_t=719a2549-b2f7-496f-a0df-836bb6d64cbb; sp_new=1; sp_landing=https%3A%2F%2Fwww.spotify.com%2Ftr-tr%2F; sp_pfhp=2c2ccb58-8a92-4713-a1c0-8b43b3090b49; _gcl_au=1.1.1186093401.1689272460; sss=1; _scid=332fc159-9d8f-4d1b-8ef6-11ba38a39b21; sp_adid=8b81dac0-e141-4bc9-acf7-9035302c12bc; _gid=GA1.2.231986145.1689272462; _cs_c=0; _sctr=1%7C1689195600000; _cs_id=866ad0e9-8e37-a00d-ad08-8c4360e4e954.1689272464.2.1689274572.1689274572.1.1723436464935; _ga_S35RN5WNT2=GS1.1.1689274572.2.1.1689274588.0.0.0; sp_gaid=0088fc4c7da360c3f6bedbb185bea5a778fe0c7caaff70c24698c6; OptanonConsent=isIABGlobal=false&datestamp=Thu+Jul+13+2023+23%3A46%3A31+GMT%2B0300+(GMT%2B03%3A00)&version=6.26.0&hosts=&landingPath=NotLandingPage&groups=s00%3A1%2Cf00%3A1%2Cm00%3A1%2Ct00%3A1%2Ci00%3A1%2Cf11%3A1&AwaitingReconsent=false; _ga_ZWG1NSHWD8=GS1.1.1689280358.3.1.1689281191.0.0.0; _scid_r=332fc159-9d8f-4d1b-8ef6-11ba38a39b21; _ga=GA1.2.1372753267.1689272462; _gat=1; sp_dc=AQDN6PTb79u0y0i2UpnwtiYCe3f18lev76FSU4n7SyDNlI80ol-F5kQ2X2h770wd7zwDbMWvY7uXcE2JGm2S1N2KvsixlNO4pOJw57yN96U310jYxmg6GehukPODGI_l8dhNk_rJkEwt45rRIHmfgxIGLn-VRk3G; sp_key=5bb37a9b-1ab4-4ffd-a8db-7b0d73b1b6ee";
    $token = $spotify->getToken($cookie);

    if ($token[0] != true) {
        die(
            json_encode([
                "success" => false,
                "message" => "cookie_error",
            ])
        );
    } else {
        $json = $token[1];
        $array = json_decode($json, true);

        $accessToken = $array["accessToken"];
        $clientId = $array["clientId"];
    }

    $ch = curl_init();
    curl_setopt(
        $ch,
        CURLOPT_URL,
        "https://api-partner.spotify.com/pathfinder/v1/query?operationName=queryArtistOverview&variables=%7B%22uri%22%3A%22spotify%3Aartist%3A" .
            $artistid .
            "%22%2C%22locale%22%3A%22%22%2C%22includePrerelease%22%3Afalse%7D&extensions=%7B%22persistedQuery%22%3A%7B%22version%22%3A1%2C%22sha256Hash%22%3A%2235648a112beb1794e39ab931365f6ae4a8d45e65396d641eeda94e4003d41497%22%7D%7D"
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_ENCODING, "gzip");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "authorization: Bearer " . $accessToken . "",
        "origin: https://open.spotify.com",
        "user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36",
    ]);

    $res = curl_exec($ch);

    curl_close($ch);

    $jsonres = json_decode($res, true);

    $biography =
        $jsonres["data"]["artistUnion"]["profile"]["biography"]["text"];
    $id = $jsonres["data"]["artistUnion"]["id"];
    $popularReleasesAlbumsTotalCount =
        $jsonres["data"]["artistUnion"]["discography"]["popularReleasesAlbums"][
            "totalCount"
        ];

    $topTracksItems = [];

    foreach (
        $jsonres["data"]["artistUnion"]["discography"]["topTracks"]["items"]
        as $item
    ) {
        $track = [
            "id" => $item["track"]["id"],
            "name" => $item["track"]["name"],
            "playcount" => $item["track"]["playcount"],
            "duration" => [
                "totalMilliseconds" =>
                    $item["track"]["duration"]["totalMilliseconds"],
            ],
        ];
        $topTracksItems[] = $track;
    }

    $statsFollowers = $jsonres["data"]["artistUnion"]["stats"]["followers"];
    $statsMonthlyListeners =
        $jsonres["data"]["artistUnion"]["stats"]["monthlyListeners"];

    $statsTopCitiesItems = [];

    foreach (
        $jsonres["data"]["artistUnion"]["stats"]["topCities"]["items"]
        as $item
    ) {
        $cityItem = [
            "numberOfListeners" => $item["numberOfListeners"],
            "city" => $item["city"],
            "country" => $item["country"],
            "region" => $item["region"],
        ];
        $statsTopCitiesItems[] = $cityItem;
    }

    $data = [
        "data" => [
            "artistUnion" => [
                "profile" => [
                    "id" => $id,
                    "biography" => [
                        "text" => $biography,
                    ],
                ],
                "discography" => [
                    "popularReleasesAlbums" => [
                        "totalCount" => $popularReleasesAlbumsTotalCount,
                    ],
                    "topTracks" => [
                        "items" => $topTracksItems,
                    ],
                ],
                "stats" => [
                    "followers" => $statsFollowers,
                    "monthlyListeners" => $statsMonthlyListeners,
                    "topCities" => [
                        "items" => $statsTopCitiesItems,
                    ],
                ],
            ],
        ],
    ];

    if ($id == null) {
        $data["success"] = false;
    } else {
        $data["success"] = true;
    }

    echo json_encode($data, JSON_PRETTY_PRINT);
    /*echo $res;*/
}

/**
 * Kullanıcı kayıt işlemlerini gerçekleştirir.
 */

function handleRegisterRequest()
{
    require_once "../server/class/MySQLConnection.php";
    $email = $_GET["email"];
    $password = $_GET["password"];
    $artistid = $_GET["artistid"];

    $config = json_decode(file_get_contents("../server/config/config.json"), true);
    $clientId = $config["spotify"]["clientid"];
    $clientSecret = $config["spotify"]["clientsecret"];

    $host = "localhost";
    $username = "root";
    $dbPassword = "";
    $database = "spotify";

    $mysqlConnection = new MySQLConnection(
        $host,
        $username,
        $dbPassword,
        $database
    );
    $mysqlConnection->connect();
    $conn = $mysqlConnection->getConnection();

    if ($conn->connect_error) {
        die("MySQL bağlantısı başarısız: " . $conn->connect_error);
    } /* else {
        echo "MySQL bağlantısı başarılı!";
    }*/

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $message = "Geçersiz e-posta adresi";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    if (
        !preg_match("#[0-9]+#", $password) ||
        !preg_match("#[a-zA-Z]+#", $password) ||
        !preg_match("#[\W]+#", $password) ||
        strlen($password) < 8
    ) {
        $success = false;
        $message =
            "Şifre en az 8 karakter uzunluğunda olmalı ve en az bir sayı, harf ve özel karakter içermelidir.";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    $sql = "SELECT * FROM users WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result === false) {
        // Hata durumunu kontrol et
        $success = false;
        $message = "Sorgu hatası: " . $conn->error;

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($result->num_rows > 0) {
        $success = false;
        $message = "Bu e-posta adresi zaten kullanılıyor.";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    $sql = "SELECT * FROM users WHERE artistid = '$artistid'";
    $result = $conn->query($sql);

    if ($result === false) {
        // Hata durumunu kontrol et
        $success = false;
        $message = "Sorgu hatası: " . $conn->error;

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($result->num_rows > 0) {
        $success = false;
        $message = "Bu sanatçı kimliği sistemde zaten kayıtlı.";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    $tokenGetter = new TokenGetter($clientId, $clientSecret);
    $token = $tokenGetter->tokengetir();

    $url = "https://api.spotify.com/v1/artists/" . $artistid;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
    $response = curl_exec($ch);
    curl_close($ch);
    $response = json_decode($response, true);
    if (isset($response["error"])) {
        $success = false;
        $message = "Geçersiz sanatçı kimliği";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }
    $artistname = $response["name"];

    $sql = "INSERT INTO users (email, password, artistid, artistname) VALUES ('$email', '$password', '$artistid', '$artistname')";

    if ($conn->query($sql) === true) {
        $success = true;
        $message = "Kayıt başarılı";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } else {
        $success = false;
        $message = "Kayıt başarısız";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
    $mysqlConnection->closeConnection();
}

/**
 * Kullanıcı giriş işlemlerini gerçekleştirir.
 */

function handleDownloaderRequest()
{
    $ch = curl_init();
    $songid = $_GET["songid"];
    curl_setopt(
        $ch,
        CURLOPT_URL,
        "https://api.spotifydown.com/download/" . $songid
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_ENCODING, "gzip");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "authority: api.spotifydown.com",
        "origin: https://spotifydown.com",
        "referer: https://spotifydown.com/",
        "user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36",
    ]);

    $response = curl_exec($ch);

    curl_close($ch);
    echo $response;
}

function handleLoginRequest()
{
    require_once "../server/class/MySQLConnection.php";
    $email = $_GET["email"];
    $password = $_GET["password"];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $message = "Geçersiz e-posta adresi";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    if (strlen($password) < 8) {
        $success = false;
        $message = "Şifre en az 8 karakter uzunluğunda olmalıdır.";

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    $host = "localhost";
    $username = "root";
    $dbPassword = ""; // Changed variable name to avoid conflict with function argument
    $database = "spotify";

    $mysqlConnection = new MySQLConnection(
        $host,
        $username,
        $dbPassword,
        $database
    );
    $mysqlConnection->connect();
    $conn = $mysqlConnection->getConnection();

    if ($conn->connect_error) {
        die("MySQL bağlantısı başarısız: " . $conn->connect_error);
    }

    $sql = "SELECT artistname, artistid FROM users WHERE email = '$email' AND password = '$password'";
    $result = $conn->query($sql);

    if ($result === false) {
        // Hata durumunu kontrol et
        $success = false;
        $message = "Sorgu hatası: " . $conn->error;

        $response = [
            "success" => $success,
            "message" => $message,
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc(); // Fetch the row once

        $artistname = $row["artistname"]; // Access the values from the fetched row
        $artistSid = $row["artistid"];

        session_start();
        $_SESSION["artistname"] = $artistname;
        $_SESSION["email"] = $email;
        $_SESSION["loggedin"] = true;
        $_SESSION["artistid"] = $artistSid;
        $success = true;
        $message = "Giriş başarılı";

        $response = [
            "success" => $success,
            "message" => $message,
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE);

        return;
    } else {
        $success = false;
        $message = "Geçersiz e-posta adresi veya şifre";

        $response = [
            "success" => $success,
            "message" => $message,
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        return;
    }
}

/**
 * Benzersiz bir dosya adı oluşturur.
 *
 * @param string $originalName Orijinal dosya adı
 * @return string Benzersiz dosya adı
 */
function generateUniqueFileName($originalName)
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $fileName = bin2hex(random_bytes(16)); // Benzersiz bir dosya adı oluşturuluyor
    return $fileName . "." . $extension;
}

/**
 * Resmi yeniden boyutlandırır.
 *
 * @param string $filePath Resmin dosya yolu
 * @param int $width Yeni genişlik
 * @param int $height Yeni yükseklik
 */
function resizeImage($filePath, $width, $height)
{
    list($originalWidth, $originalHeight) = getimagesize($filePath);
    $ratio = $originalWidth / $originalHeight;

    if ($width / $height > $ratio) {
        $newWidth = $height * $ratio;
        $newHeight = $height;
    } else {
        $newHeight = $width / $ratio;
        $newWidth = $width;
    }

    $destination = imagecreatetruecolor($newWidth, $newHeight);
    $source = null;

    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    switch ($extension) {
        case "jpg":
        case "jpeg":
            $source = imagecreatefromjpeg($filePath);
            break;
        case "png":
            $source = imagecreatefrompng($filePath);
            break;
        default:
            $data = [
                "success" => false,
                "message" => "extension_not_supported",
            ];
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
    }

    imagealphablending($destination, false);
    imagesavealpha($destination, true);
    imagecopyresampled(
        $destination,
        $source,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $originalWidth,
        $originalHeight
    );

    switch ($extension) {
        case "jpg":
        case "jpeg":
            imagejpeg($destination, $filePath);
            break;
        case "png":
            imagepng($destination, $filePath);
            break;
    }

    imagedestroy($destination);
    imagedestroy($source);
}

/**
 * Spotify albüm URL'sinden albüm ID'sini çıkarır.
 *
 * @param string $albumUrl Spotify albüm URL'si
 * @return string|false Albüm ID'si veya false (eğer çıkarılamazsa)
 */
function getAlbumIdFromUrl($albumUrl)
{
    $pattern = "/\/album\/(\w+)/";
    preg_match($pattern, $albumUrl, $matches);
    if (isset($matches[1])) {
        return $matches[1];
    }
    return false;
}

/**
 * Aylık veri takibi için hesaplamalar yapar.
 * Bu hesaplamalar sonucunda 30 günlük bir veri takibi elde edilir.
 * Bu veri takibi sonucu JSON formatında döndürülür.
 * JSON formatı ekrana yazdırılır.
 */

 function getMonthlyData($artistid)
 {
     // MySQL bağlantısı
     require_once "../server/class/MySQLConnection.php";
 
     $config = json_decode(file_get_contents("../server/config/config.json"), true);
     $host = $config["mysql"]["servername"];
     $username = $config["mysql"]["username"];
     $dbPassword = $config["mysql"]["password"];
     $database = $config["mysql"]["database"];
 
     $mysqlConnection = new MySQLConnection(
         $host,
         $username,
         $dbPassword,
         $database
     );
     $mysqlConnection->connect();
     $conn = $mysqlConnection->getConnection();
 
     if ($conn->connect_error) {
         die("MySQL bağlantısı başarısız: " . $conn->connect_error);
     }
 
     // Bugünün tarihini al
     $today = date("d.m.Y");
 
     // 30 gün öncesinin tarihini hesapla
     $thirtyDaysAgo = date("d.m.Y", strtotime("-30 days"));
 
     // Tarih formatını değiştir
     $today = date("Y-m-d", strtotime($today));
     $thirtyDaysAgo = date("Y-m-d", strtotime($thirtyDaysAgo));
 
     // SQL sorgusunu oluştur
     $sql = "SELECT * FROM `sarkitakip` WHERE `artistname` = '$artistid' AND STR_TO_DATE(`date_created`, '%d.%m.%Y') >= '$thirtyDaysAgo' AND STR_TO_DATE(`date_created`, '%d.%m.%Y') <= '$today'";
     $result = $conn->query($sql);
 
     if (!$result) {
         $success = false;
         $message = "Sorgu hatası: " . $conn->error;
     } else {
         $success = true;
         $message = "Veriler başarıyla alındı.";
 
         $data = [];
         while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        // $data dizisini kontrol et
        var_dump($data);
        
        // Aylik dinlenme verisini hesapla
        $monthlyListeners = 0;
        foreach ($data as $row) {
            if (isset($row["monthlyListeners"])) {
                $monthlyListeners += $row["monthlyListeners"];
            }
        }
        echo "monthlyListeners: " . $monthlyListeners . "<br>";
        
        // Playcount verilerini hesapla
        $playcountDifference = 0;
        if (!empty($data)) {
            $firstDayPlaycount = $data[0]["playcount"];
            $lastDayPlaycount = end($data)["playcount"];
            $playcountDifference = $lastDayPlaycount - $firstDayPlaycount;
        }
        echo "playcountDifference: " . $playcountDifference . "<br>";
        
        // MonthlyListeners değişim oranını hesapla
        $monthlyListenersChange = 0;
        if (isset($data[0]["monthlyListeners"]) && isset($data[count($data) - 1]["monthlyListeners"])) {
            $firstDayMonthlyListeners = $data[0]["monthlyListeners"];
            $lastDayMonthlyListeners = $data[count($data) - 1]["monthlyListeners"];
            if ($firstDayMonthlyListeners != 0) {
                $monthlyListenersChange = (($lastDayMonthlyListeners - $firstDayMonthlyListeners) / $firstDayMonthlyListeners) * 100;
            }
        }
        echo "monthlyListenersChange: " . $monthlyListenersChange . "<br>";
        
        // Aylik takipçi değişim oranını hesapla
        $followersChange = 0;
        if (isset($data[0]["followers"]) && isset($data[count($data) - 1]["followers"])) {
            $firstDayFollowers = $data[0]["followers"];
            $lastDayFollowers = $data[count($data) - 1]["followers"];
            if ($firstDayFollowers != 0) {
                $followersChange = (($lastDayFollowers - $firstDayFollowers) / $firstDayFollowers) * 100;
            }
        }
        echo "followersChange: " . $followersChange . "<br>";
        
        // Sonuçları diziye ekle
        $response = [
            'success' => $success,
            'message' => $message,
            'data' => [
                'monthlyListeners' => $monthlyListeners,
                'playcountDifference' => $playcountDifference,
                'monthlyListenersChange' => $monthlyListenersChange,
                'followersChange' => $followersChange
            ]
        ];
        
        // JSON yanıtını döndür
        echo json_encode($response);
        #sql den dönen yantını yazdır

        }
 
     // Bağlantıyı kapat
     $mysqlConnection->closeConnection();
 }
 
 
 
 
 



/**
 * API Kullanım Kılavuzu
 * 
 * Bu kılavuzda, API'nin kullanımını anlatan örnekler ve açıklamalar bulunmaktadır.
 */

/**
 * 1. Dosya Yükleme İsteği
 * 
 * API'ye bir dosya yüklemek için `handleUploadRequest()` işlevini kullanabilirsiniz. Bu işlem için aşağıdaki adımları takip edin:
 * 
 * URL: `https://localhost/system/functions.php?action=upload`
 * Method: POST
 * 
 * İstek Gövdesi:
 * - Dosyayı POST isteğiyle gönderin.
 * - Dosya, `file` adında bir form alanına eklenmelidir.
 * 
 * Yanıt:
 * - Başarılı bir yükleme durumunda, yanıt olarak JSON formatında aşağıdaki bilgileri içeren bir yanıt alırsınız:
 *   - success: Dosya yükleme işleminin başarılı olup olmadığını belirten bir boolean değer.
 *   - message: Dosya yükleme işlemiyle ilgili bir mesaj.
 *   - data: Yükleme işlemi başarılıysa, yüklenen dosyanın URL'si, genişliği ve yüksekliği gibi verileri içeren bir veri nesnesi.
 * - Başarısız bir yükleme durumunda, yanıt olarak JSON formatında aşağıdaki bilgileri içeren bir yanıt alırsınız:
 *   - success: Dosya yükleme işleminin başarılı olup olmadığını belirten bir boolean değer.
 *   - message: Dosya yükleme işlemiyle ilgili bir hata mesajı.
 * 
 * Örnek Kullanım:
 * 
 * CURL Örneği (Bash):
 * ```
 * curl -X POST -F 'file=@/path/to/file.jpg' 'https://localhost/system/functions.php?action=upload'
 * ```
 */


/**
 * 2. Şarkı Bilgisi Alma İsteği
 * 
 * Şarkı bilgisi almak için `handleTrackInfoRequest()` işlevini kullanabilirsiniz. Bu işlem için aşağıdaki adımları takip edin:
 * 
 * URL: `https://localhost/system/functions.php?action=getTrackInfo&trackid={trackid}`
 * Method: GET
 * 
 * Parametreler:
 * - trackid: Şarkının benzersiz kimliği
 * 
 * Yanıt:
 * - Başarılı bir işlem durumunda, yanıt olarak JSON formatında şarkıyla ilgili bilgileri içeren bir yanıt alırsınız.
 * - Başarısız bir işlem durumunda, yanıt olarak JSON formatında bir hata mesajı alırsınız.
 * 
 * Örnek Kullanım:
 * 
 * URL Örneği:
 * ```
 * https://localhost/system/functions.php?action=getTrackInfo&trackid=ABC123
 * ```
 */


/**
 * 3. Sanatçı Bilgisi Alma İsteği
 * 
 * Sanatçı bilgisi almak için `handleArtistInfoRequest()` işlevini kullanabilirsiniz. Bu işlem için aşağıdaki adımları takip edin:
 * 
 * URL: `https://localhost/system/functions.php?action=getArtistInfo&artistid={artistid}`
 * Method: GET
 * 
 * Parametreler:
 * - artistid: Sanatçının benzersiz kimliği
 * 
 * Yanıt:
 * - Başarılı bir işlem durumunda, yanıt olarak JSON formatında sanatçıyla ilgili bilgileri içeren bir yanıt alırsınız.
 * - Başarısız bir işlem durumunda, yanıt olarak JSON formatında bir hata mesajı alırsınız.
 * 
 * Örnek Kullanım:
 * 
 * URL Örneği:
 * ```
 * https://localhost/system/functions.php?action=getArtistInfo&artistid=123456
 * ```
 */


/**
 * 4. Kullanıcı Kayıt İsteği
 * 
 * Kullanıcı kaydı yapmak için `handleRegisterRequest()` işlevini kullanabilirsiniz. Bu işlem için aşağıdaki adımları takip edin:
 * 
 * URL: `https://localhost/system/functions.php?action=register&email={email}&password={password}&artistid={artistid}`
 * Method: GET
 * 
 * Parametreler:
 * - email: Kullanıcının e-posta adresi
 * - password: Kullanıcının şifresi
 * - artistid: Kullanıcının sanatçı kimliği
 * 
 * Yanıt:
 * - Başarılı bir kayıt durumunda, yanıt olarak JSON formatında kayıt işlemiyle ilgili bir başarı mesajı alırsınız.
 * - Başarısız bir kayıt durumunda, yanıt olarak JSON formatında bir hata mesajı alırsınız.
 * 
 * Örnek Kullanım:
 * 
 * URL Örneği:
 * ```
 * https://localhost/system/functions.php?action=register&email=example@example.com&password=123456&artistid=7890
 * ```
 */


/**
 * 5. Kullanıcı Giriş İsteği
 * 
 * Kullanıcı girişi yapmak için `handleLoginRequest()` işlevini kullanabilirsiniz. Bu işlem için aşağıdaki adımları takip edin:
 * 
 * URL: `https://localhost/system/functions.php?action=login&email={email}&password={password}`
 * Method: GET
 * 
 * Parametreler:
 * - email: Kullanıcının e-posta adresi
 * - password: Kullanıcının şifresi
 * 
 * Yanıt:
 * - Başarılı bir giriş durumunda, yanıt olarak JSON formatında giriş işlemiyle ilgili bir başarı mesajı alırsınız.
 * - Başarısız bir giriş durumunda, yanıt olarak JSON formatında bir hata mesajı alırsınız.
 * 
 * Örnek Kullanım:
 * 
 * URL Örneği:
 * ```
 * https://localhost/system/functions.php?action=login&email=example@example.com&password=123456
 * ```
 
 */


?>
