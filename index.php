<?php
// ═══════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════
$TMDB_KEY = '69276f754b42b04ae1fa43b51ab5fc84';
$IMG_BASE = 'https://image.tmdb.org/t/p/w500';
$BACK_BASE= 'https://image.tmdb.org/t/p/w1280';
$CSV_FILE = __DIR__ . '/IMDB Movies 2000 - 2020.csv';
$CACHE    = __DIR__ . '/posters_cache.json';

// ═══════════════════════════════════════════════
// LOAD CACHE
// ═══════════════════════════════════════════════
$poster_cache = file_exists($CACHE) ? json_decode(file_get_contents($CACHE), true) : [];

function getPoster($id, &$cache, $key, $base) {
    if (isset($cache[$id])) return $cache[$id];
    $url = "https://api.themoviedb.org/3/find/{$id}?api_key={$key}&external_source=imdb_id";
    $ctx = stream_context_create(['http'=>['timeout'=>3]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        $path = $data['movie_results'][0]['poster_path'] ?? null;
        $poster = $path ? $base . $path : '';
        $cache[$id] = $poster;
        return $poster;
    }
    return '';
}

function getBackdrop($id, $key) {
    $url = "https://api.themoviedb.org/3/find/{$id}?api_key={$key}&external_source=imdb_id";
    $ctx = stream_context_create(['http'=>['timeout'=>3]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        return $data['movie_results'][0]['backdrop_path'] ?? null;
    }
    return null;
}

// ═══════════════════════════════════════════════
// READ CSV
// ═══════════════════════════════════════════════
$all_movies = [];
if (($handle = fopen($CSV_FILE, 'r')) !== false) {
    fgetcsv($handle, 0, ',', '"', '\\');
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (empty($row[1])) continue;
        $all_movies[] = [
                'id'          => $row[0],
                'title'       => $row[1],
                'year'        => (int)$row[3],
                'genres'      => $row[5],
                'duration'    => $row[6],
                'country'     => $row[7],
                'director'    => $row[11],
                'actors'      => $row[13],
                'description' => $row[16],
                'rating'      => (float)$row[18],
                'votes'       => (int)preg_replace('/[^0-9]/', '', $row[19]),
                'worldwide'   => (int)preg_replace('/[^0-9]/', '', $row[22]),
        ];
    }
    fclose($handle);
}

// ═══════════════════════════════════════════════
// BUILD ROWS
// ═══════════════════════════════════════════════
function topN($movies, $by, $n=20, $filter_fn=null) {
    $list = $filter_fn ? array_values(array_filter($movies, $filter_fn)) : $movies;
    usort($list, fn($a,$b) => $b[$by] <=> $a[$by]);
    return array_slice($list, 0, $n);
}

// Top Rated
$top_rated = topN($all_movies, 'rating', 20);

// Best Selling
$best_selling = topN($all_movies, 'worldwide', 20);

// New Releases (2015-2020)
$new_releases = topN($all_movies, 'year', 20, fn($m)=>$m['year']>=2015);

// Staff Picks (rating >= 8.0, sorted by votes)
$staff_picks = topN($all_movies, 'votes', 20, fn($m)=>$m['rating']>=8.0);

// Genre rows — pick top genres
$genre_rows = [];
$target_genres = ['Action','Drama','Comedy','Thriller','Crime','Sci-Fi','Romance','Horror','Adventure','Biography'];
foreach ($target_genres as $genre) {
    $list = topN($all_movies, 'rating', 20, fn($m)=>stripos($m['genres'],$genre)!==false);
    if (count($list) >= 5) $genre_rows[$genre] = $list;
}

// Decade rows
$decade_rows = [];
foreach (['2010'=>'2010s','2000'=>'2000s','1990'=>'1990s'] as $dec=>$label) {
    $list = topN($all_movies, 'rating', 20, fn($m)=>$m['year']>=(int)$dec&&$m['year']<=(int)$dec+9);
    if (count($list) >= 5) $decade_rows[$label] = $list;
}

// All genres for dropdown
$all_genres = [];
foreach ($all_movies as $m) {
    foreach (explode(',', $m['genres']) as $g) {
        $g = trim($g); if($g) $all_genres[$g] = true;
    }
}
ksort($all_genres);
$genres = array_keys($all_genres);

// ── Hero movie ────────────────────────────────
shuffle($top_rated);
$hero = $top_rated[0];
$hero['poster']   = getPoster($hero['id'], $poster_cache, $TMDB_KEY, $IMG_BASE);
$hero_backdrop    = getBackdrop($hero['id'], $TMDB_KEY);
$hero_bg          = $hero_backdrop ? 'https://image.tmdb.org/t/p/w1280'.$hero_backdrop : '';

// ── Fetch posters for all rows ────────────────
function attachPosters(&$list, &$cache, $key, $base) {
    foreach ($list as &$m) {
        $m['poster'] = getPoster($m['id'], $cache, $key, $base);
    }
    unset($m);
}
attachPosters($top_rated,   $poster_cache, $TMDB_KEY, $IMG_BASE);
attachPosters($best_selling,$poster_cache, $TMDB_KEY, $IMG_BASE);
attachPosters($new_releases,$poster_cache, $TMDB_KEY, $IMG_BASE);
attachPosters($staff_picks, $poster_cache, $TMDB_KEY, $IMG_BASE);
foreach ($genre_rows  as &$row) attachPosters($row, $poster_cache, $TMDB_KEY, $IMG_BASE);
foreach ($decade_rows as &$row) attachPosters($row, $poster_cache, $TMDB_KEY, $IMG_BASE);
unset($row);

// ── Helpers ────────────────────────────────────
function fmt($v) {
    if($v>=1000000000) return '$'.round($v/1000000000,1).'B';
    if($v>=1000000)    return '$'.round($v/1000000,1).'M';
    if($v>0)           return '$'.number_format($v);
    return '—';
}
function genreColor($genres) {
    $map=['Action'=>'220,38,38','Adventure'=>'234,88,12','Comedy'=>'202,138,4',
            'Romance'=>'219,39,119','Drama'=>'79,70,229','Crime'=>'15,118,110',
            'Thriller'=>'101,163,13','Horror'=>'127,29,29','Sci-Fi'=>'6,182,212',
            'Biography'=>'124,58,237','Music'=>'236,72,153','Fantasy'=>'139,92,246'];
    $first = trim(explode(',',$genres)[0]);
    return $map[$first] ?? '99,102,241';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CineFilter</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{
            --bg:#080810;--surface:rgba(255,255,255,.04);
            --border:rgba(255,255,255,.09);
            --gold:#f5c518;--gold2:#f97316;
            --red:#e50914;--text:#f0f0fa;--dim:#6b6b8a;
            --nav-h:64px;--radius:10px;
        }
        html{scroll-behavior:smooth}
        body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
        body::before{content:'';position:fixed;inset:0;z-index:-1;
            background:radial-gradient(ellipse 80% 50% at 20% -10%,rgba(99,102,241,.1) 0%,transparent 60%),
            radial-gradient(ellipse 60% 40% at 80% 110%,rgba(219,39,119,.07) 0%,transparent 60%)}
        a{text-decoration:none;color:inherit}
        button{cursor:pointer;font-family:'DM Sans',sans-serif}
        img{display:block}

        /* ══════════════════════════════
           NAVBAR
        ══════════════════════════════ */
        .nav{
            position:fixed;top:0;left:0;right:0;
            height:var(--nav-h);z-index:500;
            padding:0 32px;
            display:flex;align-items:center;gap:6px;
            transition:background .3s,backdrop-filter .3s;
        }
        .nav.scrolled{
            background:rgba(8,8,16,.92);
            backdrop-filter:blur(24px);
            -webkit-backdrop-filter:blur(24px);
            border-bottom:1px solid var(--border);
        }
        .nav-logo{
            font-family:'Bebas Neue',sans-serif;
            font-size:26px;letter-spacing:4px;
            background:linear-gradient(135deg,var(--gold),var(--gold2));
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            margin-right:16px;white-space:nowrap;user-select:none;flex-shrink:0;
        }

        /* Nav links */
        .nav-links{display:flex;align-items:center;gap:2px;flex:1}
        .nav-item{
            position:relative;
            display:flex;align-items:center;gap:5px;
            padding:8px 12px;border-radius:8px;
            font-size:14px;font-weight:500;color:rgba(240,240,250,.7);
            transition:all .2s;white-space:nowrap;cursor:pointer;
            background:none;border:none;
        }
        .nav-item:hover,.nav-item.active{color:var(--text);background:rgba(255,255,255,.07)}
        .nav-item.active{color:var(--gold)}
        .nav-arrow{font-size:9px;opacity:.5;transition:transform .2s}
        .nav-item:hover .nav-arrow{transform:rotate(180deg)}

        /* Dropdowns */
        .dropdown{
            position:absolute;top:calc(100% + 8px);left:0;
            background:rgba(10,10,22,.96);
            backdrop-filter:blur(24px);
            -webkit-backdrop-filter:blur(24px);
            border:1px solid var(--border);
            border-radius:12px;padding:8px;
            min-width:200px;
            opacity:0;visibility:hidden;
            transform:translateY(-8px);
            transition:all .2s ease;
            z-index:600;
        }
        .nav-item:hover .dropdown{opacity:1;visibility:visible;transform:translateY(0)}
        .dropdown-grid{display:grid;grid-template-columns:1fr 1fr;gap:2px}
        .dd-item{
            display:block;padding:9px 12px;border-radius:8px;
            font-size:13px;color:var(--dim);
            transition:all .2s;white-space:nowrap;
        }
        .dd-item:hover{background:rgba(255,255,255,.07);color:var(--text)}
        .dd-item.highlighted{color:var(--gold)}

        /* Right side of nav */
        .nav-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-shrink:0}
        .nav-search-wrap{position:relative;display:flex;align-items:center}
        .nav-search-btn{
            background:rgba(255,255,255,.07);border:1px solid var(--border);
            color:var(--dim);border-radius:8px;padding:8px 12px;
            font-size:15px;transition:all .2s;
        }
        .nav-search-btn:hover{background:rgba(255,255,255,.12);color:var(--text)}
        .nav-search-input{
            position:absolute;right:0;top:50%;transform:translateY(-50%);
            width:0;opacity:0;
            background:rgba(10,10,22,.96);
            backdrop-filter:blur(20px);
            border:1px solid var(--border);
            border-radius:25px;padding:9px 18px 9px 40px;
            color:var(--text);font-size:14px;font-family:'DM Sans',sans-serif;
            outline:none;transition:all .3s ease;
            pointer-events:none;
        }
        .nav-search-input.open{
            width:280px;opacity:1;pointer-events:all;
            border-color:rgba(245,197,24,.4);
        }
        .nav-search-input::placeholder{color:var(--dim)}
        .nav-search-ico{position:absolute;left:0;top:50%;transform:translateY(-50%);
            color:var(--dim);font-size:14px;pointer-events:none;padding-left:13px;
            opacity:0;transition:opacity .3s}
        .nav-search-ico.open{opacity:1}
        .nav-wl-btn{
            position:relative;
            background:rgba(255,255,255,.07);border:1px solid var(--border);
            color:var(--text);border-radius:8px;padding:8px 14px;
            font-size:13px;font-weight:500;transition:all .2s;
            display:flex;align-items:center;gap:6px;
        }
        .nav-wl-btn:hover{background:rgba(255,255,255,.12)}
        .wl-dot{
            position:absolute;top:-4px;right:-4px;
            background:var(--red);color:#fff;
            font-size:9px;font-weight:700;
            width:16px;height:16px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
        }

        /* Mobile hamburger */
        .hamburger{
            display:none;background:rgba(255,255,255,.07);
            border:1px solid var(--border);color:var(--text);
            border-radius:8px;padding:8px 11px;font-size:18px;
        }

        /* Mobile menu */
        .mobile-menu{
            display:none;position:fixed;top:var(--nav-h);left:0;right:0;
            background:rgba(8,8,16,.97);backdrop-filter:blur(24px);
            border-bottom:1px solid var(--border);
            padding:16px 20px;z-index:450;
            flex-direction:column;gap:4px;
        }
        .mobile-menu.open{display:flex}
        .mm-item{padding:11px 14px;border-radius:9px;font-size:14px;color:var(--dim);transition:all .2s}
        .mm-item:hover{background:rgba(255,255,255,.07);color:var(--text)}
        .mm-search{
            width:100%;background:rgba(255,255,255,.06);
            border:1px solid var(--border);border-radius:25px;
            padding:10px 18px;color:var(--text);font-size:14px;
            font-family:'DM Sans',sans-serif;outline:none;margin-top:8px;
        }
        .mm-search::placeholder{color:var(--dim)}

        /* ══════════════════════════════
           HERO
        ══════════════════════════════ */
        .hero{
            position:relative;
            height:100vh;min-height:600px;max-height:900px;
            display:flex;align-items:flex-end;
            padding:0 48px 80px;
            overflow:hidden;
        }
        .hero-bg{
            position:absolute;inset:0;
            background-size:cover;background-position:center top;
            background-color:#0d0d1a;
        }
        .hero-bg::after{
            content:'';position:absolute;inset:0;
            background:
                    linear-gradient(to right, rgba(8,8,16,.95) 35%, rgba(8,8,16,.2) 70%, transparent),
                    linear-gradient(to top, rgba(8,8,16,1) 0%, rgba(8,8,16,.4) 30%, transparent 60%);
        }
        .hero-content{position:relative;z-index:2;max-width:580px}
        .hero-badge{
            display:inline-flex;align-items:center;gap:6px;
            background:rgba(245,197,24,.12);border:1px solid rgba(245,197,24,.3);
            border-radius:20px;padding:4px 12px;
            font-size:12px;font-weight:600;color:var(--gold);
            margin-bottom:16px;
        }
        .hero-title{
            font-family:'Bebas Neue',sans-serif;
            font-size:clamp(42px,6vw,80px);
            letter-spacing:3px;line-height:1;
            margin-bottom:16px;
        }
        .hero-meta{
            display:flex;align-items:center;gap:14px;
            margin-bottom:18px;flex-wrap:wrap;
        }
        .hero-meta span{font-size:14px;color:rgba(240,240,250,.6)}
        .hero-rat{color:var(--gold);font-weight:700;font-size:16px}
        .hero-desc{
            font-size:15px;color:rgba(240,240,250,.7);
            line-height:1.75;margin-bottom:28px;
            display:-webkit-box;-webkit-line-clamp:3;
            -webkit-box-orient:vertical;overflow:hidden;
        }
        .hero-genre-pills{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:28px}
        .hero-gpill{
            background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
            border-radius:20px;padding:5px 14px;font-size:12px;color:rgba(240,240,250,.6);
        }
        .hero-btns{display:flex;gap:12px;flex-wrap:wrap}
        .hero-btn-play{
            display:flex;align-items:center;gap:8px;
            background:linear-gradient(135deg,var(--gold),var(--gold2));
            color:#000;border:none;border-radius:10px;
            padding:13px 28px;font-size:15px;font-weight:700;
            transition:opacity .2s;
        }
        .hero-btn-play:hover{opacity:.9}
        .hero-btn-wl{
            display:flex;align-items:center;gap:8px;
            background:rgba(255,255,255,.1);
            border:1px solid rgba(255,255,255,.2);
            color:var(--text);border-radius:10px;
            padding:13px 24px;font-size:15px;font-weight:500;
            backdrop-filter:blur(10px);transition:all .2s;
        }
        .hero-btn-wl:hover{background:rgba(255,255,255,.18)}
        .hero-scroll-hint{
            position:absolute;bottom:24px;left:50%;transform:translateX(-50%);
            color:var(--dim);font-size:12px;display:flex;flex-direction:column;align-items:center;gap:6px;
            animation:bounce 2s infinite;z-index:2;
        }
        @keyframes bounce{0%,100%{transform:translateX(-50%) translateY(0)}50%{transform:translateX(-50%) translateY(6px)}}

        /* ══════════════════════════════
           ROWS SECTION
        ══════════════════════════════ */
        .rows-section{padding:20px 0 80px}

        .row-block{margin-bottom:40px}
        .row-header{
            display:flex;align-items:center;justify-content:space-between;
            padding:0 32px;margin-bottom:16px;
        }
        .row-title{
            font-family:'Bebas Neue',sans-serif;
            font-size:22px;letter-spacing:2px;color:var(--text);
            display:flex;align-items:center;gap:10px;
        }
        .row-title-icon{font-size:20px}
        .row-see-all{
            font-size:13px;color:var(--gold);font-weight:500;
            display:flex;align-items:center;gap:4px;
            transition:gap .2s;
        }
        .row-see-all:hover{gap:8px}

        /* Horizontal scroll */
        .row-scroll{
            display:flex;gap:12px;
            overflow-x:auto;padding:8px 32px 16px;
            scroll-behavior:smooth;
            scrollbar-width:none;
        }
        .row-scroll::-webkit-scrollbar{display:none}

        /* Movie card */
        .mc{
            flex-shrink:0;width:150px;
            border-radius:var(--radius);overflow:hidden;
            border:1px solid var(--border);
            background:var(--surface);
            transition:transform .3s,border-color .3s,box-shadow .3s;
            cursor:pointer;position:relative;
        }
        .mc:hover{
            transform:scale(1.06) translateY(-4px);
            border-color:rgba(245,197,24,.4);
            box-shadow:0 16px 40px rgba(0,0,0,.6);
            z-index:3;
        }
        .mc-poster{width:100%;aspect-ratio:2/3;object-fit:cover}
        .mc-no-poster{
            width:100%;aspect-ratio:2/3;
            display:flex;align-items:center;justify-content:center;
            position:relative;overflow:hidden;
        }
        .mc-letter{
            font-family:'Bebas Neue',sans-serif;
            font-size:44px;color:rgba(255,255,255,.12);
            position:relative;z-index:1;user-select:none;
        }
        .mc-rat{
            position:absolute;top:6px;right:6px;
            background:rgba(0,0,0,.7);backdrop-filter:blur(6px);
            border:1px solid rgba(245,197,24,.3);
            border-radius:5px;padding:2px 7px;
            font-size:11px;font-weight:700;color:var(--gold);
        }

        /* Hover overlay on card */
        .mc-hover{
            position:absolute;inset:0;
            background:linear-gradient(to top,rgba(0,0,0,.97) 30%,transparent 70%);
            opacity:0;transition:opacity .25s;
            display:flex;flex-direction:column;justify-content:flex-end;
            padding:10px;
        }
        .mc:hover .mc-hover{opacity:1}
        .mc-hover-title{font-size:11px;font-weight:600;margin-bottom:7px;line-height:1.3}
        .mc-hover-btns{display:flex;gap:5px}
        .mc-hbtn{
            flex:1;padding:6px 3px;
            border-radius:6px;font-size:10px;font-weight:700;
            border:none;transition:all .15s;
        }
        .mc-hbtn-wl{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
        .mc-hbtn-det{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)}
        .mc-hbtn-det:hover{background:rgba(255,255,255,.22)}

        .mc-body{padding:9px 10px 11px}
        .mc-title{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px}
        .mc-row{display:flex;justify-content:space-between;align-items:center}
        .mc-year{font-size:10.5px;color:var(--dim)}
        .mc-stars{font-size:11px;color:var(--gold);font-weight:700}

        /* ══════════════════════════════
           MODAL
        ══════════════════════════════ */
        .modal-bg{
            display:none;position:fixed;inset:0;
            background:rgba(0,0,0,.8);backdrop-filter:blur(16px);
            z-index:700;align-items:center;justify-content:center;padding:20px;
        }
        .modal-bg.open{display:flex}
        .modal{
            background:rgba(10,10,22,.9);
            backdrop-filter:blur(30px);
            border:1px solid var(--border);
            border-radius:20px;width:100%;max-width:640px;
            max-height:92vh;overflow-y:auto;
            padding:0;position:relative;
            animation:popIn .25s cubic-bezier(.34,1.56,.64,1);
        }
        .modal::-webkit-scrollbar{width:4px}
        .modal::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
        @keyframes popIn{from{transform:scale(.88) translateY(20px);opacity:0}to{transform:scale(1);opacity:1}}

        /* Modal backdrop top */
        .modal-top{
            position:relative;height:220px;overflow:hidden;border-radius:20px 20px 0 0;
            background:linear-gradient(135deg,rgba(99,102,241,.3),rgba(219,39,119,.2));
        }
        .modal-top-img{width:100%;height:100%;object-fit:cover;opacity:.6}
        .modal-top-grad{position:absolute;inset:0;background:linear-gradient(to top,rgba(10,10,22,1) 0%,transparent 60%)}
        .modal-top-poster{
            position:absolute;bottom:-30px;left:24px;
            width:90px;height:135px;border-radius:8px;overflow:hidden;
            border:2px solid var(--border);
            box-shadow:0 8px 32px rgba(0,0,0,.6);
        }
        .modal-top-poster img{width:100%;height:100%;object-fit:cover}
        .modal-x{
            position:absolute;top:14px;right:14px;z-index:10;
            background:rgba(0,0,0,.5);backdrop-filter:blur(8px);
            border:1px solid var(--border);color:rgba(255,255,255,.7);
            border-radius:50%;width:30px;height:30px;font-size:13px;
            display:flex;align-items:center;justify-content:center;transition:all .2s;
        }
        .modal-x:hover{background:rgba(0,0,0,.8);color:#fff}

        /* Modal body */
        .modal-body{padding:44px 24px 28px}
        .modal-ttl{font-family:'Bebas Neue',sans-serif;font-size:30px;letter-spacing:2px;margin-bottom:8px;line-height:1.1}
        .modal-meta-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px}
        .modal-meta-row span{font-size:13px;color:var(--dim);display:flex;align-items:center;gap:4px}
        .modal-rat{color:var(--gold);font-weight:700;font-size:15px}
        .modal-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
        .modal-tag{background:rgba(255,255,255,.06);border:1px solid var(--border);padding:4px 12px;border-radius:20px;font-size:12px;color:var(--dim)}
        .modal-desc{color:rgba(240,240,250,.72);font-size:13.5px;line-height:1.8;margin-bottom:18px}
        .modal-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
        .modal-info-box{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:12px}
        .modal-info-label{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--dim);margin-bottom:5px}
        .modal-info-val{font-size:13px;font-weight:500}
        .modal-cast-box{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:20px}
        .modal-cast-list{font-size:12.5px;color:rgba(240,240,250,.65);line-height:1.75;margin-top:6px}
        .modal-btns{display:flex;gap:10px}
        .modal-btn-add{
            flex:1;padding:12px;
            background:linear-gradient(135deg,var(--gold),var(--gold2));
            color:#000;border:none;border-radius:10px;
            font-size:14px;font-weight:700;transition:opacity .2s;
        }
        .modal-btn-add:hover{opacity:.9}
        .modal-btn-close{
            padding:12px 20px;
            background:rgba(255,255,255,.07);
            border:1px solid var(--border);color:var(--dim);
            border-radius:10px;font-size:14px;transition:all .2s;
        }
        .modal-btn-close:hover{background:rgba(255,255,255,.12);color:var(--text)}

        /* ══════════════════════════════
           WATCHLIST PANEL
        ══════════════════════════════ */
        .wl-panel{
            display:none;position:fixed;top:0;right:0;bottom:0;
            width:340px;max-width:90vw;
            background:rgba(8,8,16,.97);
            backdrop-filter:blur(24px);
            border-left:1px solid var(--border);
            z-index:800;flex-direction:column;
            animation:slideIn .25s ease;
        }
        .wl-panel.open{display:flex}
        @keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
        .wl-header{
            display:flex;align-items:center;justify-content:space-between;
            padding:20px 20px 16px;border-bottom:1px solid var(--border);
        }
        .wl-title{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px}
        .wl-close{background:rgba(255,255,255,.07);border:1px solid var(--border);color:var(--dim);border-radius:8px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .2s}
        .wl-close:hover{color:var(--text);background:rgba(255,255,255,.12)}
        .wl-list{flex:1;overflow-y:auto;padding:12px}
        .wl-list::-webkit-scrollbar{width:3px}
        .wl-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:3px}
        .wl-item{display:flex;gap:10px;padding:10px;border-radius:9px;margin-bottom:6px;transition:background .2s}
        .wl-item:hover{background:rgba(255,255,255,.05)}
        .wl-item-poster{width:46px;height:69px;border-radius:6px;overflow:hidden;flex-shrink:0;background:rgba(255,255,255,.05)}
        .wl-item-poster img{width:100%;height:100%;object-fit:cover}
        .wl-item-info{flex:1;min-width:0}
        .wl-item-title{font-size:13px;font-weight:500;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .wl-item-year{font-size:11px;color:var(--dim)}
        .wl-item-rm{background:none;border:none;color:var(--dim);font-size:16px;padding:4px;border-radius:5px;transition:color .2s}
        .wl-item-rm:hover{color:var(--red)}
        .wl-empty{text-align:center;padding:60px 20px;color:var(--dim)}
        .wl-empty h3{font-family:'Bebas Neue',sans-serif;font-size:24px;margin-bottom:8px;color:rgba(255,255,255,.15)}

        /* ══════════════════════════════
           RESPONSIVE
        ══════════════════════════════ */
        @media(max-width:900px){
            .nav-links{display:none}
            .hamburger{display:flex}
            .hero{padding:0 24px 60px}
            .row-header{padding:0 16px}
            .row-scroll{padding:8px 16px 16px}
        }
        @media(max-width:600px){
            .hero-title{font-size:36px}
            .hero-desc{-webkit-line-clamp:2}
            .mc{width:120px}
        }

        /* ══════════════════════════════
           SCROLLBAR
        ══════════════════════════════ */
        ::-webkit-scrollbar{width:5px}
        ::-webkit-scrollbar-track{background:var(--bg)}
        ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:5px}
    </style>
