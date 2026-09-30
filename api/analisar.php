<?php
/**
 * Analisador de site — V1.
 * Busca a página informada NO SERVIDOR (o navegador não consegue por CORS) e devolve um diagnóstico
 * básico de SEO técnico e conversão em JSON. Não é auditoria completa: o objetivo é gerar um lead qualificado.
 *
 * Segurança (SSRF): somente http/https, portas 80/443, DNS resolvido e validado (sem IP privado/reservado),
 * IP fixado na conexão (CURLOPT_RESOLVE) para evitar DNS rebinding, redirecionamentos seguidos manualmente
 * e revalidados, limite de tamanho e de tempo, limite de uso por IP.
 */
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';

const AN_MAX_BYTES = 3_000_000;
const AN_TIMEOUT = 12;
const AN_UA = 'Mozilla/5.0 (compatible; SitesBrasiliaAnalisador/1.0; +https://www.sitesbrasilia.com.br/analisar-site/)';

if (!function_exists('curl_init')) sb_json(503, ['ok' => false, 'error' => 'Analisador indisponível no momento.']);

$raw = trim((string)($_POST['url'] ?? $_GET['url'] ?? ''));
if ($raw === '' || strlen($raw) > 300) sb_json(422, ['ok' => false, 'error' => 'Informe o endereço do site.']);
if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;

// Limite de uso: 12 análises por IP por hora (quando houver banco configurado).
try {
    if (sb_config()) {
        if (!sb_rate_ok('analise', 12, 3600)) sb_json(429, ['ok' => false, 'error' => 'Limite de análises atingido. Tente novamente mais tarde ou fale com a gente.']);
        sb_rate_hit('analise');
    }
} catch (Throwable $e) { error_log('[sitesbrasilia] analisar rate: ' . $e->getMessage()); }

/** Valida URL e resolve o host para um IP público. Retorna [url normalizada, host, porta, ip] ou string de erro. */
function an_target(string $url) {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return 'Endereço inválido.';
    if (isset($p['user']) || isset($p['pass'])) return 'Endereço inválido.';
    $scheme = strtolower($p['scheme']);
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) return 'Só analisamos sites nas portas padrão (80/443).';
    $host = strtolower(rtrim($p['host'], '.'));
    if (filter_var($host, FILTER_VALIDATE_IP)) return 'Informe o domínio do site, não um endereço IP.';
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', idn_host($host))) return 'Domínio inválido.';
    $ips = an_resolve(idn_host($host));
    if (!$ips) return 'Não encontramos esse domínio. Confira se foi digitado corretamente.';
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'Endereço não permitido.';
    }
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    return [$scheme . '://' . $host . ($path === '' ? '/' : $path), $host, $port, $ips[0]];
}

/**
 * Resolve o host por DNS PÚBLICO (DNS-over-HTTPS do Google), com cache por requisição.
 * Motivo: dentro da hospedagem, domínios hospedados no mesmo provedor resolvem para IPs internos,
 * o que faria o analisador recusar sites legítimos. Conectamos sempre no IP público validado.
 * Se o DoH falhar, cai no resolvedor do sistema (e IPs privados continuam bloqueados).
 */
function an_resolve(string $host): array {
    static $cache = [];
    if (isset($cache[$host])) return $cache[$host];
    $ips = [];
    $ch = curl_init('https://dns.google/resolve?type=A&name=' . rawurlencode($host));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if (is_array($j) && ($j['Status'] ?? 1) === 0) {
        foreach ($j['Answer'] ?? [] as $a) {
            if (($a['type'] ?? 0) === 1 && filter_var($a['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[] = $a['data'];
        }
    }
    if (!$ips) $ips = @gethostbynamel($host) ?: [];
    return $cache[$host] = $ips;
}

function idn_host(string $h): string {
    return function_exists('idn_to_ascii') ? (idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $h) : $h;
}

/** Uma requisição sem seguir redirecionamento, com IP fixado. */
function an_request(string $url, string $method = 'GET', int $maxBytes = AN_MAX_BYTES) {
    $t = an_target($url);
    if (is_string($t)) return ['error' => $t];
    [$url, $host, $port, $ip] = $t;
    $body = '';
    $headers = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RESOLVE => [idn_host($host) . ":$port:$ip"],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => AN_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => AN_UA,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: pt-BR,pt;q=0.9'],
        CURLOPT_ENCODING => '',
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return strlen($body) > $maxBytes ? 0 : strlen($chunk); // aborta acima do limite
        },
    ]);
    curl_exec($ch);
    $err = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($err && $err !== CURLE_WRITE_ERROR) return ['error' => 'Não foi possível acessar o site (' . ($err === CURLE_OPERATION_TIMEDOUT ? 'tempo esgotado' : 'falha de conexão') . ').'];
    return ['url' => $url, 'status' => (int)$info['http_code'], 'headers' => $headers, 'body' => $body, 'time' => (float)$info['total_time'], 'ttfb' => (float)($info['starttransfer_time'] ?? 0), 'truncated' => $err === CURLE_WRITE_ERROR];
}

