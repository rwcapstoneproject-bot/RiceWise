<?php
require_once 'config.php';
header('Content-Type: application/json');


if (!isset($_SESSION['rw_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}


$uid = (int)$_SESSION['rw_user_id'];
$input = json_decode(file_get_contents('php://input'), true);


$name     = trim($input['name'] ?? '');
$email    = trim($input['email'] ?? '');
$location = trim($input['location'] ?? '');
$hectares = $input['hectares'] ?? null;


if ($name === '' || $email === '' || $location === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if ($hectares !== null && (!is_numeric($hectares) || $hectares < 0)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid farm size.']);
    exit;
}


// location comes in as "Town, Province, Country" — split for geocoding accuracy
$parts = array_map('trim', explode(',', $location));
$town     = $parts[0] ?? '';
$province = $parts[1] ?? '';
$country  = $parts[2] ?? '';


/**
 * Geocodes a town/province/country via Open-Meteo's free geocoding API.
 * Returns null (not an error) if no match is found or the request fails —
 * callers should treat null lat/lng as "fall back to default coordinates",
 * never as a reason to block the profile save.
 *
 * NOTE: mirror this function's behavior with whatever signup.php already
 * uses for its own geocoding, so a location string geocodes the same way
 * whether it's set at signup or updated later in Profile. If signup.php's
 * version differs (different endpoint, different query format), copy that
 * one here instead of this one, or better, factor it out into a shared
 * helper file both scripts include.
 */
function geocodeLocation(string $town, string $province, string $country): ?array
{
    if ($town === '') return null;


    // Build several query variants — Open-Meteo's geocoder is picky about
    // exact phrasing/capitalization, especially for PH city names that have
    // "City of X" / "X City" / lowercase particles like "del", "de".
    $townVariants = array_unique([
        $town,
        preg_replace('/\bdel\b/i', 'Del', $town),   // "del Monte" -> "Del Monte"
        str_ireplace('City of ', '', $town),
        'City of ' . preg_replace('/ City$/i', '', $town),
        preg_replace('/ City$/i', '', $town),
    ]);


    $attempts = [];
    foreach ($townVariants as $tv) {
        $attempts[] = trim("$tv, $province, $country", ', ');
        $attempts[] = trim("$tv, $country", ', ');
        $attempts[] = $tv;
    }
    $attempts = array_unique($attempts);


    $fallbackMatch = null;


    foreach ($attempts as $query) {
        if ($query === '') continue;
        $url = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($query) . "&count=5&language=en&format=json";
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) continue;


        $data = json_decode($raw, true);
        if (empty($data['results'])) continue;


        foreach ($data['results'] as $r) {
            if (empty($r['latitude'])) continue;
            $coords = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude']];
            if (($r['country_code'] ?? '') === 'PH') {
                return $coords;
            }
            if ($fallbackMatch === null) {
                $fallbackMatch = $coords;
            }
        }
    }


    return $fallbackMatch;
}


$coords = geocodeLocation($town, $province, $country);
$farmLat = $coords['lat'] ?? null;
$farmLng = $coords['lng'] ?? null;


// Check email isn't taken by another user
$chk = $conn->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
$chk->bind_param('si', $email, $uid);
$chk->execute();
if ($chk->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'That email is already in use.']);
    exit;
}
$chk->close();


$stmt = $conn->prepare(
    "UPDATE users SET name=?, email=?, location=?, hectares=?, farm_lat=?, farm_lng=? WHERE id=?"
);
$stmt->bind_param('sssdddi', $name, $email, $location, $hectares, $farmLat, $farmLng, $uid);
$ok = $stmt->execute();
$stmt->close();


echo json_encode(['success' => (bool)$ok]);



