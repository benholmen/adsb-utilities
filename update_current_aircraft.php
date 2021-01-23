<?php
    define('SOURCE_FILE', '/run/dump1090-fa/aircraft.json');
    // define('SOURCE_FILE', '/home/pi/adsb-utilities/sample-aircraft.json');
    define('SLEEP_TIME', 10);
    define('SEEN_COUNT_DELAY', 10*60); // How long between plane spottings before we count it as a new spotting
    define('JSON_DATABASE_URL', 'https://raw.githubusercontent.com/Mictronics/readsb-protobuf/dev/webapp/src/db/aircrafts.json');
    define('DB_FILE', dirname(__FILE__) . '/aircraft.db');
    define('TMP_DB_FILE', '/dev/shm/aircraft-tmp.db'); // much faster to initialize database in memory
    define('LAT_HOME',  44.8267759);
    define('LON_HOME', -91.5326822);

    /** USAGE
     *  Before running the first time, run aircraft_stats.php --init to create a new SQLite DB
     *  and load aircraft data from a popular repo.
     *
     *  After it is intialized you can run this script as a daemon and it will continuously
     *  monitor SOURCE_FILE which should be the dump1090 aircraft.json file
     */

    // Set up and populate a table of aircraft tail numbers and types
    if (in_array('--init', $argv)) {
        echo "Initializing in memory at " . TMP_DB_FILE . "...";
        $db = new SQLite3(TMP_DB_FILE);

        createAircraftSeenTable();
        createAircraftMetaTable();

        echo "done.\n";

        loadJsonToAircraftMetaTable();

        echo "Moving " . TMP_DB_FILE . " to " . DB_FILE . "...";

        rename(TMP_DB_FILE, DB_FILE);

        echo "done.\n";
        exit;
    }
    elseif (in_array('--refresh', $argv)) {
        echo "Refreshing in memory at " . TMP_DB_FILE . ".\n";
        $db = new SQLite3(TMP_DB_FILE);

        loadJsonToAircraftMetaTable();

        echo "Moving " . TMP_DB_FILE . " to " . DB_FILE . "...";

        rename(TMP_DB_FILE, DB_FILE);

        echo "done.\n";
        exit;
    }

    if (!file_exists(DB_FILE)) {
        echo "Missing database " . DB_FILE . ", did you intialize with --init?\n";
    }

    $db = new SQLite3(DB_FILE);

    $keepOnLooping = true;
    while ($keepOnLooping) {
        $currentAircraftReadable = [];
        $allAdsbAircraft = json_decode(file_get_contents(SOURCE_FILE))->aircraft;
        foreach ($allAdsbAircraft as $adsbAircraft) {
            // Always use upper case for ICAO hex ID
            $adsbAircraft->hex = strtoupper($adsbAircraft->hex);

            updateAircraft($adsbAircraft);
        }

        displaySummaryStats();

        sleep(SLEEP_TIME);
    }

    function getAircraftMeta($hex)
    {
        global $db;

        // Aircraft are referenced with a hex value. It's much faster to use INTs in Sqlite3
        $hex_to_int = hexdec($hex);
        return $db->querySingle("SELECT * FROM aircraft_meta WHERE hex_to_int = {$hex_to_int}", true);
    }

    function getAircraft($hex)
    {
        global $db;

        return $db->querySingle("SELECT * FROM aircraft_seen WHERE hex = '{$hex}'", true);
    }


    function updateAircraft($aircraft): void
    {
        global $db;

        foreach(['alt_geom', 'lat', 'lon', 'gs'] as $requiredDataField)
        if (!isset($aircraft->$requiredDataField)) {
            return;
        }

        $aircraft->hex = strtoupper($aircraft->hex);

        $distance = distanceBetween($aircraft->lat, $aircraft->lon, LAT_HOME, LON_HOME);

        if ($dbAircraft = getAircraft($aircraft->hex)) {
            $seenCount = $dbAircraft['seen_count'];
            $timeSinceLastSeen = time() - strtotime($dbAircraft['last_seen'] . ' UTC');
            if ($timeSinceLastSeen > SEEN_COUNT_DELAY) {
                $seenCount++;
            }

            $db->query("
                UPDATE aircraft_seen
                SET
                    min_altitude = MIN(min_altitude, {$aircraft->alt_geom}),
                    max_altitude = MAX(max_altitude, {$aircraft->alt_geom}),
                    min_speed = MIN(min_speed, {$aircraft->gs}),
                    max_speed = MAX(max_speed, {$aircraft->gs}),
                    min_distance = MIN(min_distance, {$distance}),
                    max_distance = MAX(max_distance, {$distance}),
                    seen_count = {$seenCount},
                    last_seen = datetime('now')
                WHERE
                    id = '{$dbAircraft['id']}'
            ");
        } else {
            $db->query("
                INSERT INTO aircraft_seen
                    (hex, min_altitude, max_altitude, min_speed, max_speed, min_distance, max_distance, seen_count, first_seen, last_seen)
                VALUES
                    ('{$aircraft->hex}', {$aircraft->alt_geom}, {$aircraft->alt_geom}, {$aircraft->gs}, {$aircraft->gs}, {$distance}, {$distance}, 1, datetime('now'), datetime('now'))");
        }
    }

    function displaySummaryStats(): void
    {
        global $db;

        $max_age_minutes = 10;

        $result = $db->query("
            SELECT *
            FROM
                aircraft_seen
                LEFT JOIN aircraft_meta USING (hex)
            WHERE
                last_seen > datetime('now', '-{$max_age_minutes} minutes')
            ORDER BY seen_count DESC, last_seen DESC, min_distance ASC
            LIMIT 50");

        echo "\nHEX\tTAIL\tTYPE\tSEEN\tMIN DISTANCE\tLINK\n";
        echo "---\t----\t----\t----\t------------\t----\n";

        $count = 0;

        while ($aircraft = $result->fetchArray(SQLITE3_ASSOC)) {
            $min_distance = number_format($aircraft['min_distance'], 1);
            $link = 'https://globe.adsbexchange.com/?icao=' . strtolower($aircraft['hex']);
            echo "{$aircraft['hex']}\t{$aircraft['tail']}\t{$aircraft['type']}\t{$aircraft['seen_count']}\t{$min_distance}\t\t{$link}\n";

            $count++;
        }
        echo "--- {$count} in last {$max_age_minutes} min ------\n";
    }

    function distanceBetween($lat1, $lon1, $lat2 = LAT_HOME, $lon2 = LON_HOME, $unit = 'nm'): float
    {
        if ($unit == 'nm') {
            $earth_radius = 3961 / 1.151; // NAUTICAL MILES
        } else {
            $earth_radius = 3961; // MILES
        }

        $dLat = deg2rad(LAT_HOME - $lat1);
        $dLon = deg2rad(LON_HOME - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad(LAT_HOME)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * asin(sqrt($a));
        $d = $earth_radius * $c;

        return floatval($d);
    }

    function createAircraftSeenTable(): void
    {
        global $db;

        $db->exec('
            CREATE TABLE IF NOT EXISTS "aircraft_seen" (
                "id"	        INTEGER UNIQUE,
                "hex"	        TEXT UNIQUE,
                "min_altitude"	INTEGER,
                "max_altitude"	INTEGER,
                "min_speed"	    INTEGER,
                "max_speed"	    INTEGER,
                "min_distance"	INTEGER,
                "max_distance"	INTEGER,
                "seen_count"	INTEGER,
                "first_seen"	TEXT,
                "last_seen"	    TEXT,
                PRIMARY KEY("id" AUTOINCREMENT)
        )');
    }

    function createAircraftMetaTable(): void
    {
        global $db;

        $db->exec("DROP TABLE IF EXISTS aircraft_meta");
        $db->exec('
            CREATE TABLE "aircraft_meta" (
                "id"	        INTEGER UNIQUE,
                "hex"	        TEXT UNIQUE,
                "hex_to_int"    INTEGER UNIQUE,
                "tail"	        TEXT,
                "type"	        TEXT,
                "updated"	    TEXT,
                PRIMARY KEY("id" AUTOINCREMENT)
                UNIQUE("hex")
                UNIQUE("hex_to_int")
            )');
    }

    function loadJsonToAircraftMetaTable(): void
    {
        global $db;

        createAircraftMetaTable();

        echo "Loading " . JSON_DATABASE_URL . " to internal table. This will take a while.\n";

        $tailDbCache = json_decode(file_get_contents(JSON_DATABASE_URL), true);

        echo "Found " . number_format(count($tailDbCache)) . " aircraft to import. Starting...\n";

        $starttime = time();

        $inserted = 0;
        foreach ($tailDbCache as $hex => $meta) {
            // Aircraft are referenced with a hex value. It's much faster to use INTs in Sqlite3
            $hex = strtoupper($hex);
            $hex_to_int = hexdec($hex);
            $db->exec("INSERT INTO aircraft_meta (hex, hex_to_int, tail, type, updated) VALUES ('{$hex}', {$hex_to_int}, '{$meta[0]}', '{$meta[1]}', DATETIME('now'))");
            $inserted++;

            if ($inserted % 1000 === 0) {
                $elapsed = time() - $starttime;
                $remaining = round(count($tailDbCache) * $elapsed / $inserted) - $elapsed;
                echo "> " . number_format($inserted) . " completed in {$elapsed} sec (approx {$remaining} sec remaining)  \r";
            }
        }
        echo "\n";
    }