/** Segue até 5 redirecionamentos, revalidando cada destino. */
function an_fetch(string $url, string $method = 'GET', int $maxBytes = AN_MAX_BYTES): array {
    $chain = [];
    $total = 0.0;
    for ($i = 0; $i <= 5; $i++) {
        $r = an_request($url, $method, $maxBytes);
        if (isset($r['error'])) return $r + ['chain' => $chain];
        $total += $r['time'];
        $chain[] = ['url' => $r['url'], 'status' => $r['status']];
        if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
            $url = an_abs($r['headers']['location'], $r['url']);
            continue;
        }
        return $r + ['chain' => $chain, 'total_time' => $total];
    }
    return ['error' => 'Redirecionamentos demais.', 'chain' => $chain];
}

function an_abs(string $href, string $base): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    $b = parse_url($base);
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return $b['scheme'] . ':' . $href;
    if (str_starts_with($href, '/')) return $origin . $href;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $origin . $dir . $href;
}

function an_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen(utf8_decode($s)); }

// ───────────────────────── Análise ─────────────────────────
$page = an_fetch($raw);
if (isset($page['error'])) sb_json(422, ['ok' => false, 'error' => $page['error']]);

$final = $page['url'];
$fp = parse_url($final);
$origin = $fp['scheme'] . '://' . $fp['host'];
$checks = [];
$add = function (string $id, string $group, string $status, string $title, string $detail, int $weight) use (&$checks) {
    $checks[] = compact('id', 'group', 'status', 'title', 'detail', 'weight');
};

// Status HTTP
if ($page['status'] >= 200 && $page['status'] < 300) $add('status', 'Técnico', 'ok', 'Página responde normalmente', "Código HTTP {$page['status']}.", 10);
else { $add('status', 'Técnico', 'fail', 'A página não respondeu corretamente', "Código HTTP {$page['status']}. Visitantes e o Google podem não conseguir acessar.", 10); }

// HTTPS
$isHttps = $fp['scheme'] === 'https';
$add('https', 'Segurança', $isHttps ? 'ok' : 'fail', $isHttps ? 'Site usa HTTPS (cadeado)' : 'Site sem HTTPS', $isHttps ? 'A conexão é criptografada.' : 'Navegadores exibem "não seguro". Isso afasta visitantes e prejudica o Google.', 10);

// Redirecionamento http → https
if ($isHttps) {
    $h = an_fetch('http://' . $fp['host'] . '/', 'HEAD', 10000);
    $toHttps = !isset($h['error']) && str_starts_with(strtolower($h['url'] ?? ''), 'https://');
    $add('http_redirect', 'Segurança', $toHttps ? 'ok' : 'warn', $toHttps ? 'Versão sem HTTPS redireciona corretamente' : 'Versão http:// não redireciona para https://', $toHttps ? 'Quem digita o endereço sem https chega à versão segura.' : 'Pode gerar conteúdo duplicado e acessos à versão insegura.', 3);
}

// Tempo de resposta do servidor
$ttfb = $page['ttfb'] ?: $page['time'];
$ms = (int)round($ttfb * 1000);
$add('ttfb', 'Desempenho', $ttfb < 0.8 ? 'ok' : ($ttfb < 1.8 ? 'warn' : 'fail'), 'Tempo de resposta do servidor: ' . number_format($ms, 0, ',', '.') . ' ms',
    $ttfb < 0.8 ? 'O servidor responde rápido.' : 'O servidor demora para começar a entregar a página. Hospedagem ou cache podem ser a causa.', 6);

// Tamanho do HTML
$kb = (int)round(strlen($page['body']) / 1024);
$add('size', 'Desempenho', $kb < 300 ? 'ok' : ($kb < 1000 ? 'warn' : 'fail'), "HTML com {$kb} KB", $kb < 300 ? 'Tamanho adequado.' : 'HTML pesado: pode deixar o carregamento lento, principalmente no celular.', 3);

