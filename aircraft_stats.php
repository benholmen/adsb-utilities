<?php
    // define('SOURCE_FILE', '/run/dump1090-fa/aircraft.json');
    define('SLEEP_TIME', 3);
    define('SOURCE_FILE', 'home/pi/adsb-utilities/sample-aircraft.json');
    define('JSON_DATABASE_URL', 'https://raw.githubusercontent.com/Mictronics/readsb-protobuf/dev/webapp/src/db/aircrafts.json');
    // define('DB_FILE', '/home/pi/aircraft.db');
    define('DB_FILE', '/home/pi/adsb-utilities/aircraft.db');
    define('TMP_DB_FILE', '/dev/shm/'); // much faster to initialize database in memory

    /** USAGE
     *  Before running the first time, run aircraft_stats.php --init to create a new SQLite DB
     *  and load aircraft data from a popular repo.
     *
     *  After it is intialized you can run this script as a daemon and it will continuously
     *  monitor SOURCE_FILE which should be the dump1090 aircraft.json file
     */

    // Set up and populate a table of aircraft tail numbers and types
    if (in_array('--init', $argv)) {
        echo "Initializing in memory at {TMP_DB_FILE}...";
        $db = new SQLite3(TMP_DB_FILE);

        createAircraftSeenTable();
        createAircraftTable();
        loadJsonToAircraftTable();

        echo "done.\n";
        echo "Moving {TMP_DB_FILE} to {DB_FILE}\n";

        rename(TMP_DB_FILE, DB_FILE);
    }
    elseif (in_array('--refresh')) {
        echo "Refreshing in memory at {TMP_DB_FILE}...";
        $db = new SQLite3(TMP_DB_FILE);

        loadJsonToAircraftTable();

        echo "done.\n";
        echo "Moving {TMP_DB_FILE} to {DB_FILE}\n";

        rename(TMP_DB_FILE, DB_FILE);
    }

    if (!file_exists(DB_FILE)) {
        echo "Missing database {DB_FILE}, did you intialize with --init?\n";
    }

    $db = new SQLite3(DB_FILE);

    $current_aircraft = json_decode(file_get_contents(SOURCE_FILE))->aircraft;
    foreach ($current_aircraft as $aircraft) {
        print_r($aircraft);
        echo 'TAIL: ' . print_r(tailFromHex($aircraft->hex), true) . "\n";
        echo 'TYPE: ' . print_r(typeFromHex($aircraft->hex), true) . "\n";
        continue;
        updateAircraft($aircraft);
    }


    function getAircraft($hex)
    {
        global $db;

        $stmt = $db->prepare("SELECT * FROM aircraft WHERE hex = :hex");
        $result = $stmt->bindValue('hex', $hex);

        return $result->fetchArray();
    }


    function updateAircraft($aircraft)
    {
        global $db;

        if ($dbAircraft = getAircraft($aircraft->hex)) {
            $seenCount = $dbAircraft['seen_count'];
            if (strtotime($dbAircraft['last_seen']) - time() > SEEN_COUNT_DELAY) {
                $seenCount++;
            }

            $db->query("
                UPDATE aircraft_seen
                SET
                    min_altitude = MIN(min_altitude, {$aircraft->alt_geom}),
                    max_altitude = MAX(max_altitude, {$aircraft->alt_geom}),
                    min_speed = MIN(min_speed, {$aircraft->gs}),
                    max_speed = MAX(max_speed, {$aircraft->gs}),
                    seen_count = {$seenCount},
                    last_seen = datetime('now')
                WHERE
                    hex = '{$aircraft->hex}'
            ");
        } else {
            $db->query("
                INSERT INTO aircraft_seen
                SET
                    hex = '{$aircraft->hex}',
                    tail_num = '{$tailNum}',
                    min_altitude = {$aircraft->alt_geom},
                    max_altitude = {$aircraft->alt_geom},
                    min_speed = {$aircraft->gs},
                    max_speed = {$aircraft->gs},
                    seen_count = 1,
                    first_seen = datetime('now'),
                    last_seen = datetime('now')
            ");
        }
    }

    function tailFromHex($hex)
    {
        global $db;

        return $db->querySingle("SELECT tail FROM aircraft WHERE hex = '{$hex}'");
    }

    function typeFromHex($hex)
    {
        global $db;

        return $db->querySingle("SELECT type FROM aircraft WHERE hex = '{$hex}'");
    }

    function createAircraftSeenTable(): void
    {
        global $db;

        $db->exec('
            CREATE TABLE IF NOT EXISTS "aircraft_seen" (
                "id"	INTEGER UNIQUE,
                "hex"	TEXT UNIQUE,
                "min_altitude"	INTEGER,
                "max_altitude"	INTEGER,
                "min_speed"	INTEGER,
                "max_speed"	INTEGER,
                "seen_count"	INTEGER,
                "first_seen"	TEXT,
                "last_seen"	TEXT,
                PRIMARY KEY("id" AUTOINCREMENT)
        )');
    }

    function createAircraftTable(): void
    {
        global $db;

        $db->exec("DROP TABLE aircraft");
        $db->exec('
            CREATE TABLE "aircraft" (
                "id"	    INTEGER UNIQUE,
                "hex"	    TEXT UNIQUE,
                "tail"	    TEXT,
                "type"	    TEXT,
                "updated"	TEXT,
                PRIMARY KEY("id" AUTOINCREMENT)
            )');
    }

    function loadJsonToAircraftTable(): void
    {
        global $db;

        createAircraftTable();

        echo "Loading " . JSON_DATABASE_URL . " to internal table...";

        $tailDbCache = json_decode(file_get_contents(JSON_DATABASE_URL), true);

        $inserted = 0;
        foreach ($tailDbCache as $hex => $meta) {
            $db->exec("INSERT INTO aircraft (hex, tail, type, updated) VALUES ('{$hex}', '{$meta[0]}', '{$meta[1]}', DATETIME('now'))");
            $inserted++;
        }

        echo "done. Found " . number_format($inserted) . " aircraft.\n";
    }