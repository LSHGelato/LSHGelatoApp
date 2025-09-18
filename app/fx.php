<?php
declare(strict_types=1);

/**
 * FX helper functions for schema:
 *   exchange_rates(rate_date DATE, currency CHAR(3), rate_to_bwp DECIMAL(...))
 * Meaning: rate_to_bwp == "BWP per 1 <currency>"
 */

function fx_norm(string $c): string {
  return strtoupper(preg_replace('/[^A-Z]/', '', $c));
}

/** exact row for a currency on a given date */
function fx_exact(PDO $pdo, string $date, string $cur): ?float {
  $st = $pdo->prepare(
    "SELECT rate_to_bwp FROM exchange_rates WHERE currency=? AND rate_date=?"
  );
  $st->execute([fx_norm($cur), $date]);
  $v = $st->fetchColumn();
  return ($v === false) ? null : (float)$v;
}

/** nearest prior (<= date) row for a currency, returns [rate_date, rate_to_bwp] */
function fx_prior(PDO $pdo, string $date, string $cur): ?array {
  $st = $pdo->prepare(
    "SELECT rate_date, rate_to_bwp
       FROM exchange_rates
      WHERE currency=? AND rate_date<=?
      ORDER BY rate_date DESC
      LIMIT 1"
  );
  $st->execute([fx_norm($cur), $date]);
  $row = $st->fetch();
  if (!$row) return null;
  return ['rate_date' => $row['rate_date'], 'rate_to_bwp' => (float)$row['rate_to_bwp']];
}

/**
 * Public API (kept for compatibility with index.php)
 * All return ['rate' => float, 'source' => 'par'|'db-exact'|'db-prev']
 */

/** Exact match only */
function fx_get_cached(PDO $pdo, string $date, string $from, string $to): ?array {
  $from = fx_norm($from); $to = fx_norm($to);
  if ($from === $to) return ['rate'=>1.0, 'source'=>'par'];

  if ($to === 'BWP') {
    $r = fx_exact($pdo, $date, $from);
    return ($r !== null) ? ['rate'=>$r, 'source'=>'db-exact'] : null;
  }
  if ($from === 'BWP') {
    $r = fx_exact($pdo, $date, $to);
    return ($r !== null && $r > 0) ? ['rate'=>1.0/$r, 'source'=>'db-exact'] : null;
  }

  // cross via BWP
  $rFrom = fx_exact($pdo, $date, $from); // BWP per FROM
  $rTo   = fx_exact($pdo, $date, $to);   // BWP per TO
  if ($rFrom !== null && $rTo !== null && $rTo > 0) {
    return ['rate' => $rFrom / $rTo, 'source' => 'db-exact'];
  }
  return null;
}

/** Nearest prior (<= date) fallback */
function fx_get_manual_prev(PDO $pdo, string $date, string $from, string $to): ?array {
  $from = fx_norm($from); $to = fx_norm($to);
  if ($from === $to) return ['rate'=>1.0, 'source'=>'par'];

  if ($to === 'BWP') {
    $r = fx_prior($pdo, $date, $from);
    return $r ? ['rate'=>$r['rate_to_bwp'], 'source'=>'db-prev'] : null;
  }
  if ($from === 'BWP') {
    $r = fx_prior($pdo, $date, $to);
    return ($r && $r['rate_to_bwp']>0) ? ['rate'=>1.0/$r['rate_to_bwp'], 'source'=>'db-prev'] : null;
  }

  // cross via BWP
  $rFrom = fx_prior($pdo, $date, $from); // BWP per FROM
  $rTo   = fx_prior($pdo, $date, $to);   // BWP per TO
  if ($rFrom && $rTo && $rTo['rate_to_bwp'] > 0) {
    return ['rate' => $rFrom['rate_to_bwp'] / $rTo['rate_to_bwp'], 'source' => 'db-prev'];
  }
  return null;
}

/** Primary lookup that tries exact, then prior */
function fx_get_rate(PDO $pdo, string $date, string $from, string $to): ?array {
  if ($from === $to) return ['rate'=>1.0, 'source'=>'par'];
  $q = fx_get_cached($pdo, $date, $from, $to);
  if ($q) return $q;
  return fx_get_manual_prev($pdo, $date, $from, $to);
}

/**
 * Upserts. Kept for compatibility with index.php calls:
 *   fx_cache_upsert($pdo, $date, $from, 'BWP', $rate, $source)
 *   fx_upsert_manual($pdo, $date, $from, 'BWP', $rate)
 * We only store rows of the form (rate_date, currency, rate_to_bwp).
 */

function fx_upsert_row(PDO $pdo, string $date, string $currency, float $rate_to_bwp): void {
  // BWP must be exactly 1.0 to avoid nonsense, but we still store it if you want.
  if (fx_norm($currency) === 'BWP') $rate_to_bwp = 1.0;

  $st = $pdo->prepare(
    "INSERT INTO exchange_rates (rate_date, currency, rate_to_bwp)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE rate_to_bwp = VALUES(rate_to_bwp)"
  );
  $st->execute([$date, fx_norm($currency), $rate_to_bwp]);
}

/** compatibility: ignore $to and $source; just store FROM→BWP (or reciprocal if FROM is BWP) */
function fx_cache_upsert(PDO $pdo, string $date, string $from, string $to, float $rate, string $source='db'): void {
  $from = fx_norm($from); $to = fx_norm($to);
  if ($from === $to) return;
  if ($to === 'BWP') {
    fx_upsert_row($pdo, $date, $from, $rate);
  } elseif ($from === 'BWP') {
    if ($rate > 0) fx_upsert_row($pdo, $date, $to, 1.0/$rate);
  } else {
    // If someone calls this for non-BWP pairs, store only FROM→BWP if TO has a prior we can combine with later.
    fx_upsert_row($pdo, $date, $from, $rate); // treat as BWP per FROM
  }
}

/** compatibility: same behavior as cache_upsert, just a named variant */
function fx_upsert_manual(PDO $pdo, string $date, string $from, string $to, float $rate): void {
  fx_cache_upsert($pdo, $date, $from, $to, $rate, 'manual');
}