// Parse do HTML
$html = $page['body'];
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
$xp = new DOMXPath($dom);
$q = fn(string $x) => $xp->query($x);
$attr = function (string $x, string $a) use ($xp): ?string { $n = $xp->query($x); return $n && $n->length ? trim((string)$n->item(0)->getAttribute($a)) : null; };

// Title
$titleNode = $q('//title');
$title = $titleNode->length ? trim(preg_replace('/\s+/', ' ', $titleNode->item(0)->textContent)) : '';
$tl = an_len($title);
if ($tl === 0) $add('title', 'SEO', 'fail', 'Página sem título', 'O título é o principal texto que aparece no Google.', 8);
elseif ($tl < 20 || $tl > 70) $add('title', 'SEO', 'warn', "Título com {$tl} caracteres", "\"$title\". O ideal é entre 20 e 65 caracteres, descrevendo o serviço, não só o nome da empresa.", 8);
else $add('title', 'SEO', 'ok', 'Título com tamanho adequado', "\"$title\"", 8);

// Meta description
$desc = $attr('//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content') ?? '';
$dl = an_len($desc);
if ($dl === 0) $add('description', 'SEO', 'fail', 'Sem meta description', 'Sem ela, o Google escolhe um trecho qualquer da página para mostrar.', 5);
elseif ($dl < 70 || $dl > 170) $add('description', 'SEO', 'warn', "Meta description com {$dl} caracteres", 'O ideal fica entre 70 e 160 caracteres.', 5);
else $add('description', 'SEO', 'ok', 'Meta description adequada', $desc, 5);

// H1
$h1s = $q('//h1');
$h1n = $h1s->length;
$h1txt = $h1n ? trim(preg_replace('/\s+/', ' ', $h1s->item(0)->textContent)) : '';
if ($h1n === 0) $add('h1', 'SEO', 'fail', 'Página sem título principal (H1) no HTML', 'O H1 diz ao Google qual é o assunto principal da página.', 6);
elseif ($h1n > 1) $add('h1', 'SEO', 'warn', "{$h1n} títulos H1 na mesma página", 'Recomendado ter um único H1 claro.', 6);
else $add('h1', 'SEO', 'ok', 'Um título H1', "\"$h1txt\"", 6);

// Conteúdo no HTML (detecção de site dependente de JavaScript)
$bodyNode = $q('//body');
$text = '';
if ($bodyNode->length) {
    $clone = $bodyNode->item(0)->cloneNode(true);
    foreach (['script', 'style', 'noscript', 'svg', 'template'] as $tag) {
        foreach (iterator_to_array($clone->getElementsByTagName($tag)) as $n) $n->parentNode->removeChild($n);
    }
    $text = trim(preg_replace('/\s+/u', ' ', $clone->textContent));
}
$words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
$spaRoot = $q('//div[@id="root" or @id="app" or @id="__next"]')->length > 0;
if ($words < 80) $add('content', 'SEO', 'fail', $spaRoot ? 'Conteúdo depende de JavaScript' : 'Pouco texto no HTML', "O HTML entregue tem cerca de {$words} palavras." . ($spaRoot ? ' O conteúdo parece ser montado pelo JavaScript no navegador, o que atrasa ou prejudica a leitura pelo Google.' : ' Páginas com pouco conteúdo têm dificuldade para aparecer nas buscas.'), 10);
elseif ($words < 250) $add('content', 'SEO', 'warn', "Cerca de {$words} palavras no HTML", 'Conteúdo curto. Explicar melhor os serviços ajuda o Google e o cliente.', 10);
else $add('content', 'SEO', 'ok', "Conteúdo presente no HTML (cerca de {$words} palavras)", 'O Google consegue ler o texto sem depender de JavaScript.', 10);

// Canonical
$canonical = $attr('//link[translate(@rel,"CANONICAL","canonical")="canonical"]', 'href');
$add('canonical', 'SEO', $canonical ? 'ok' : 'warn', $canonical ? 'Canonical definido' : 'Sem link canonical', $canonical ? $canonical : 'O canonical indica ao Google qual é o endereço oficial da página e evita duplicidade.', 3);

// Meta robots
$robotsMeta = strtolower($attr('//meta[translate(@name,"ROBOTS","robots")="robots"]', 'content') ?? '');
$xRobots = strtolower($page['headers']['x-robots-tag'] ?? '');
if (str_contains($robotsMeta, 'noindex') || str_contains($xRobots, 'noindex')) $add('noindex', 'SEO', 'fail', 'Página bloqueada para o Google (noindex)', 'A página pede para não ser indexada. Ela não vai aparecer nas buscas.', 10);