</head>
<body>

<!-- ══════════════════════════════
     NAVBAR
══════════════════════════════ -->
<nav class="nav" id="navbar">
    <a href="?" class="nav-logo">▶ CINEFILTER</a>

    <div class="nav-links">
        <!-- Home -->
        <a href="?" class="nav-item active">Home</a>

        <!-- Movies dropdown -->
        <div class="nav-item">
            Movies <span class="nav-arrow">▼</span>
            <div class="dropdown">
                <a class="dd-item highlighted" href="?view=top_rated">⭐ Top Rated</a>
                <a class="dd-item highlighted" href="?view=new_releases">🆕 New Releases</a>
                <a class="dd-item highlighted" href="?view=best_selling">🏆 Best Selling</a>
                <a class="dd-item highlighted" href="?view=staff_picks">✨ Staff Picks</a>
            </div>
        </div>

        <!-- Categories dropdown -->
        <div class="nav-item">
            Categories <span class="nav-arrow">▼</span>
            <div class="dropdown">
                <div class="dropdown-grid">
                    <?php foreach(array_slice($genres,0,16) as $g): ?>
                        <a class="dd-item" href="?genre=<?= urlencode($g) ?>"><?= htmlspecialchars($g) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Decades dropdown -->
        <div class="nav-item">
            Decades <span class="nav-arrow">▼</span>
            <div class="dropdown">
                <a class="dd-item" href="?decade=2010">📅 2010s</a>
                <a class="dd-item" href="?decade=2000">📅 2000s</a>
                <a class="dd-item" href="?decade=1990">📅 1990s</a>
                <a class="dd-item" href="?decade=1980">📅 1980s</a>
            </div>
        </div>

        <a href="?view=tv" class="nav-item">TV Shows</a>
    </div>

    <!-- Right side -->
    <div class="nav-right">
        <!-- Search -->
        <div class="nav-search-wrap">
            <button class="nav-search-btn" onclick="toggleSearch()" id="searchBtn">🔍</button>
            <span class="nav-search-ico" id="searchIco">🔍</span>
            <form method="get" action="">
                <input type="text" name="search" class="nav-search-input" id="searchInput"
                       placeholder="Search movies, actors…" autocomplete="off"
                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </form>
        </div>

        <!-- Watchlist -->
        <button class="nav-wl-btn" onclick="toggleWLPanel()">
            🔖 Watchlist
            <span class="wl-dot" id="wlDot" style="display:none">0</span>
        </button>

        <!-- Mobile hamburger -->
        <button class="hamburger" onclick="toggleMobileMenu()">☰</button>
    </div>
