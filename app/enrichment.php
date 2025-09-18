<?php
/**
 * enrichment.php — OFF-first with stricter matching
 * - Normalizes the requested ingredient name (drops trailing qualifiers after comma).
 * - Token overlap + synonyms + blacklist to avoid wildly wrong matches (e.g., bread for "sugar, white").
 * - Confidence 0..100 based on name similarity + hints; baseline removed.
 * - Auto-apply handled by caller (index.php route) using threshold (e.g., ≥80).
 */

function http_get_json(string $url, array $headers = [], int $timeout = 12): ?array {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERAGENT => 'LSH-Gelato/1.1 (+server)',
  ]);
  $out = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if (!$out || $code >= 400) return null;
  $json = json_decode($out, true);
  return is_array($json) ? $json : null;
}

function norm(string $s): string {
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

function base_name(string $name): string {
  // "sugar, white" -> "sugar"; "raspberries, frozen" -> "raspberries"
  $parts = explode(',', $name, 2);
  return trim($parts[0]);
}

function tokens(string $s): array {
  $s = norm($s);
  $parts = array_values(array_filter(explode(' ', $s), function($w) {
    return mb_strlen($w, 'UTF-8') >= 3; // drop "a", "of", etc.
  }));
  return array_values(array_unique($parts));
}

function synonyms_for(string $basename): array {
  $map = [
    'sugar' => ['sugar','white sugar','granulated','sucrose','caster','castor'],
    'salt'  => ['salt','table salt','sodium chloride'],
    'milk'  => ['milk','whole milk','full cream milk'],
    'cream' => ['cream','heavy cream','whipping cream'],
    'butter'=> ['butter','unsalted butter','sweet cream butter'],
    'flour' => ['flour','wheat flour','all purpose flour','plain flour'],
  ];
  $key = norm($basename);
  foreach ($map as $k => $syns) {
    if (strpos($key, $k) !== false) return $syns;
  }
  return [$basename];
}

function blacklist_tokens(): array {
  return ['bread','batch','roll','sauce','cereal','bar','drink','yogurt','chips','biscuit','cookie','cake','soup'];
}

function jaccard(array $a, array $b): float {
  $a = array_unique($a); $b = array_unique($b);
  $inter = array_intersect($a,$b);
  $union = array_unique(array_merge($a,$b));
  if (count($union) === 0) return 0.0;
  return count($inter) / count($union);
}

function compute_solids_from_nutriments(array $n): ?float {
  $carb = isset($n['carbohydrates_100g']) ? (float)$n['carbohydrates_100g'] : null;
  $prot = isset($n['proteins_100g']) ? (float)$n['proteins_100g'] : null;
  $fat  = isset($n['fat_100g']) ? (float)$n['fat_100g'] : null;
  $fib  = isset($n['fiber_100g']) ? (float)$n['fiber_100g'] : 0.0;
  $salt = isset($n['salt_100g']) ? (float)$n['salt_100g'] : 0.0;
  $ash  = $salt * 2.5;
  $parts = array_filter([$carb, $prot, $fat], fn($v) => $v !== null);
  if (empty($parts)) return null;
  $sum = (float)$carb + (float)$prot + (float)$fat + (float)$fib + (float)$ash;
  return max(0.0, min(100.0, $sum));
}

function off_search_best(string $name, string $cc='ZA', string $lang='en'): ?array {
  $q = urlencode($name);
  $fields = urlencode('product_name,generic_name,brands,ingredients_text,allergens_tags,categories_tags,nutriments,code,countries_tags,lang');
  $url = "https://world.openfoodfacts.org/cgi/search.pl?search_simple=1&action=process&json=1&fields={$fields}&page_size=10&search_terms={$q}";
  $res = http_get_json($url);
  if (!$res || empty($res['products'])) return null;

  $base = base_name($name);
  $expected = tokens($base);              // e.g., ["sugar"]
  $syns = tokens(implode(' ', synonyms_for($base)));
  $black = blacklist_tokens();

  $best = null; $bestScore = -1;
  foreach ($res['products'] as $p) {
    $pn = norm($p['product_name'] ?? '');
    $gn = norm($p['generic_name'] ?? '');
    $cat = array_map('norm', $p['categories_tags'] ?? []);

    // Quick reject: blacklist words in product_name
    foreach ($black as $blk) {
      if (strpos($pn, $blk) !== false) { continue 2; }
    }

    $candTokens = array_merge(tokens($pn), tokens($gn));
    $jac = jaccard($syns, $candTokens);         // 0..1
    $score = (int)round($jac * 80);             // up to 80 from name similarity

    // Boost if category hints align (e.g., contains "sugars")
    foreach ($cat as $t) {
      if (strpos($t, 'sugar') !== false) { $score += 15; break; }
    }

    // Country/lang tiny boosts
    if (!empty($p['countries_tags']) && in_array('en:'.strtolower($cc), $p['countries_tags'], true)) $score += 2;
    if (!empty($p['lang']) && strtolower($p['lang']) === strtolower($lang)) $score += 3;

    if ($score > $bestScore) { $bestScore = $score; $best = $p; }
  }

  if (!$best) return null;
  $best['_score'] = max(0, min(100, $bestScore));
  return $best;
}

function off_enrich(string $name, string $cc='ZA', string $lang='en'): ?array {
  $p = off_search_best($name, $cc, $lang);
  if (!$p) return null;
  $n = $p['nutriments'] ?? [];
  $fat = isset($n['fat_100g']) ? (float)$n['fat_100g'] : null;
  $sug = isset($n['sugars_100g']) ? (float)$n['sugars_100g'] : null;
  $solids = compute_solids_from_nutriments($n);
  $conf = (int)($p['_score'] ?? 0);
  $allergens = [];
  if (!empty($p['allergens_tags']) && is_array($p['allergens_tags'])) {
    foreach ($p['allergens_tags'] as $t) { $allergens[] = preg_replace('/^en:/', '', $t); }
  }
  return [
    'source' => 'OFF',
    'confidence' => $conf,
    'values' => [
      'solids_pct' => $solids,
      'fat_pct'    => $fat,
      'sugar_pct'  => $sug,
      'allergens'  => $allergens,
    ],
    'meta' => [
      'product_name' => $p['product_name'] ?? null,
      'generic_name' => $p['generic_name'] ?? null,
      'brands'       => $p['brands'] ?? null,
      'code'         => $p['code'] ?? null,
      'categories'   => $p['categories_tags'] ?? null,
    ]
  ];
}

function fdc_enrich(string $name, string $apiKey): ?array {
  if (!$apiKey) return null;
  $q = urlencode(base_name($name));
  $url = "https://api.nal.usda.gov/fdc/v1/foods/search?query={$q}&pageSize=5&api_key={$apiKey}";
  $res = http_get_json($url);
  if (!$res || empty($res['foods'])) return null;
  $best = $res['foods'][0];
  $nut = [];
  foreach ($best['foodNutrients'] ?? [] as $fn) {
    $n = strtolower($fn['nutrientName'] ?? '');
    $v = isset($fn['value']) ? (float)$fn['value'] : null;
    if ($v === null) continue;
    if (strpos($n, 'carbohydrate') !== false) $nut['carbohydrates_100g'] = $v;
    if ($n === 'sugars, total including nlea') $nut['sugars_100g'] = $v;
    if (strpos($n, 'protein') !== false) $nut['proteins_100g'] = $v;
    if (strpos($n, 'total lipid') !== false or strpos($n, 'fat') !== false) $nut['fat_100g'] = $v;
    if (strpos($n, 'fiber') !== false) $nut['fiber_100g'] = $v;
    if ($n === 'sodium, na') $nut['salt_100g'] = $v * 2.54 / 1000.0;
  }
  $fat = $nut['fat_100g'] ?? null;
  $sug = $nut['sugars_100g'] ?? null;
  $solids = compute_solids_from_nutriments($nut);
  return [
    'source' => 'FDC',
    'confidence' => 70,
    'values' => [
      'solids_pct' => $solids,
      'fat_pct'    => $fat,
      'sugar_pct'  => $sug,
      'allergens'  => [],
    ],
    'meta' => [
      'fdcId' => $best['fdcId'] ?? null,
      'description' => $best['description'] ?? null,
    ]
  ];
}

function enrich_one(PDO $pdo, int $ingredient_id, int $user_id, bool $auto_apply=true, int $threshold=80): array {
  $stmt = $pdo->prepare("SELECT id, name FROM ingredients WHERE id=?");
  $stmt->execute([$ingredient_id]);
  $ing = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$ing) throw new RuntimeException("Ingredient not found: $ingredient_id");
  $name = $ing['name'];

  $cc   = $_ENV['OFF_CC']   ?? 'ZA';
  $lang = $_ENV['OFF_LANG'] ?? 'en';
  $fdc  = $_ENV['FDC_API_KEY'] ?? '';

  $res = off_enrich($name, $cc, $lang);
  if (!$res && $fdc) $res = fdc_enrich($name, $fdc);
  if (!$res) return ['source'=>'NONE','confidence'=>0,'values'=>[],'meta'=>['reason'=>'no_match']];

  $solids = isset($res['values']['solids_pct']) ? round((float)$res['values']['solids_pct'], 3) : null;
  $fat    = isset($res['values']['fat_pct'])    ? round((float)$res['values']['fat_pct'], 3)    : null;
  $sugar  = isset($res['values']['sugar_pct'])  ? round((float)$res['values']['sugar_pct'], 3)  : null;
  $conf   = (int)$res['confidence'];
  $meta   = json_encode($res['meta'] ?? [], JSON_UNESCAPED_UNICODE);
  $res['_auto_applied'] = false;

  // stricter: if confidence < 60, do NOT auto-apply even if threshold lower
  if ($auto_apply && $conf >= max($threshold, 60)) {
    $q = $pdo->prepare("UPDATE ingredients
       SET solids_pct = COALESCE(?, solids_pct),
           fat_pct    = COALESCE(?, fat_pct),
           sugar_pct  = COALESCE(?, sugar_pct),
           nutrition_source = ?,
           nutrition_confidence = ?,
           nutrition_meta = ?
       WHERE id=?");
    $q->execute([$solids, $fat, $sugar, $res['source'], $conf, $meta, $ingredient_id]);
    $res['_auto_applied'] = true;
  }
  return $res;
}