// Viewport (mobile)
$viewport = $attr('//meta[translate(@name,"VIEWPORT","viewport")="viewport"]', 'content');
$add('viewport', 'Celular', $viewport ? 'ok' : 'fail', $viewport ? 'Configurado para celular (viewport)' : 'Sem configuração para celular', $viewport ? 'A página declara adaptação à tela do celular.' : 'Sem a meta viewport, a página aparece minúscula no celular.', 7);

// Idioma
$lang = $attr('//html', 'lang');
$add('lang', 'SEO', $lang ? 'ok' : 'warn', $lang ? "Idioma declarado ($lang)" : 'Idioma não declarado', $lang ? 'Ajuda navegadores e leitores de tela.' : 'Declare lang="pt-BR" na tag html.', 1);

// Open Graph
$ogTitle = $attr('//meta[@property="og:title"]', 'content');
$ogImage = $attr('//meta[@property="og:image"]', 'content');
$ogOk = $ogTitle && $ogImage;
$add('og', 'Compartilhamento', $ogOk ? 'ok' : 'warn', $ogOk ? 'Prévia para WhatsApp e redes configurada' : 'Prévia de compartilhamento incompleta', $ogOk ? 'Links compartilhados mostram título e imagem.' : 'Quando alguém compartilha o site no WhatsApp, a prévia pode sair sem imagem ou com o texto errado.', 3);

// Dados estruturados
$types = [];
foreach ($q('//script[@type="application/ld+json"]') as $s) {
    $j = json_decode($s->textContent, true);
    $walk = function ($n) use (&$walk, &$types) {
        if (!is_array($n)) return;
        if (isset($n['@type'])) foreach ((array)$n['@type'] as $t) $types[] = (string)$t;
        foreach ($n as $v) if (is_array($v)) $walk($v);
    };
    $walk($j);
}
$types = array_values(array_unique($types));
$add('schema', 'SEO', $types ? 'ok' : 'warn', $types ? 'Dados estruturados encontrados' : 'Sem dados estruturados (Schema.org)', $types ? implode(', ', array_slice($types, 0, 8)) : 'Dados estruturados ajudam o Google a entender a empresa (nome, serviço, região).', 3);

// Imagens sem alt
$imgs = $q('//img');
$noAlt = 0;
foreach ($imgs as $im) if (!$im->hasAttribute('alt')) $noAlt++;
if ($imgs->length) $add('alt', 'Acessibilidade', $noAlt === 0 ? 'ok' : 'warn', $noAlt === 0 ? 'Todas as imagens têm texto alternativo' : "{$noAlt} de {$imgs->length} imagens sem texto alternativo", $noAlt === 0 ? 'Bom para acessibilidade e SEO de imagens.' : 'O texto alternativo (alt) descreve a imagem para o Google e para leitores de tela.', 2);

// Conversão: WhatsApp, telefone, formulário, CTA
$hrefs = [];
foreach ($q('//a[@href]') as $a) $hrefs[] = strtolower($a->getAttribute('href'));
$hasWa = (bool)preg_grep('#(wa\.me/|api\.whatsapp\.com|whatsapp://|web\.whatsapp\.com)#', $hrefs);
$hasTel = (bool)preg_grep('#^tel:#', $hrefs);
$hasForm = $q('//form')->length > 0;
$ctaWords = '/(or[çc]amento|fale conosco|entre em contato|agende|agendar|solicite|comprar|contrat|whatsapp|fale com)/iu';
$hasCta = false;
foreach ($q('//a | //button') as $n) { if (preg_match($ctaWords, $n->textContent)) { $hasCta = true; break; } }
$add('whatsapp', 'Conversão', $hasWa ? 'ok' : 'warn', $hasWa ? 'Link de WhatsApp encontrado' : 'Nenhum link de WhatsApp no HTML', $hasWa ? 'Facilita o contato imediato pelo celular.' : 'Para muitas empresas, o WhatsApp é o principal canal de contato.', 5);
$add('contact', 'Conversão', ($hasForm || $hasTel) ? 'ok' : 'warn', ($hasForm || $hasTel) ? 'Formulário ou telefone presente' : 'Sem formulário nem telefone clicável', ($hasForm || $hasTel) ? 'O visitante tem mais de um caminho para falar com a empresa.' : 'Ofereça pelo menos um formulário ou telefone clicável.', 3);
$add('cta', 'Conversão', $hasCta ? 'ok' : 'warn', $hasCta ? 'Chamada para ação encontrada' : 'Sem chamada clara para ação', $hasCta ? 'Há botões ou links convidando ao contato.' : 'Botões como "Solicitar orçamento" orientam o visitante ao próximo passo.', 4);