</nav>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <a class="mm-item" href="?">🏠 Home</a>
    <a class="mm-item" href="?view=top_rated">⭐ Top Rated</a>
    <a class="mm-item" href="?view=new_releases">🆕 New Releases</a>
    <a class="mm-item" href="?view=best_selling">🏆 Best Selling</a>
    <a class="mm-item" href="?view=staff_picks">✨ Staff Picks</a>
    <hr style="border-color:var(--border);margin:6px 0">
    <?php foreach($target_genres as $g): ?>
        <a class="mm-item" href="?genre=<?= urlencode($g) ?>"><?= $g ?></a>
    <?php endforeach; ?>
    <form method="get" action="" style="margin-top:8px">
        <input type="text" name="search" class="mm-search" placeholder="Search…" autocomplete="off">
    </form>
</div>

<!-- ══════════════════════════════
     HERO BANNER
══════════════════════════════ -->
<section class="hero">
    <div class="hero-bg" <?= $hero_bg ? "style=\"background-image:url('{$hero_bg}')\"" : '' ?>></div>
    <div class="hero-content">
        <div class="hero-badge">★ Featured Film</div>
        <h1 class="hero-title"><?= htmlspecialchars($hero['title']) ?></h1>
        <div class="hero-meta">
            <span class="hero-rat">★ <?= $hero['rating'] ?> / 10</span>
            <span>📅 <?= $hero['year'] ?></span>
            <span>⏱ <?= $hero['duration'] ?> min</span>
            <span>🌍 <?= htmlspecialchars($hero['country']) ?></span>
        </div>
        <div class="hero-genre-pills">
            <?php foreach(array_slice(array_map('trim',explode(',',$hero['genres'])),0,3) as $g): ?>
                <span class="hero-gpill"><?= htmlspecialchars($g) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="hero-desc"><?= htmlspecialchars($hero['description']) ?></p>
        <div class="hero-btns">
            <button class="hero-btn-play" onclick="openModal('<?= addslashes(htmlspecialchars($hero['title'])) ?>','<?= $hero['year'] ?>','<?= $hero['rating'] ?>','<?= addslashes(htmlspecialchars($hero['genres'])) ?>','<?= addslashes(htmlspecialchars($hero['director'])) ?>','<?= addslashes(htmlspecialchars($hero['actors'])) ?>','<?= addslashes(htmlspecialchars($hero['description'])) ?>','<?= fmt($hero['worldwide']) ?>','<?= $hero['duration'] ?>','<?= htmlspecialchars($hero['poster']) ?>')">
                ▶ &nbsp;More Info
            </button>
            <button class="hero-btn-wl" onclick="toggleWL('<?= $hero['id'] ?>','<?= addslashes(htmlspecialchars($hero['title'])) ?>','<?= htmlspecialchars($hero['poster']) ?>',this)">
                + Add to Watchlist
            </button>
        </div>
    </div>
    <div class="hero-scroll-hint">
        <span>Scroll to explore</span>
        <span>↓</span>
    </div>
