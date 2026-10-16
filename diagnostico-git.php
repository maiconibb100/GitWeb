<?php
/**
 * Diagnostico de compatibilidade para Git/API em hospedagem Windows compartilhada.
 * Compatível com PHP 7.3+.
 *
 * Uso:
 *   1. Envie este arquivo para uma pasta protegida do site.
 *   2. Acesse-o pelo navegador.
 *   3. Analise ou salve o resultado.
 *   4. REMOVA o arquivo após o diagnóstico.
 */

declare(strict_types=1);

@set_time_limit(60);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function boolText($value): string
{
    return $value ? 'SIM' : 'NAO';
}

function statusClass($status): string
{
    if ($status === true || $status === 'OK' || $status === 'SIM') {
        return 'ok';
    }
    if ($status === false || $status === 'FALHA' || $status === 'NAO') {
        return 'bad';
    }
    return 'warn';
}

function addRow(array &$rows, string $item, $value, $status = null, string $detail = ''): void
{
    $rows[] = [
        'item' => $item,
        'value' => (string)$value,
        'status' => $status,
        'detail' => $detail,
    ];
}

function disabledFunctions(): array
{
    $raw = (string)ini_get('disable_functions');
    if (trim($raw) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function functionUsable(string $name, array $disabled): bool
{
    return function_exists($name) && !in_array($name, $disabled, true);
}

function testWriteDirectory(string $directory): array
{
    $result = [
        'directory' => $directory,
        'exists' => is_dir($directory),
        'is_writable' => false,
        'write_test' => false,
        'delete_test' => false,
        'bytes' => 0,
        'error' => '',
    ];

    if (!$result['exists']) {
        $result['error'] = 'Diretorio inexistente.';
        return $result;
    }

    $result['is_writable'] = is_writable($directory);
    $file = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '.webgit-write-test-' . bin2hex(random_bytes(6)) . '.tmp';
    $payload = 'webgit-write-test ' . date('c') . "\n";

    error_clear_last();
    $written = @file_put_contents($file, $payload, LOCK_EX);
    $lastError = error_get_last();

    if ($written === false) {
        $result['error'] = $lastError['message'] ?? 'file_put_contents retornou false.';
        return $result;
    }

    $result['write_test'] = true;
    $result['bytes'] = (int)$written;

    error_clear_last();
    $deleted = @unlink($file);
    $lastError = error_get_last();
    $result['delete_test'] = $deleted;

    if (!$deleted) {
        $result['error'] = $lastError['message'] ?? 'O arquivo foi criado, mas nao foi removido.';
    }

    return $result;
}

function curlRequest(string $url, bool $verifyPeer = true, ?string $caInfo = null): array
{
    $result = [
        'url' => $url,
        'verify_peer' => $verifyPeer,
        'http_code' => 0,
        'errno' => null,
        'error' => '',
        'total_time' => null,
        'connect_time' => null,
        'primary_ip' => '',
        'local_ip' => '',
        'ssl_verify_result' => null,
        'content_type' => '',
        'redirect_url' => '',
        'response_bytes' => 0,
        'headers' => '',
        'body_preview' => '',
    ];

    if (!function_exists('curl_init')) {
        $result['error'] = 'Extensao cURL indisponivel.';
        return $result;
    }

    $headers = [];
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'WebGit-Diagnostic/2.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        CURLOPT_SSL_VERIFYPEER => $verifyPeer,
        CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
            $headers[] = rtrim($line, "\r\n");
            return strlen($line);
        },
    ];

    if ($caInfo !== null && $caInfo !== '') {
        $options[CURLOPT_CAINFO] = $caInfo;
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);

    $result['errno'] = curl_errno($ch);
    $result['error'] = curl_error($ch);
    $result['http_code'] = (int)($info['http_code'] ?? 0);
    $result['total_time'] = $info['total_time'] ?? null;
    $result['connect_time'] = $info['connect_time'] ?? null;
    $result['primary_ip'] = (string)($info['primary_ip'] ?? '');
    $result['local_ip'] = (string)($info['local_ip'] ?? '');
    $result['ssl_verify_result'] = $info['ssl_verify_result'] ?? null;
    $result['content_type'] = (string)($info['content_type'] ?? '');
    $result['redirect_url'] = (string)($info['redirect_url'] ?? '');
    $result['headers'] = implode("\n", array_slice($headers, 0, 30));

    if (is_string($body)) {
        $result['response_bytes'] = strlen($body);
        $result['body_preview'] = substr($body, 0, 500);
    }

    curl_close($ch);
    return $result;
}