// robots.txt e sitemap
$robots = an_fetch($origin . '/robots.txt', 'GET', 200000);
$robotsOk = !isset($robots['error']) && $robots['status'] === 200 && stripos($robots['headers']['content-type'] ?? 'text/plain', 'html') === false;
$blocksAll = $robotsOk && preg_match('/user-agent:\s*\*\s*(?:\r?\n(?!user-agent)[^\n]*)*?\r?\ndisallow:\s*\/\s*(\r?\n|$)/i', $robots['body']);
if ($blocksAll) $add('robots', 'SEO', 'fail', 'robots.txt bloqueia o site inteiro', 'A regra "Disallow: /" impede o Google de rastrear o site.', 10);
else $add('robots', 'SEO', $robotsOk ? 'ok' : 'warn', $robotsOk ? 'robots.txt encontrado' : 'robots.txt não encontrado', $robotsOk ? 'O arquivo de instruções para robôs existe.' : 'Não é obrigatório, mas ajuda a indicar o sitemap.', 1);

$sitemapUrl = null;
if ($robotsOk && preg_match('/^sitemap:\s*(\S+)/im', $robots['body'], $m)) $sitemapUrl = $m[1];
$sitemapUrl = $sitemapUrl ?: $origin . '/sitemap.xml';
$sm = an_fetch($sitemapUrl, 'GET', 2000000);
$smOk = !isset($sm['error']) && $sm['status'] === 200 && preg_match('/<(urlset|sitemapindex)\b/i', substr($sm['body'], 0, 5000));
$add('sitemap', 'SEO', $smOk ? 'ok' : 'warn', $smOk ? 'Sitemap encontrado' : 'Sitemap não encontrado', $smOk ? $sitemapUrl : 'O sitemap lista as páginas para o Google encontrar tudo mais rápido.', 3);

// Links internos quebrados (amostra de até 8)
$internal = [];
foreach ($hrefs as $h) {
    if ($h === '' || $h[0] === '#' || preg_match('#^(mailto|tel|javascript|whatsapp|data):#', $h)) continue;
    $abs = an_abs($h, $final);
    $ap = parse_url($abs);
    if (($ap['host'] ?? '') !== $fp['host']) continue;
    $abs = preg_replace('/#.*$/', '', $abs);
    if ($abs === $final) continue;
    $internal[$abs] = true;
    if (count($internal) >= 8) break;
}
$broken = [];
foreach (array_keys($internal) as $u) {
    $r = an_fetch($u, 'HEAD', 10000);
    if (!isset($r['error']) && in_array($r['status'], [405, 403], true)) $r = an_fetch($u, 'GET', 300000);
    if (isset($r['error']) || $r['status'] >= 400) $broken[] = $u . (isset($r['status']) ? " ({$r['status']})" : '');
}
if ($internal) $add('links', 'Técnico', $broken ? 'warn' : 'ok', $broken ? count($broken) . ' link(s) interno(s) com erro na amostra' : 'Links internos verificados sem erro', $broken ? implode("\n", $broken) : 'Verificamos ' . count($internal) . ' links internos da página.', 4);

// Score ponderado
$total = 0; $got = 0.0;
foreach ($checks as $c) { $total += $c['weight']; $got += $c['status'] === 'ok' ? $c['weight'] : ($c['status'] === 'warn' ? $c['weight'] * 0.5 : 0); }
$score = $total ? (int)round($got / $total * 100) : 0;
$count = ['fail' => 0, 'warn' => 0, 'ok' => 0];
foreach ($checks as $c) $count[$c['status']]++;
usort($checks, fn($a, $b) => [['fail' => 0, 'warn' => 1, 'ok' => 2][$a['status']], -$a['weight']] <=> [['fail' => 0, 'warn' => 1, 'ok' => 2][$b['status']], -$b['weight']]);
foreach ($checks as &$c) unset($c['weight']);
unset($c);

sb_json(200, [
    'ok' => true,
    'url' => $raw,
    'final_url' => $final,
    'redirects' => count($page['chain']) - 1,
    'score' => $score,
    'counts' => $count,
    'checks' => $checks,
    'analyzed_at' => gmdate('c'),
]);