</section>

<!-- ══════════════════════════════
     ROWS
══════════════════════════════ -->
<section class="rows-section">

    <?php
    // Helper to render a row
    function renderRow($title, $icon, $movies, $see_all_url = '#') {
        global $poster_cache, $TMDB_KEY, $IMG_BASE;
        echo "<div class='row-block'>";
        echo "<div class='row-header'>";
        echo "<div class='row-title'><span class='row-title-icon'>$icon</span> $title</div>";
        echo "<a href='$see_all_url' class='row-see-all'>See all →</a>";
        echo "</div>";
        echo "<div class='row-scroll'>";
        foreach ($movies as $m) {
            $poster = $m['poster'] ?? '';
            $letter = strtoupper(substr($m['title'],0,1));
            $color  = genreColor($m['genres']);
            $js     = fn($s) => addslashes(htmlspecialchars($s));
            echo "<div class='mc' onclick=\"openModal('{$js($m['title'])}','{$m['year']}','{$m['rating']}','{$js($m['genres'])}','{$js($m['director'])}','{$js($m['actors'])}','{$js($m['description'])}','".fmt($m['worldwide'])."','{$m['duration']}','".htmlspecialchars($poster)."')\">";
            if ($poster) {
                echo "<img class='mc-poster' src='".htmlspecialchars($poster)."' alt='".htmlspecialchars($m['title'])."' loading='lazy' onerror=\"this.style.display='none';this.nextElementSibling.style.display='flex'\">";
                echo "<div class='mc-no-poster' style='display:none'><div style='position:absolute;inset:0;background:linear-gradient(135deg,rgba({$color},.35),rgba({$color},.05))'></div><div class='mc-letter'>$letter</div></div>";
            } else {
                echo "<div class='mc-no-poster'><div style='position:absolute;inset:0;background:linear-gradient(135deg,rgba({$color},.35),rgba({$color},.05))'></div><div class='mc-letter'>$letter</div></div>";
            }
            echo "<div class='mc-rat'>★ {$m['rating']}</div>";
            echo "<div class='mc-hover'>";
            echo "<div class='mc-hover-title'>".htmlspecialchars($m['title'])."</div>";
            echo "<div class='mc-hover-btns'>";
            echo "<button class='mc-hbtn mc-hbtn-wl' onclick=\"event.stopPropagation();toggleWL('{$js($m['id'])}','{$js($m['title'])}','".htmlspecialchars($poster)."',this)\">+ WL</button>";
            echo "<button class='mc-hbtn mc-hbtn-det'>Info</button>";
            echo "</div></div>";
            echo "<div class='mc-body'>";
            echo "<div class='mc-title'>".htmlspecialchars($m['title'])."</div>";
            echo "<div class='mc-row'><span class='mc-year'>{$m['year']}</span><span class='mc-stars'>★ {$m['rating']}</span></div>";
            echo "</div></div>";
        }
        echo "</div></div>";
    }

    renderRow('Top Rated', '⭐', $top_rated,   '?view=top_rated');
    renderRow('Best Selling', '🏆', $best_selling,'?view=best_selling');
    renderRow('New Releases', '🆕', $new_releases,'?view=new_releases');
    renderRow('Staff Picks', '✨', $staff_picks, '?view=staff_picks');

    foreach ($genre_rows as $genre => $list) {
        renderRow($genre, '🎬', $list, "?genre=".urlencode($genre));
    }
    foreach ($decade_rows as $label => $list) {
        renderRow("Best of $label", '📅', $list, "?decade=".substr($label,0,4));
    }
    ?>