function detectGit(array $disabled): array
{
    $result = [
        'tested' => false,
        'found' => false,
        'output' => '',
        'error' => '',
    ];

    if (!functionUsable('exec', $disabled)) {
        $result['error'] = 'exec indisponivel ou desabilitado.';
        return $result;
    }

    $result['tested'] = true;
    $commands = DIRECTORY_SEPARATOR === '\\'
        ? ['where git 2>&1', 'git --version 2>&1']
        : ['command -v git 2>&1', 'git --version 2>&1'];

    foreach ($commands as $command) {
        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        $text = trim(implode("\n", $output));
        if ($code === 0 && $text !== '') {
            $result['found'] = true;
            $result['output'] .= ($result['output'] !== '' ? "\n" : '') . $text;
        } elseif ($text !== '') {
            $result['error'] .= ($result['error'] !== '' ? "\n" : '') . $text;
        }
    }

    return $result;
}

$disabled = disabledFunctions();
$general = [];

addRow($general, 'Data/hora do servidor', date('c'), null);
addRow($general, 'PHP', PHP_VERSION, version_compare(PHP_VERSION, '7.3.0', '>='), 'PHP 7.3 funciona para o diagnostico, mas esta fora de suporte e deve ser atualizado quando possivel.');
addRow($general, 'Sistema operacional', PHP_OS . ' / ' . php_uname('s') . ' ' . php_uname('r'), null);
addRow($general, 'SAPI', PHP_SAPI, null);
addRow($general, 'Arquitetura', PHP_INT_SIZE === 8 ? '64 bits' : '32 bits', null);
addRow($general, 'Diretorio do script', __DIR__, null);
addRow($general, 'Arquivo do script', __FILE__, null);
addRow($general, 'Document root', $_SERVER['DOCUMENT_ROOT'] ?? 'indisponivel', null);
addRow($general, 'Usuario informado pelo processo', get_current_user(), null, 'No Windows/IIS isto pode nao representar a identidade efetiva do Application Pool.');
addRow($general, 'open_basedir', ini_get('open_basedir') ?: 'nao configurado', null);
addRow($general, 'sys_temp_dir', ini_get('sys_temp_dir') ?: 'nao configurado', null);
addRow($general, 'upload_tmp_dir', ini_get('upload_tmp_dir') ?: 'nao configurado', null);
addRow($general, 'Diretorio temporario efetivo', sys_get_temp_dir(), null);
addRow($general, 'memory_limit', ini_get('memory_limit'), null);
addRow($general, 'max_execution_time', ini_get('max_execution_time'), null);
addRow($general, 'allow_url_fopen', boolText((bool)ini_get('allow_url_fopen')), (bool)ini_get('allow_url_fopen'));
addRow($general, 'disable_functions', $disabled ? implode(', ', $disabled) : 'nenhuma listada', null);

$extensions = [];
foreach (['curl', 'openssl', 'json', 'zip'] as $ext) {
    addRow($extensions, 'Extensao ' . $ext, boolText(extension_loaded($ext)), extension_loaded($ext));
}
addRow($extensions, 'ZipArchive', boolText(class_exists('ZipArchive')), class_exists('ZipArchive'));
foreach (['file_put_contents', 'curl_init', 'exec', 'shell_exec', 'proc_open'] as $fn) {
    addRow($extensions, 'Funcao ' . $fn, boolText(functionUsable($fn, $disabled)), functionUsable($fn, $disabled));
}

$ssl = [];
$curlVersion = function_exists('curl_version') ? curl_version() : [];
addRow($ssl, 'cURL version', $curlVersion['version'] ?? 'indisponivel', isset($curlVersion['version']));
addRow($ssl, 'Backend SSL do cURL', $curlVersion['ssl_version'] ?? 'indisponivel', isset($curlVersion['ssl_version']));
addRow($ssl, 'OpenSSL extension', defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'indisponivel', defined('OPENSSL_VERSION_TEXT'));

