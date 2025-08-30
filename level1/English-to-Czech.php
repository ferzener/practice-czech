<?php
// /english1/index.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

$WORDS_FILE  = dirname(__DIR__) . '/level1/words.json'; // ajusta se seu words.json estiver em outro lugar
$AUDIOS_FILE = dirname(__DIR__) . '/audios.json';        // mesmo audios.json que você já usa

// ---------- Utilidades ----------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Normaliza strings "neutras" (case-insensitive, tira pontuação básica, espaços múltiplos)
function normalize_generic(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = trim($s, " \t\n\r\0\x0B.,;:!?()[]{}\"'");
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s ?? '';
}

// Remove marcações de gênero/observações entre parênteses do termo tcheco (ex.: "pes (m)" -> "pes")
function strip_parenthetical(string $cz): string {
    // remove qualquer " ( ... )" no final ou no meio
    $cz = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $cz);
    $cz = preg_replace('/\s+/u', ' ', trim($cz));
    return $cz ?? '';
}

// Carrega dicionário (cz -> [en...])
if (!file_exists($WORDS_FILE)) {
    http_response_code(500);
    echo "<h1>Error</h1><p><code>words.json</code> not found.</p>";
    exit;
}
$json = file_get_contents($WORDS_FILE);
$czToEn = json_decode($json, true, flags: JSON_OBJECT_AS_ARRAY);
if (!is_array($czToEn) || empty($czToEn)) {
    http_response_code(500);
    echo "<h1>Error</h1><p>Could not read a valid dictionary from <code>words.json</code>.</p>";
    exit;
}

// Carrega mapa de áudios (opcional)
$audioMap = [];
if (file_exists($AUDIOS_FILE)) {
    $jsonAudios = file_get_contents($AUDIOS_FILE);
    $audioMap = json_decode($jsonAudios, true, flags: JSON_OBJECT_AS_ARRAY) ?? [];
}

// ---------- Constrói índice invertido: EN -> [CZ... ] ----------
$enToCz = [];
foreach ($czToEn as $czWordOriginal => $enList) {
    if (!is_array($enList)) continue;
    foreach ($enList as $en) {
        $key = normalize_generic((string)$en);
        if ($key === '') continue;
        $enToCz[$key] ??= [];
        // Guarda a forma ORIGINAL do tcheco (para exibir, com gênero se houver)
        // e também uma forma normalizada para comparação
        $enToCz[$key][] = $czWordOriginal;
    }
}

// ---------- Sorteio / Avanço ----------
if (!isset($_SESSION['current_en']) || isset($_POST['next'])) {
    // pega uma chave EN aleatória
    $enKeys = array_keys($enToCz);
    if (empty($enKeys)) {
        http_response_code(500);
        echo "<h1>Error</h1><p>Empty inverted index (en → cz).</p>";
        exit;
    }
    $_SESSION['current_en']   = $enKeys[random_int(0, count($enKeys) - 1)];
    $_SESSION['answered']     = false;
    $_SESSION['is_correct']   = null;
    $_SESSION['user_answer']  = '';
    $_SESSION['matched_cz']   = null; // qual tcheco foi o que bateu (para áudio, se houver)
}

$currentEnKey = $_SESSION['current_en'];          // normalizado
$displayEnglish = $currentEnKey;                  // pode exibir "bonitinho"? Melhor tentar recuperar original
// Para exibir “bonitinho”, procure algum original que normalize igual:
$displayEnglish = (function($czToEn, $currentEnKey) {
    foreach ($czToEn as $cz => $ens) {
        foreach ($ens as $en) {
            if (normalize_generic((string)$en) === $currentEnKey) return (string)$en;
        }
    }
    return $currentEnKey;
})($czToEn, $currentEnKey);

// lista de possíveis respostas tchecas (originais, com gênero se existir)
$czCandidatesOriginal = $enToCz[$currentEnKey] ?? [];

