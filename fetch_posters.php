<?php
/**
 * CineFilter — Poster Cache Builder
 * Run this ONCE from terminal: php fetch_posters.php
 * It will fetch all poster URLs from TMDB and save to posters_cache.json
 * After that, index.php reads from cache — no more API calls needed!
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$TMDB_KEY  = '69276f754b42b04ae1fa43b51ab5fc84';
$CSV_FILE  = __DIR__ . '/IMDB Movies 2000 - 2020.csv';
$CACHE     = __DIR__ . '/posters_cache.json';
$IMG_BASE  = 'https://image.tmdb.org/t/p/w500';

// Load existing cache so we can resume if interrupted
$cache = file_exists($CACHE) ? json_decode(file_get_contents($CACHE), true) : [];
echo "Cache loaded: " . count($cache) . " existing entries\n";

// Read all IMDB IDs from CSV
$ids = [];
if (($handle = fopen($CSV_FILE, 'r')) !== false) {
    fgetcsv($handle, 0, ',', '"', '\\'); // skip header
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (!empty($row[0])) $ids[] = $row[0];
    }
    fclose($handle);
}

$total   = count($ids);
$fetched = 0;
$skipped = 0;
$failed  = 0;

echo "Total movies to process: $total\n";
echo "Starting fetch...\n\n";

foreach ($ids as $i => $imdb_id) {
    // Skip if already cached
    if (isset($cache[$imdb_id])) {
        $skipped++;
        continue;
    }

    $url = "https://api.themoviedb.org/3/find/{$imdb_id}?api_key={$TMDB_KEY}&external_source=imdb_id";

    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header'  => "Accept: application/json\r\n"
    ]]);

    $response = @file_get_contents($url, false, $ctx);

    if ($response) {
        $data = json_decode($response, true);
        $poster = $data['movie_results'][0]['poster_path'] ?? null;
        $cache[$imdb_id] = $poster ? $IMG_BASE . $poster : '';
        $fetched++;
    } else {
        $cache[$imdb_id] = '';
        $failed++;
    }

    // Save cache every 50 entries so we don't lose progress
    if ($fetched % 50 === 0) {
        file_put_contents($CACHE, json_encode($cache));
        echo "Progress: " . ($i+1) . "/$total — Fetched: $fetched, Skipped: $skipped, Failed: $failed\n";
    }

    // Be nice to TMDB API — small delay
    usleep(50000); // 0.05s = ~20 requests/second (well under 40/s limit)
}

// Final save
file_put_contents($CACHE, json_encode($cache));
echo "\n✅ Done! $fetched fetched, $skipped skipped, $failed failed\n";
echo "Cache saved to: $CACHE\n";
echo "Total cached: " . count($cache) . " movies\n";