$iniCaFile = trim((string)ini_get('curl.cainfo'));
$iniOpenSslCaFile = trim((string)ini_get('openssl.cafile'));
$iniOpenSslCaPath = trim((string)ini_get('openssl.capath'));

addRow($ssl, 'curl.cainfo', $iniCaFile !== '' ? $iniCaFile : 'nao configurado', $iniCaFile !== '', $iniCaFile !== '' ? (is_file($iniCaFile) ? 'Arquivo encontrado.' : 'Caminho configurado, mas arquivo nao encontrado pelo PHP.') : 'Provavel causa do erro unable to get local issuer certificate em instalacoes PHP no Windows.');
addRow($ssl, 'curl.cainfo existe', $iniCaFile !== '' ? boolText(is_file($iniCaFile)) : 'N/A', $iniCaFile !== '' ? is_file($iniCaFile) : null);
addRow($ssl, 'curl.cainfo legivel', $iniCaFile !== '' ? boolText(is_readable($iniCaFile)) : 'N/A', $iniCaFile !== '' ? is_readable($iniCaFile) : null);
addRow($ssl, 'openssl.cafile', $iniOpenSslCaFile !== '' ? $iniOpenSslCaFile : 'nao configurado', $iniOpenSslCaFile !== '');
addRow($ssl, 'openssl.cafile existe', $iniOpenSslCaFile !== '' ? boolText(is_file($iniOpenSslCaFile)) : 'N/A', $iniOpenSslCaFile !== '' ? is_file($iniOpenSslCaFile) : null);
addRow($ssl, 'openssl.capath', $iniOpenSslCaPath !== '' ? $iniOpenSslCaPath : 'nao configurado', null);

$locations = [
    'Diretorio atual (__DIR__)' => __DIR__,
    'Diretorio pai' => dirname(__DIR__),
    'Temporario do PHP' => sys_get_temp_dir(),
];

$writeTests = [];
$seen = [];
foreach ($locations as $label => $directory) {
    $normalized = strtolower(rtrim($directory, '\\/'));
    if (isset($seen[$normalized])) {
        continue;
    }
    $seen[$normalized] = true;
    $entry = testWriteDirectory($directory);
    $entry['label'] = $label;
    $writeTests[] = $entry;
}

$dns = [];
foreach (['api.github.com', 'github.com', 'codeload.github.com'] as $host) {
    $ip = @gethostbyname($host);
    $resolved = ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP));
    addRow($dns, $host, $resolved ? $ip : 'nao resolvido', (bool)$resolved);
}

$githubVerified = curlRequest('https://api.github.com/rate_limit', true, $iniCaFile !== '' ? $iniCaFile : null);
$githubUnverified = null;
if ($githubVerified['http_code'] === 0 && $githubVerified['errno']) {
    // Teste diagnostico somente para diferenciar falha de CA de falha de rede.
    $githubUnverified = curlRequest('https://api.github.com/rate_limit', false, null);
}
$httpControl = curlRequest('http://example.com/', false, null);
$git = detectGit($disabled);