</section>

<!-- ══════════════════════════════
     MODAL
══════════════════════════════ -->
<div class="modal-bg" id="modalBg">
    <div class="modal">
        <button class="modal-x" onclick="closeModal()">✕</button>
        <div class="modal-top" id="modalTop">
            <div class="modal-top-grad"></div>
            <div class="modal-top-poster" id="modalPosterWrap"></div>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- ══════════════════════════════
     WATCHLIST PANEL
══════════════════════════════ -->
<div class="wl-panel" id="wlPanel">
    <div class="wl-header">
        <div class="wl-title">🔖 My Watchlist</div>
        <button class="wl-close" onclick="toggleWLPanel()">✕</button>
    </div>
    <div class="wl-list" id="wlList"></div>
</div>
<div id="wlOverlay" onclick="toggleWLPanel()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:799"></div>

<script>
    // ══════════════════════════════
    // NAVBAR SCROLL
    // ══════════════════════════════
    window.addEventListener('scroll', ()=>{
        document.getElementById('navbar').classList.toggle('scrolled', window.scrollY > 50);
    });

    // ══════════════════════════════
    // SEARCH
    // ══════════════════════════════
    function toggleSearch(){
        const inp = document.getElementById('searchInput');
        const ico = document.getElementById('searchIco');
        inp.classList.toggle('open');
        ico.classList.toggle('open');
        if(inp.classList.contains('open')) { setTimeout(()=>inp.focus(),300); }
    }
    document.addEventListener('keydown', e=>{
        if(e.key==='Escape'){
            document.getElementById('searchInput').classList.remove('open');
            document.getElementById('searchIco').classList.remove('open');
            closeModal();
        }
    });

    // ══════════════════════════════
    // MOBILE MENU
    // ══════════════════════════════
    function toggleMobileMenu(){
        document.getElementById('mobileMenu').classList.toggle('open');
    }

    // ══════════════════════════════
    // WATCHLIST
    // ══════════════════════════════
    let wl = JSON.parse(localStorage.getItem('cinemate_wl') || '[]');

    function syncWL(){
        const count = wl.length;
        const dot   = document.getElementById('wlDot');
        dot.textContent = count;
        dot.style.display = count > 0 ? 'flex' : 'none';
        renderWLPanel();
    }
    syncWL();

    function toggleWL(id, title, poster, btn){
        if(wl.find(x=>x.id===id)){
            wl = wl.filter(x=>x.id!==id);
            if(btn){ btn.textContent='+ Watchlist'; btn.style.cssText=''; }
        } else {
            wl.push({id, title, poster});
            if(btn){ btn.textContent='✓ Saved'; btn.style.background='rgba(16,185,129,.8)'; btn.style.color='#fff'; }
        }
        localStorage.setItem('cinemate_wl', JSON.stringify(wl));
        syncWL();
    }

    function renderWLPanel(){
        const list = document.getElementById('wlList');
        if(!wl.length){
            list.innerHTML = '<div class="wl-empty"><h3>Empty</h3><p>Add movies to your watchlist!</p></div>';
            return;
        }
        list.innerHTML = wl.map(m=>`
    <div class="wl-item">
      <div class="wl-item-poster">${m.poster?`<img src="${m.poster}" alt="${m.title}">`:''}</div>
      <div class="wl-item-info">
        <div class="wl-item-title">${m.title}</div>
      </div>
      <button class="wl-item-rm" onclick="toggleWL('${m.id}','${m.title}','${m.poster}',null)">×</button>
    </div>
  `).join('');
    }

    function toggleWLPanel(){
        const panel   = document.getElementById('wlPanel');
        const overlay = document.getElementById('wlOverlay');
        const open    = panel.classList.toggle('open');
        overlay.style.display = open ? 'block' : 'none';
        if(open) renderWLPanel();
    }

    // ══════════════════════════════
    // MODAL
    // ══════════════════════════════
    function openModal(title,year,rating,genres,director,actors,desc,gross,duration,poster){
        const pills = genres.split(',').map(g=>`<span class="modal-tag">${g.trim()}</span>`).join('');
        const cast  = actors.split(',').slice(0,8).map(a=>a.trim()).join(', ');

        // Top backdrop
        const top = document.getElementById('modalTop');
        top.style.backgroundImage = poster ? `url(${poster})` : '';
        top.style.backgroundSize  = 'cover';
        top.style.backgroundPosition = 'center';

        // Poster
        document.getElementById('modalPosterWrap').innerHTML = poster
            ? `<img src="${poster}" alt="${title}" style="width:100%;height:100%;object-fit:cover">`
            : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue';font-size:36px;color:rgba(255,255,255,.1)">${title[0]}</div>`;

        document.getElementById('modalBody').innerHTML = `
    <div class="modal-ttl">${title}</div>
    <div class="modal-meta-row">
      <span>📅 ${year}</span>
      <span>⏱ ${duration} min</span>
      <span class="modal-rat">★ ${rating} / 10</span>
    </div>
    <div class="modal-tags">${pills}</div>
    <p class="modal-desc">${desc}</p>
    <div class="modal-info-grid">
      <div class="modal-info-box">
        <div class="modal-info-label">Director</div>
        <div class="modal-info-val">${director||'—'}</div>
      </div>
      <div class="modal-info-box">
        <div class="modal-info-label">Worldwide Gross</div>
        <div class="modal-info-val" style="color:var(--gold)">${gross}</div>
      </div>
    </div>
    <div class="modal-cast-box">
      <div class="modal-info-label">Cast</div>
      <div class="modal-cast-list">${cast||'—'}</div>
    </div>
    <div class="modal-btns">
      <button class="modal-btn-add" onclick="toggleWL('modal_${title.replace(/'/g,'`')}','${title.replace(/'/g,'`')}','${poster}',null);this.textContent='✓ Added';this.style.background='rgba(16,185,129,.8)';this.style.color='#fff'">
        + Add to Watchlist
      </button>
      <button class="modal-btn-close" onclick="closeModal()">Close</button>
    </div>
  `;
        document.getElementById('modalBg').classList.add('open');
        document.body.style.overflow='hidden';
    }

    function closeModal(){
        document.getElementById('modalBg').classList.remove('open');
        document.body.style.overflow='';
    }
    document.getElementById('modalBg').addEventListener('click',e=>{
        if(e.target===document.getElementById('modalBg')) closeModal();
    });
</script>
</body>
</html>
