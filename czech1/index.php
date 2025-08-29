<?php
// index.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

$WORDS_FILE  = __DIR__ . '/words.json';
$AUDIOS_FILE = dirname(__DIR__) . '/audios.json'; // one level up from czech1/

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function normalize_answer(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/^(the|a|an)\s+/u', '', $s);
    $s = trim($s, " \t\n\r\0\x0B.,;:!?()[]{}\"'");
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s ?? '';
}

// ---- load dictionary ----
if (!file_exists($WORDS_FILE)) {
    http_response_code(500);
    echo "<h1>Error</h1><p><code>words.json</code> not found.</p>";
    exit;
}
$json = file_get_contents($WORDS_FILE);
$dict = json_decode($json, true, flags: JSON_OBJECT_AS_ARRAY);
if (!is_array($dict) || empty($dict)) {
    http_response_code(500);
    echo "<h1>Error</h1><p>Could not read a valid dictionary from <code>words.json</code>.</p>";
    exit;
}

// load audio map
$audioMap = [];
if (file_exists($AUDIOS_FILE)) {
    $jsonAudios = file_get_contents($AUDIOS_FILE);
    $audioMap = json_decode($jsonAudios, true, flags: JSON_OBJECT_AS_ARRAY) ?? [];
}

// ---- parse URL params ok/nok ----
$okList  = isset($_GET['ok'])  ? json_decode($_GET['ok'], true)  : [];
$nokList = isset($_GET['nok']) ? json_decode($_GET['nok'], true) : [];
if (!is_array($okList))  $okList  = [];
if (!is_array($nokList)) $nokList = [];

// ---- pick/advance word ----
if (!isset($_SESSION['current_key']) || isset($_POST['next'])) {
    // candidate words = all - okList
    $keys = array_keys($dict);
    $candidates = array_values(array_diff($keys, $okList));

    if (count($candidates) > 0) {
        $_SESSION['current_key'] = $candidates[random_int(0, count($candidates) - 1)];
    } else {
        $_SESSION['current_key'] = null; // all done
    }

    $_SESSION['answered'] = false;
    $_SESSION['is_correct'] = null;
    $_SESSION['user_answer'] = '';
}
$currentKey = $_SESSION['current_key'];
$answers = $currentKey ? ($dict[$currentKey] ?? []) : [];

// ---- audio ----
$audioSrc = null;
if ($currentKey && isset($audioMap[$currentKey])) {
    $file = trim((string)$audioMap[$currentKey]);
    $audioSrc = '/audios/' . basename($file);
}

// ---- handle answer ----
if ($currentKey && isset($_POST['answer']) && $_SESSION['answered'] !== true) {
    $userAnswer = (string)($_POST['answer'] ?? '');
    $_SESSION['user_answer'] = $userAnswer;

    $normUser = normalize_answer($userAnswer);
    $isCorrect = false;
    foreach ($answers as $a) {
        if ($normUser !== '' && normalize_answer((string)$a) === $normUser) {
            $isCorrect = true;
            break;
        }
    }

    $_SESSION['answered'] = true;
    $_SESSION['is_correct'] = $isCorrect;

    if ($isCorrect) {
        if (!in_array($currentKey, $okList, true)) {
            $okList[] = $currentKey;
        }
        // if it was wrong before and now correct → remove from nok
        $nokList = array_values(array_diff($nokList, [$currentKey]));
    } else {
        if (!in_array($currentKey, $nokList, true)) {
            $nokList[] = $currentKey;
        }
    }

    // redirect com nova URL
    $qs = http_build_query([
        'ok'  => json_encode($okList, JSON_UNESCAPED_UNICODE),
        'nok' => json_encode($nokList, JSON_UNESCAPED_UNICODE)
    ]);
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $base?$qs");
    exit;
}

// ---- view state ----
$answered   = $_SESSION['answered'] ?? false;
$isCorrect  = $_SESSION['is_correct'] ?? null;
$userAnswer = $_SESSION['user_answer'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Czech → English Vocabulary Trainer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji"; }
    body { margin: 24px; }
    .card { max-width: 720px; margin: 0 auto; padding: 24px; border: 1px solid #ddd; border-radius: 16px; box-shadow: 0 3px 12px rgba(0,0,0,0.06); }
    h1 { margin-top: 0; font-size: 1.4rem; }
    .cz { font-size: 2rem; font-weight: 700; margin: 12px 0 4px; display: flex; align-items: center; gap: 10px; }
    .hint { color: #666; margin-bottom: 16px; }
    .row { display: flex; gap: 8px; }
    input[type="text"] { flex: 1; padding: 12px; font-size: 1rem; border-radius: 10px; border: 1px solid #ccc; }
    button { padding: 12px 16px; font-size: 1rem; border: 0; border-radius: 10px; cursor: pointer; }
    .primary { background: #111; color: #fff; }
    .result { margin-top: 16px; padding: 12px 14px; border-radius: 10px; }
    .ok { background: #e7f7ee; color: #0a7a3c; border: 1px solid #bde8cf; }
    .bad { background: #fdecec; color: #a12626; border: 1px solid #f5c2c2; }
    .answers { margin-top: 8px; color: #333; }
    .footer { margin-top: 20px; display: flex; gap: 8px; }
    .muted { color: #666; font-size: .9rem; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .chip { padding: 6px 10px; border-radius: 999px; background: #f1f1f1; font-size: .9rem; }
    .icon { padding: 6px 10px; line-height: 1; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Czech → English Vocabulary Trainer</h1>

    <?php if ($currentKey === null): ?>
      <p>🎉 You finished all words!</p>
        <a href="/" class="button">Back to home</a>
    <?php else: ?>
      <div class="cz">
        <?= h($currentKey) ?>
        <?php if ($audioSrc !== null): ?>
          <button type="button" class="icon" aria-label="Play pronunciation"
            onclick="(function(){var a=document.getElementById('cz-audio'); if(a){a.currentTime=0; a.play();}})()">🔊</button>
          <audio id="cz-audio" src="<?= h($audioSrc) ?>"></audio>
        <?php endif; ?>
      </div>
      <div class="hint muted">Type a valid English meaning.</div>

      <form method="post" class="row" autocomplete="off">
        <input type="text" name="answer" placeholder="Your meaning in English"
               value="<?= h($userAnswer) ?>" <?= $answered ? 'disabled' : '' ?>>
        <?php if (!$answered): ?>
          <button type="submit" class="primary">Check</button>
        <?php endif; ?>
      </form>

      <?php if ($answered): ?>
        <?php if ($isCorrect): ?>
          <div class="result ok">✅ Correct!</div>
        <?php else: ?>
          <div class="result bad">❌ Not quite. Try the next one.</div>
        <?php endif; ?>

        <div class="answers">
          <div class="muted">Accepted answers:</div>
          <div class="chips">
            <?php foreach ($answers as $a): ?>
              <span class="chip"><?= h($a) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <form method="post" class="footer">
          <button type="submit" name="next" value="1" class="primary">Next word</button>
        </form>
      <?php endif; ?>

    <?php
    $totalWords = count($dict);
    $correctCount = count($okList);
    ?>
    <div class="muted" style="margin-top:20px">
      📊 You got <?= $correctCount ?> / <?= $totalWords ?> words
    </div>

    <?php endif; ?>
  </div>
</body>
</html>