function renderRows(array $rows): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th>Resultado</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $class = statusClass($row['status']);
        echo '<tr>';
        echo '<td>' . h($row['item']) . '</td>';
        echo '<td class="' . h($class) . '"><code>' . h($row['value']) . '</code></td>';
        echo '<td>' . h($row['detail']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function renderCurlResult(string $title, array $result, bool $insecure = false): void
{
    $success = $result['http_code'] >= 200 && $result['http_code'] < 400 && (int)$result['errno'] === 0;
    echo '<article class="card">';
    echo '<h3>' . h($title) . '</h3>';
    if ($insecure) {
        echo '<p class="warning"><strong>ATENCAO:</strong> este teste desativa a validacao TLS apenas para diagnostico. Nao copie essa configuracao para a aplicacao.</p>';
    }
    $rows = [];
    addRow($rows, 'URL', $result['url'], null);
    addRow($rows, 'Validacao TLS', $result['verify_peer'] ? 'ATIVA' : 'DESATIVADA', $result['verify_peer'] ? true : 'AVISO');
    addRow($rows, 'HTTP status', (string)$result['http_code'], $success);
    addRow($rows, 'cURL errno', (string)$result['errno'], (int)$result['errno'] === 0);
    addRow($rows, 'Erro cURL', $result['error'] !== '' ? $result['error'] : 'nenhum', $result['error'] === '');
    addRow($rows, 'ssl_verify_result', (string)$result['ssl_verify_result'], ((int)$result['ssl_verify_result'] === 0));
    addRow($rows, 'IP remoto', $result['primary_ip'] ?: 'indisponivel', $result['primary_ip'] !== '');
    addRow($rows, 'IP local', $result['local_ip'] ?: 'indisponivel', null);
    addRow($rows, 'Tempo de conexao', $result['connect_time'] !== null ? $result['connect_time'] . ' s' : 'indisponivel', null);
    addRow($rows, 'Tempo total', $result['total_time'] !== null ? $result['total_time'] . ' s' : 'indisponivel', null);
    addRow($rows, 'Content-Type', $result['content_type'] ?: 'indisponivel', null);
    addRow($rows, 'Bytes recebidos', (string)$result['response_bytes'], null);
    renderRows($rows);
    if ($result['headers'] !== '') {
        echo '<details><summary>Cabecalhos HTTP</summary><pre>' . h($result['headers']) . '</pre></details>';
    }
    if ($result['body_preview'] !== '') {
        echo '<details><summary>Inicio da resposta</summary><pre>' . h($result['body_preview']) . '</pre></details>';
    }
    echo '</article>';
}

$probableDiagnosis = [];
if ($githubVerified['errno'] === 60 || stripos($githubVerified['error'], 'local issuer certificate') !== false) {
    $probableDiagnosis[] = 'A conexao alcança a camada TLS, mas o PHP/cURL nao consegue validar a cadeia do certificado. Verifique curl.cainfo e openssl.cafile no php.ini usado pelo site.';
}
if ($githubUnverified !== null && $githubUnverified['http_code'] >= 200 && $githubUnverified['http_code'] < 400) {
    $probableDiagnosis[] = 'O teste sem validacao TLS recebeu resposta HTTP. Isso confirma que DNS e saida HTTPS funcionam; a falha esta concentrada na cadeia de certificados/CA local.';
}
$dirCurrent = $writeTests[0] ?? null;
if ($dirCurrent && !$dirCurrent['write_test']) {
    $probableDiagnosis[] = 'O diretorio deste script nao permite gravacao para a identidade do PHP, apesar de outras pastas do site poderem permitir. WordPress funcionar nao prova que toda a arvore tenha a mesma ACL NTFS.';
}
if ($dirCurrent && !$dirCurrent['is_writable'] && $dirCurrent['write_test']) {
    $probableDiagnosis[] = 'is_writable() retornou NAO, mas a gravacao real funcionou. Neste ambiente, priorize o teste efetivo de criar/remover arquivo em vez de confiar apenas em is_writable().';
}
if (!$probableDiagnosis) {
    $probableDiagnosis[] = 'Nenhuma causa unica foi identificada automaticamente. Compare os testes de escrita, DNS, TLS verificado e TLS diagnostico.';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostico WebGit para PHP/Windows</title>
<style>
:root { color-scheme: light; --bg:#f4f7fb; --card:#fff; --text:#172033; --muted:#667085; --line:#d9e1ec; --ok:#067647; --bad:#b42318; --warn:#b54708; --blue:#175cd3; }
* { box-sizing: border-box; }
body { margin:0; font-family:Segoe UI, Arial, sans-serif; background:var(--bg); color:var(--text); line-height:1.45; }
main { max-width:1200px; margin:32px auto; padding:0 18px 48px; }
h1 { margin-bottom:6px; }
h2 { margin-top:34px; }
h3 { margin-top:0; }
.subtitle { color:var(--muted); margin-top:0; }
.banner, .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; margin:16px 0; box-shadow:0 2px 8px rgba(16,24,40,.04); }
.banner { border-left:5px solid var(--blue); }
.warning { padding:12px; background:#fff4e5; border-left:4px solid var(--warn); }
.ok { color:var(--ok); font-weight:700; }
.bad { color:var(--bad); font-weight:700; }
.warn { color:var(--warn); font-weight:700; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; background:var(--card); }
th, td { padding:10px 12px; border:1px solid var(--line); text-align:left; vertical-align:top; }
th { background:#eef3f9; }
code, pre { font-family:Consolas, monospace; white-space:pre-wrap; overflow-wrap:anywhere; }
pre { padding:12px; background:#101828; color:#e6edf7; border-radius:8px; overflow:auto; }
ul { padding-left:22px; }
details { margin-top:12px; }
footer { color:var(--muted); margin-top:30px; }
@media print { body { background:white; } .card, .banner { box-shadow:none; } }
</style>
</head>
<body>
<main>
    <h1>Diagnostico WebGit para PHP/Windows</h1>
    <p class="subtitle">Analise de escrita, extensoes, funcoes, DNS, HTTPS/TLS, CA e disponibilidade do Git.</p>

    <div class="banner">
        <strong>Seguranca:</strong> este arquivo revela caminhos e configuracoes do servidor. Restrinja o acesso e remova-o apos coletar o resultado. Nenhum token ou credencial Git e solicitado.
    </div>

    <h2>Diagnostico provavel</h2>
    <div class="card"><ul>
        <?php foreach ($probableDiagnosis as $diagnosis): ?>
            <li><?= h($diagnosis) ?></li>
        <?php endforeach; ?>
    </ul></div>

    <h2>Ambiente PHP</h2>
    <?php renderRows($general); ?>

    <h2>Extensoes e funcoes</h2>
    <?php renderRows($extensions); ?>

    <h2>Certificados e TLS</h2>
    <?php renderRows($ssl); ?>

    <h2>Testes reais de gravacao</h2>
    <div class="table-wrap"><table><thead><tr><th>Local</th><th>Diretorio</th><th>is_writable</th><th>Criou arquivo</th><th>Removeu arquivo</th><th>Erro</th></tr></thead><tbody>
    <?php foreach ($writeTests as $test): ?>
        <tr>
            <td><?= h($test['label']) ?></td>
            <td><code><?= h($test['directory']) ?></code></td>
            <td class="<?= h(statusClass($test['is_writable'])) ?>"><?= h(boolText($test['is_writable'])) ?></td>
            <td class="<?= h(statusClass($test['write_test'])) ?>"><?= h(boolText($test['write_test'])) ?><?= $test['write_test'] ? ' (' . h($test['bytes']) . ' bytes)' : '' ?></td>
            <td class="<?= h(statusClass($test['delete_test'])) ?>"><?= h(boolText($test['delete_test'])) ?></td>
            <td><?= h($test['error'] ?: 'nenhum') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <h2>Resolucao DNS</h2>
    <?php renderRows($dns); ?>

    <h2>Conectividade HTTP/HTTPS</h2>
    <?php renderCurlResult('GitHub API com validacao TLS', $githubVerified); ?>
    <?php if ($githubUnverified !== null): ?>
        <?php renderCurlResult('GitHub API sem validacao TLS, somente diagnostico', $githubUnverified, true); ?>
    <?php endif; ?>
    <?php renderCurlResult('Controle HTTP sem TLS', $httpControl); ?>

    <h2>Git no servidor</h2>
    <div class="card">
        <p><strong>Teste executado:</strong> <?= h(boolText($git['tested'])) ?></p>
        <p><strong>Git encontrado:</strong> <span class="<?= h(statusClass($git['found'])) ?>"><?= h(boolText($git['found'])) ?></span></p>
        <?php if ($git['output'] !== ''): ?><pre><?= h($git['output']) ?></pre><?php endif; ?>
        <?php if ($git['error'] !== ''): ?><pre><?= h($git['error']) ?></pre><?php endif; ?>
    </div>

    <h2>Interpretacao objetiva</h2>
    <div class="card">
        <ul>
            <li>Se o teste real de gravacao for <strong>SIM</strong>, a pasta pode ser usada mesmo que <code>is_writable()</code> seja inconsistente.</li>
            <li>Se HTTPS verificado falhar, mas HTTPS sem validacao responder, a rede esta funcional e falta corrigir o pacote CA no PHP.</li>
            <li>Nao use <code>CURLOPT_SSL_VERIFYPEER=false</code> na aplicacao final. Isso remove a autenticidade da conexao.</li>
            <li>Se o Git nao estiver instalado, ainda e possivel criar uma interface PHP baseada na API do GitHub, usando cURL, ZIP e permissao de escrita.</li>
        </ul>
    </div>

    <footer>Gerado em <?= h(date('c')) ?>. Remova este arquivo do servidor apos o diagnostico.</footer>
</main>
</body>
</html>