// ---------- Submissão ----------
if (isset($_POST['answer']) && $_SESSION['answered'] !== true) {
    $userAnswer = (string)($_POST['answer'] ?? '');
    $_SESSION['user_answer'] = $userAnswer;

    $normUser = normalize_generic(strip_parenthetical($userAnswer));
    $isCorrect = false;
    $matchedCz = null;

    foreach ($czCandidatesOriginal as $czOrig) {
        $czNorm = normalize_generic(strip_parenthetical((string)$czOrig));
        if ($normUser !== '' && $czNorm === $normUser) {
            $isCorrect = true;
            $matchedCz = (string)$czOrig; // guarda o original que bateu
            break;
        }
    }

    $_SESSION['answered']   = true;
    $_SESSION['is_correct'] = $isCorrect;
    $_SESSION['matched_cz'] = $matchedCz;
}

// ---------- Áudio (se quiser tocar o tcheco que o usuário acertou) ----------
$audioSrc = null;
if (!empty($_SESSION['matched_cz'])) {
    $czForAudio = $_SESSION['matched_cz'];
    // tira observações entre parênteses para procurar no audios.json
    $lookup = strip_parenthetical($czForAudio);
    if (isset($audioMap[$lookup])) {
        $file = trim((string)$audioMap[$lookup]);
        $audioSrc = '/audios/' . basename($file);
    }
}

$answered   = $_SESSION['answered'] ?? false;
$isCorrect  = $_SESSION['is_correct'] ?? null;
$userAnswer = $_SESSION['user_answer'] ?? '';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>English → Czech Vocabulary Trainer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji"; }
    body { margin: 24px; }
    .card { max-width: 720px; margin: 0 auto; padding: 24px; border: 1px solid #ddd; border-radius: 16px; box-shadow: 0 3px 12px rgba(0,0,0,0.06); }
    h1 { margin-top: 0; font-size: 1.4rem; }
    .en { font-size: 2rem; font-weight: 700; margin: 12px 0 4px; display: flex; align-items: center; gap: 10px; }
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
    .chip { padding: 6px 10px; border-radius: 999px; background: #f1f1f1; font-size: .95rem; }
    .icon { padding: 6px 10px; line-height: 1; }
  </style>
</head>
<body>
  <div class="card">
    <h1>English → Czech Vocabulary Trainer</h1>

    <div class="en">
      <?= h($displayEnglish) ?>
    </div>
    <div class="hint muted">Type the Czech word (gender markers like “(m)/(f)/(n)” are optional).</div>

    <form method="post" class="row" autocomplete="off">
      <input type="text" name="answer" placeholder="Your word in Czech" value="<?= h($userAnswer) ?>" <?= $answered ? 'disabled' : '' ?>>
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
        <div class="muted">Accepted Czech answers:</div>
        <div class="chips">
          <?php foreach ($czCandidatesOriginal as $cz): ?>
            <span class="chip"><?= h($cz) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($isCorrect && $audioSrc): ?>
        <div style="margin-top:10px;">
          <button type="button" class="icon" aria-label="Play pronunciation"
            onclick="(function(){var a=document.getElementById('cz-audio'); if(a){a.currentTime=0; a.play();}})()">🔊 Hear Czech</button>
          <audio id="cz-audio" src="<?= h($audioSrc) ?>"></audio>
        </div>
      <?php endif; ?>

      <form method="post" class="footer">
        <button type="submit" name="next" value="1" class="primary">Next word</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- UX: Enter para enviar e após resposta, Enter aciona "Next word" -->
  <script>
    (function () {
      const form = document.querySelector('form.row');
      const nextBtn = document.querySelector('form.footer button[name="next"]');
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          if (form && !form.querySelector('input[disabled]')) return; // envia o "Check" padrão
          if (nextBtn) { e.preventDefault(); nextBtn.click(); }
        }
      });
    }());
  </script>
</body>
</html>
