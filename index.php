<?php
// ─────────────────────────────────────────────────────────────────────────────
// Word Editor — editor de documente (docx / doc / odt / pdf) cu toolbar tip Word.
// Inspirat din "index V.4.php" (Mini Dreamweaver), dar dedicat fisierelor office.
//   • docx  → deschis client-side cu mammoth.js  → editabil → salvat cu html-docx-js
//   • odt   → convertit server-side (zip + content.xml) → editabil → salvat ca .docx
//   • pdf   → randat cu pdf.js; text extras pentru editare → salvat ca .docx
//   • doc   → binar vechi: doar avertisment (recomanda conversie in .docx)
// ─────────────────────────────────────────────────────────────────────────────

$ROOT = 'e:/Carte/'; // folder de lucru implicit (poti deschide si dupa cale absoluta)
mb_internal_encoding('UTF-8');

$ALLOWED_EXT = ['docx', 'doc', 'odt', 'pdf'];

function resolve_path($p, $ROOT)
{
    $p = str_replace("\\", "/", $p);
    if (preg_match('#^[a-zA-Z]:/#', $p) || strpos($p, '/') === 0) {
        return $p;
    }
    return rtrim($ROOT, '/') . '/' . ltrim($p, '/');
}

// ─── DOCX → HTML (server-side, rapid): word/document.xml + rels (imagini/linkuri) ──
// Mai rapid si mai sigur decat mammoth.js in browser (care avea ~18s/document).
const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

function docx_to_html($path)
{
    if (!class_exists('ZipArchive'))
        return false;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true)
        return false;
    // semnatura OLE2 = .doc vechi redenumit .docx → nu e zip OOXML
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        return ['ole2' => true];
    }
    // relationships: imagini + hyperlink-uri externe
    $rels = [];
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml !== false) {
        $rd = new DOMDocument();
        libxml_use_internal_errors(true);
        $rd->loadXML($relsXml);
        libxml_clear_errors();
        foreach ($rd->getElementsByTagName('Relationship') as $r) {
            $rels[$r->getAttribute('Id')] = ['target' => $r->getAttribute('Target'), 'mode' => $r->getAttribute('TargetMode')];
        }
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();
    $body = $dom->getElementsByTagNameNS(W_NS, 'body')->item(0);
    if (!$body) {
        $zip->close();
        return '';
    }

    $getImage = function ($rid) use (&$rels, $zip) {
        if (!isset($rels[$rid]))
            return '';
        $t = ltrim($rels[$rid]['target'], '/');
        $cand = strpos($t, 'word/') === 0 ? $t : 'word/' . $t;
        $data = $zip->getFromName($cand);
        if ($data === false)
            $data = $zip->getFromName($t);
        if ($data === false)
            return '';
        $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : ($ext === 'svg' ? 'image/svg+xml' : 'image/jpeg'));
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    };

    // child(el, localName) — primul copil cu acest localName in W_NS
    $child = function ($el, $name) {
        if (!$el)
            return null;
        foreach ($el->childNodes as $c)
            if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === $name && $c->namespaceURI === W_NS)
                return $c;
        return null;
    };
    $wval = function ($el) {
        return $el ? $el->getAttributeNS(W_NS, 'val') : '';
    };

    // randeaza un run (w:r) → text formatat
    $renderRun = function ($r) use ($child, $wval, $getImage) {
        $rPr = $child($r, 'rPr');
        $pre = '';
        $post = '';
        if ($rPr) {
            if ($child($rPr, 'b') && $wval($child($rPr, 'b')) !== '0' && $wval($child($rPr, 'b')) !== 'false') { $pre .= '<strong>'; $post = '</strong>' . $post; }
            if ($child($rPr, 'i') && $wval($child($rPr, 'i')) !== '0' && $wval($child($rPr, 'i')) !== 'false') { $pre .= '<em>'; $post = '</em>' . $post; }
            if ($child($rPr, 'u') && $wval($child($rPr, 'u')) !== 'none' && $wval($child($rPr, 'u')) !== '') { $pre .= '<u>'; $post = '</u>' . $post; }
            if ($child($rPr, 'strike')) { $pre .= '<s>'; $post = '</s>' . $post; }
            $va = $wval($child($rPr, 'vertAlign'));
            if ($va === 'superscript') { $pre .= '<sup>'; $post = '</sup>' . $post; }
            elseif ($va === 'subscript') { $pre .= '<sub>'; $post = '</sub>' . $post; }
            $css = '';
            $color = $wval($child($rPr, 'color'));
            if ($color && $color !== 'auto')
                $css .= 'color:#' . ltrim($color, '#') . ';';
            $sz = $wval($child($rPr, 'sz'));
            if ($sz)
                $css .= 'font-size:' . ((int) $sz / 2) . 'pt;';
            $hl = $wval($child($rPr, 'highlight'));
            if ($hl && $hl !== 'none')
                $css .= 'background-color:' . $hl . ';';
            if ($css) { $pre .= '<span style="' . $css . '">'; $post = '</span>' . $post; }
        }
        $inner = '';
        foreach ($r->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE)
                continue;
            $ln = $c->localName;
            if ($ln === 't')
                $inner .= htmlspecialchars($c->textContent);
            elseif ($ln === 'br')
                $inner .= '<br>';
            elseif ($ln === 'tab')
                $inner .= '&nbsp;&nbsp;&nbsp;&nbsp;';
            elseif ($ln === 'drawing') {
                $blips = $c->getElementsByTagNameNS(A_NS, 'blip');
                if ($blips->length) {
                    $rid = $blips->item(0)->getAttributeNS(R_NS, 'embed');
                    $src = $getImage($rid);
                    if ($src)
                        $inner .= '<img src="' . $src . '" style="max-width:100%">';
                }
            }
        }
        return $inner === '' ? '' : $pre . $inner . $post;
    };

    // randeaza un paragraf (w:p) → ['list'=>bool, 'tag'=>.., 'attrs'=>.., 'inner'=>..]
    $renderPara = function ($p) use ($child, $wval, $renderRun, &$rels) {
        $pPr = $child($p, 'pPr');
        $tag = 'p';
        $attrs = '';
        $isList = false;
        if ($pPr) {
            $style = strtolower($wval($child($pPr, 'pStyle')));
            if (preg_match('/heading\s*([1-6])|titlu\s*([1-6])/', $style, $m)) {
                $lvl = $m[1] ?: $m[2];
                $tag = 'h' . $lvl;
            } elseif ($style === 'title') {
                $tag = 'h1';
            }
            if ($child($pPr, 'numPr'))
                $isList = true;
            $jc = $wval($child($pPr, 'jc'));
            if ($jc) {
                $al = $jc === 'both' ? 'justify' : $jc;
                $attrs = ' style="text-align:' . $al . '"';
            }
        }
        $inner = '';
        foreach ($p->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE)
                continue;
            if ($c->localName === 'r')
                $inner .= $renderRun($c);
            elseif ($c->localName === 'hyperlink') {
                $rid = $c->getAttributeNS(R_NS, 'id');
                $txt = '';
                foreach ($c->childNodes as $hc)
                    if ($hc->nodeType === XML_ELEMENT_NODE && $hc->localName === 'r')
                        $txt .= $renderRun($hc);
                $href = isset($rels[$rid]) ? $rels[$rid]['target'] : '';
                $inner .= $href ? '<a href="' . htmlspecialchars($href) . '">' . $txt . '</a>' : $txt;
            }
        }
        return ['list' => $isList, 'tag' => $tag, 'attrs' => $attrs, 'inner' => $inner];
    };

    // randeaza blocuri (paragrafe + tabele), grupand listele
    $renderBlocks = function ($parent) use (&$renderBlocks, $child, $renderPara) {
        $out = '';
        $listOpen = false;
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE)
                continue;
            if ($node->localName === 'p') {
                $pr = $renderPara($node);
                if ($pr['list']) {
                    if (!$listOpen) { $out .= '<ul>'; $listOpen = true; }
                    $out .= '<li>' . ($pr['inner'] ?: '&nbsp;') . '</li>';
                } else {
                    if ($listOpen) { $out .= '</ul>'; $listOpen = false; }
                    $out .= '<' . $pr['tag'] . $pr['attrs'] . '>' . ($pr['inner'] === '' ? '<br>' : $pr['inner']) . '</' . $pr['tag'] . '>';
                }
            } elseif ($node->localName === 'tbl') {
                if ($listOpen) { $out .= '</ul>'; $listOpen = false; }
                $out .= '<table border="1" style="border-collapse:collapse;width:100%">';
                foreach ($node->childNodes as $tr) {
                    if ($tr->nodeType !== XML_ELEMENT_NODE || $tr->localName !== 'tr')
                        continue;
                    $out .= '<tr>';
                    foreach ($tr->childNodes as $tc) {
                        if ($tc->nodeType !== XML_ELEMENT_NODE || $tc->localName !== 'tc')
                            continue;
                        $out .= '<td style="border:1px solid #999;padding:4px">' . $renderBlocks($tc) . '</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table>';
            }
        }
        if ($listOpen)
            $out .= '</ul>';
        return $out;
    };

    $html = $renderBlocks($body);

    // Fișierele salvate de acest editor (html-docx-js) folosesc <w:altChunk> → conținutul real
    // e HTML quoted-printable în word/afchunk.mht, nu în w:p. Îl extragem ca să se redeschidă corect.
    if (trim(strip_tags($html)) === '' && strpos($xml, 'altChunk') !== false) {
        $mht = $zip->getFromName('word/afchunk.mht');
        if ($mht !== false) {
            $pos = stripos($mht, '<!doctype');
            if ($pos === false) $pos = stripos($mht, '<html');
            if ($pos !== false) $mht = substr($mht, $pos);
            $decoded = quoted_printable_decode($mht);
            if (preg_match('#<body[^>]*>(.*)</body>#is', $decoded, $mm)) $html = $mm[1];
            elseif (trim($decoded) !== '') $html = $decoded;
        }
    }

    $zip->close();
    return $html;
}

// ─── ODT → HTML (minimal): citeste content.xml din arhiva zip si mapeaza ─────────
function odt_to_html($path)
{
    if (!class_exists('ZipArchive'))
        return false;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true)
        return false;
    $xml = $zip->getFromName('content.xml');
    $zip->close();
    if ($xml === false)
        return false;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();

    $body = $dom->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'body')->item(0);
    if (!$body)
        return '';

    $textNs = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    $out = '';
    $walk = function ($node) use (&$walk, $textNs) {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $html .= htmlspecialchars($child->nodeValue);
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $name = $child->localName;
                if ($name === 'h') {
                    $lvl = (int) $child->getAttributeNS($textNs, 'outline-level');
                    $lvl = $lvl >= 1 && $lvl <= 6 ? $lvl : 2;
                    $html .= "<h$lvl>" . $walk($child) . "</h$lvl>";
                } elseif ($name === 'p') {
                    $inner = $walk($child);
                    $html .= '<p>' . ($inner === '' ? '<br>' : $inner) . '</p>';
                } elseif ($name === 'span') {
                    $html .= $walk($child);
                } elseif ($name === 'list') {
                    $html .= '<ul>' . $walk($child) . '</ul>';
                } elseif ($name === 'list-item') {
                    $html .= '<li>' . $walk($child) . '</li>';
                } elseif ($name === 's') {
                    $html .= ' ';
                } elseif ($name === 'tab') {
                    $html .= '&nbsp;&nbsp;&nbsp;&nbsp;';
                } elseif ($name === 'line-break') {
                    $html .= '<br>';
                } elseif ($name === 'a') {
                    $href = $child->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                    $html .= '<a href="' . htmlspecialchars($href) . '">' . $walk($child) . '</a>';
                } else {
                    $html .= $walk($child);
                }
            }
        }
        return $html;
    };
    return $walk($body);
}

// ─── API ─────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Stream raw binary (pentru mammoth.js / pdf.js)
    if ($action === 'raw') {
        $file = isset($_GET['file']) ? $_GET['file'] : '';
        $full = resolve_path($file, $ROOT);
        if (!file_exists($full) || !is_file($full)) {
            http_response_code(404);
            echo "Not found";
            exit;
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mimes = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'pdf' => 'application/pdf',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: no-store');
        readfile($full);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'list') {
        global $ALLOWED_EXT;
        $root = rtrim($ROOT, "/");
        $subdir = isset($_GET['dir']) ? $_GET['dir'] : '';
        $subdir = str_replace(["\\", ".."], ["/", ""], $subdir);
        $subdir = trim($subdir, '/');
        $scanPath = $subdir ? ($root . '/' . $subdir) : $root;
        $out = [];
        if (is_dir($scanPath)) {
            $items = scandir($scanPath);
            foreach ($items as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                $full = $scanPath . '/' . $f;
                $relPath = $subdir ? ($subdir . '/' . $f) : $f;
                if (is_dir($full)) {
                    $out[] = ['type' => 'dir', 'path' => $relPath, 'name' => $f];
                } else {
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, $ALLOWED_EXT)) {
                        $out[] = ['type' => 'file', 'path' => $relPath, 'name' => $f, 'ext' => $ext];
                    }
                }
            }
        }
        // dirs first, then files, alphabetical
        usort($out, function ($a, $b) {
            if ($a['type'] !== $b['type'])
                return $a['type'] === 'dir' ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });
        echo json_encode(['ok' => true, 'root' => $root, 'items' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'docx2html') {
        // suporta cale (?file=) sau upload drag&drop ($_FILES['f'])
        if (isset($_FILES['f']) && is_uploaded_file($_FILES['f']['tmp_name'])) {
            $full = $_FILES['f']['tmp_name'];
            $name = $_FILES['f']['name'];
        } else {
            $file = isset($_GET['file']) ? $_GET['file'] : '';
            $full = resolve_path($file, $ROOT);
            $name = $full;
        }
        if (!file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'Fisierul nu exista']);
            exit;
        }
        $html = docx_to_html($full);
        if ($html === false) {
            echo json_encode(['ok' => false, 'error' => 'Nu pot citi DOCX (zip/dom)']);
            exit;
        }
        if (is_array($html) && !empty($html['ole2'])) {
            echo json_encode(['ok' => true, 'ole2' => true, 'file' => $name]);
            exit;
        }
        echo json_encode(['ok' => true, 'html' => $html, 'file' => $name], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'odt2html') {
        // suporta si upload prin drag&drop ($_FILES['f']) pe langa cale (?file=)
        if (isset($_FILES['f']) && is_uploaded_file($_FILES['f']['tmp_name'])) {
            $full = $_FILES['f']['tmp_name'];
        } else {
            $file = isset($_GET['file']) ? $_GET['file'] : '';
            $full = resolve_path($file, $ROOT);
        }
        if (!file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'Fisierul nu exista']);
            exit;
        }
        $html = odt_to_html($full);
        if ($html === false) {
            echo json_encode(['ok' => false, 'error' => 'Nu pot citi ODT (zip/dom)']);
            exit;
        }
        echo json_encode(['ok' => true, 'html' => $html, 'file' => $full], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Salveaza fisier binar (docx generat client-side, base64)
    if ($action === 'savebin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = isset($_POST['file']) ? $_POST['file'] : '';
        $full = resolve_path($file, $ROOT);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            echo json_encode(['ok' => false, 'error' => 'Directorul nu exista: ' . $dir]);
            exit;
        }
        $b64 = isset($_POST['content']) ? $_POST['content'] : '';
        $b64 = preg_replace('#^data:[^,]+,#', '', $b64); // strip data: prefix daca exista
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            echo json_encode(['ok' => false, 'error' => 'base64 invalid']);
            exit;
        }
        $ok = file_put_contents($full, $bytes);
        if ($ok === false) {
            echo json_encode(['ok' => false, 'error' => 'Nu pot scrie fisierul']);
            exit;
        }
        echo json_encode(['ok' => true, 'file' => $full, 'bytes' => strlen($bytes)]);
        exit;
    }

    // Cauta calea reala pe disc a unui fisier dupa nume (+ dimensiune) — pentru drag&drop,
    // ca sa putem face overwrite pe fisierul original fara sa intrebam unde.
    if ($action === 'findpath') {
        $name = basename(str_replace('\\', '/', isset($_GET['name']) ? $_GET['name'] : ''));
        $size = isset($_GET['size']) ? (int) $_GET['size'] : -1;
        if ($name === '') {
            echo json_encode(['ok' => false, 'matches' => []]);
            exit;
        }
        $root = str_replace('/', '\\', rtrim($ROOT, '/'));
        $cmd = 'dir /s /b "' . $root . '\\' . $name . '" 2>nul';
        $lines = [];
        @exec($cmd, $lines);
        $matches = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !is_file($line))
                continue;
            if ($size >= 0 && @filesize($line) !== $size)
                continue;   // potrivire pe dimensiune → acelasi fisier
            $norm = str_replace('\\', '/', $line);
            if (!in_array($norm, $matches))
                $matches[] = $norm;
        }
        echo json_encode(['ok' => true, 'matches' => $matches], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'actiune necunoscuta']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Word Editor — docx / odt / pdf</title>
    <link rel="icon" href="../favicon.ico">
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --ribbon-bg: #f3f2f1;
            --ribbon-border: #d2d0ce;
            --accent: #2b579a;
            --accent-light: #c7d6ea;
            --btn-hover: #e1dfdd;
        }

        html,
        body {
            margin: 0;
            height: 100%;
            font-family: "Segoe UI", Tahoma, sans-serif;
            font-size: 13px;
            color: #201f1e;
            background: #edebe9;
        }

        body {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Top bar ── */
        .topbar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            background: var(--accent);
            color: #fff;
        }

        .topbar .brand {
            font-weight: 700;
            font-size: 14px;
            letter-spacing: .3px;
        }

        .topbar .file-name {
            font-size: 12px;
            opacity: .9;
            max-width: 380px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .topbar .spacer {
            flex: 1;
        }

        .topbtn {
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .35);
            color: #fff;
            border-radius: 4px;
            padding: 4px 12px;
            cursor: pointer;
            font-size: 12px;
        }

        .topbtn:hover {
            background: rgba(255, 255, 255, .3);
        }

        .topbtn.primary {
            background: #107c10;
            border-color: #0b610b;
            font-weight: 600;
        }

        .topbtn.primary:hover {
            background: #0e6b0e;
        }

        /* ── Ribbon ── */
        .ribbon {
            background: var(--ribbon-bg);
            border-bottom: 1px solid var(--ribbon-border);
            display: flex;
            align-items: stretch;
            padding: 4px 6px 2px;
            gap: 2px;
            overflow-x: auto;
            flex-wrap: nowrap;
        }

        .rgroup {
            display: flex;
            flex-direction: column;
            align-items: center;
            border-right: 1px solid var(--ribbon-border);
            padding: 0 6px;
        }

        .rgroup-row {
            display: flex;
            align-items: center;
            gap: 2px;
            flex: 1;
            flex-wrap: wrap;
            max-width: 220px;
        }

        .rgroup-label {
            font-size: 10px;
            color: #605e5c;
            margin-top: 2px;
            white-space: nowrap;
        }

        .rbtn {
            min-width: 26px;
            height: 24px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 3px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 0 5px;
            font-size: 13px;
            color: #201f1e;
            line-height: 1;
        }

        .rbtn:hover {
            background: var(--btn-hover);
            border-color: #c8c6c4;
        }

        .rbtn.active {
            background: var(--accent-light);
            border-color: #9db8de;
        }

        .rbtn svg {
            width: 15px;
            height: 15px;
        }

        .rsel {
            height: 24px;
            border: 1px solid #c8c6c4;
            border-radius: 3px;
            background: #fff;
            font-size: 12px;
            padding: 0 2px;
            cursor: pointer;
        }

        .rsep {
            width: 1px;
            background: #d2d0ce;
            align-self: stretch;
            margin: 2px 3px;
        }

        input[type=color].rcolor {
            width: 24px;
            height: 22px;
            border: 1px solid #c8c6c4;
            border-radius: 3px;
            padding: 0;
            cursor: pointer;
            background: #fff;
        }

        /* ── Main layout ── */
        .main {
            flex: 1;
            display: flex;
            min-height: 0;
        }

        .sidebar {
            width: 250px;
            background: #faf9f8;
            border-right: 1px solid var(--ribbon-border);
            overflow-y: auto;
            padding: 8px;
            flex-shrink: 0;
        }

        .sidebar.hidden {
            display: none;
        }

        .sidebar h3 {
            font-size: 12px;
            margin: 6px 4px;
            color: #605e5c;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-item:hover {
            background: var(--btn-hover);
        }

        .file-item .ico {
            flex-shrink: 0;
        }

        .file-item.dir {
            font-weight: 600;
            color: var(--accent);
        }

        /* ── Editor canvas ── */
        .canvas {
            flex: 1;
            overflow: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #edebe9;
            scroll-behavior: smooth;
        }

        /* wrapper editabil care contine paginile A4 (ca in MS Word) */
        #pages {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            outline: none;
            counter-reset: pg;
            width: 21cm;
            max-width: 100%;
        }

        .page {
            position: relative;
            background: #fff;
            width: 21cm;
            min-height: 29.7cm;
            padding: 2.2cm 2cm 2cm;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .25);
            outline: none;
            color: #000;
            font-family: "Times New Roman", serif;
            font-size: 12pt;
            line-height: 1.4;
        }

        .page::after {
            counter-increment: pg;
            content: "Pag. " counter(pg);
            position: absolute;
            right: 2cm;
            bottom: .7cm;
            font-size: 9pt;
            color: #b3b1ae;
            pointer-events: none;
        }

        .page.placeholder::before {
            content: "Deschide un document (docx / odt / pdf) sau scrie aici…";
            color: #a19f9d;
        }

        .page p {
            margin: 0 0 .35em;
        }

        .page img {
            max-width: 100%;
        }

        #pdfPages {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        #pdfPages canvas {
            box-shadow: 0 1px 6px rgba(0, 0, 0, .25);
            background: #fff;
        }

        /* ── Popups ── */
        .popup {
            position: fixed;
            top: 120px;
            right: 30px;
            width: 320px;
            background: #fff;
            border: 1px solid #c8c6c4;
            border-radius: 6px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, .25);
            z-index: 1000;
            display: none;
        }

        .popup.open {
            display: block;
        }

        .popup h4 {
            margin: 0;
            padding: 8px 12px;
            background: var(--accent);
            color: #fff;
            border-radius: 6px 6px 0 0;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            cursor: move;
        }

        .popup .body {
            padding: 12px;
        }

        .popup input[type=text] {
            width: 100%;
            padding: 5px 7px;
            border: 1px solid #c8c6c4;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .popup .row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .popup button {
            padding: 5px 10px;
            border: 1px solid #c8c6c4;
            background: #f3f2f1;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .popup button:hover {
            background: #e1dfdd;
        }

        .popup button.primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        .close-x {
            cursor: pointer;
            font-weight: 700;
        }

        .diac-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .diac-grid button {
            min-width: 34px;
            font-size: 16px;
        }

        /* status toast */
        #toast {
            position: fixed;
            bottom: 18px;
            left: 50%;
            transform: translateX(-50%);
            background: #323130;
            color: #fff;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 13px;
            opacity: 0;
            transition: opacity .25s;
            pointer-events: none;
            z-index: 2000;
        }

        #toast.show {
            opacity: 1;
        }

        .statusbar {
            background: var(--accent);
            color: #fff;
            font-size: 11px;
            padding: 2px 12px;
            display: flex;
            gap: 16px;
        }

        /* ── Tab bar ── */
        #tabBar {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            flex: 1;
            overflow-x: auto;
            min-width: 0;
            padding: 2px;
            border-radius: 6px;
        }

        #tabBar.drop-target {
            outline: 2px dashed #ffd166;
            outline-offset: -2px;
            background: rgba(255, 209, 102, .12);
        }

        .etab {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            border-radius: 6px 6px 0 0;
            padding: 8px 13px;
            font-size: 13.5px;
            cursor: pointer;
            max-width: 230px;
            white-space: nowrap;
        }

        .etab.active {
            background: #fff;
            color: var(--accent);
            font-weight: 600;
        }

        .etab-name {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .etab-x {
            font-weight: 700;
            opacity: .7;
            padding: 0 3px;
            font-size: 15px;
        }

        .etab-x:hover {
            opacity: 1;
            color: #d13438;
        }

        .etab-new {
            background: rgba(255, 255, 255, .28);
            font-weight: 700;
            font-size: 18px;
            padding: 6px 14px;
        }

        /* ── Start overlay (deschidere fisier) ── */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 22, 30, .72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
        }

        .overlay.open {
            display: flex;
        }

        .overlay-card {
            background: #1e2230;
            color: #e8eaf0;
            width: min(880px, 94vw);
            max-height: 90vh;
            overflow: auto;
            border-radius: 12px;
            padding: 26px 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .5);
        }

        .overlay-title {
            font-size: 24px;
            font-weight: 700;
        }

        .overlay-sub {
            color: #9aa3b2;
            margin: 6px 0 18px;
        }

        .overlay-path {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
        }

        .overlay-path input {
            flex: 1;
            background: #15161d;
            border: 1px solid #3a3f4f;
            border-radius: 6px;
            padding: 10px 12px;
            color: #eee;
            font-size: 15px;
        }

        .overlay-path button {
            background: var(--accent);
            border: none;
            color: #fff;
            border-radius: 6px;
            padding: 0 22px;
            font-weight: 600;
            cursor: pointer;
        }

        .overlay-row {
            display: flex;
            gap: 18px;
        }

        .drop-zone {
            flex: 1;
            border: 2px dashed #3a3f4f;
            border-radius: 10px;
            padding: 34px 16px;
            text-align: center;
            cursor: pointer;
            transition: .15s;
        }

        .drop-zone:hover,
        .drop-zone.drag {
            border-color: #6ea8fe;
            background: rgba(110, 168, 254, .08);
        }

        .drop-zone .big {
            font-size: 40px;
        }

        .drop-zone .lbl {
            font-size: 18px;
            font-weight: 600;
            margin-top: 8px;
        }

        .drop-zone .hint {
            color: #9aa3b2;
            font-size: 13px;
            margin-top: 4px;
        }

        .recent-panel {
            width: 320px;
            flex-shrink: 0;
            border-left: 1px solid #2c3142;
            padding-left: 16px;
        }

        .recent-panel h5 {
            margin: 0 0 8px;
            color: #9aa3b2;
            font-size: 13px;
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }

        .recent-item:hover {
            background: rgba(255, 255, 255, .07);
        }

        .recent-item .rn {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .recent-item .rf {
            color: #8b93a4;
            font-size: 11px;
            margin-left: auto;
            flex-shrink: 0;
        }

        .overlay-actions {
            margin-top: 18px;
            text-align: right;
        }

        .overlay-actions button {
            background: transparent;
            border: 1px solid #3a3f4f;
            color: #cdd3df;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <!-- TOP BAR -->
    <div class="topbar">
        <button class="topbtn" onclick="toggleSidebar()" title="Arata/ascunde fisierele">☰</button>
        <div class="brand">📄 Word Editor</div>
        <div id="tabBar"></div>
        <span id="fileName" style="display:none"></span>
        <button class="topbtn" onclick="showOverlay()" title="Deschide alt fisier">＋ Deschide</button>
        <button class="topbtn" onclick="closeActiveTab()" title="Inchide tab-ul curent">Inchide tab</button>
        <button class="topbtn primary" onclick="saveDocx()">💾 Salveaza (Ctrl+S)</button>
    </div>

    <!-- RIBBON (Word classic Home tab + extra din Mini Dreamweaver) -->
    <div class="ribbon" id="ribbon">
        <!-- Clipboard -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:150px">
                <button class="rbtn" title="Lipește (Ctrl+V)" onclick="doPaste()">📋</button>
                <button class="rbtn" title="Decupează (Ctrl+X)" onclick="cmd('cut')">✂️</button>
                <button class="rbtn" title="Copiază (Ctrl+C)" onclick="cmd('copy')">⧉</button>
                <button class="rbtn" id="btnPainter" title="Descriptor de formate: click pe text-sursă → click aici → selectează/click pe țintă" onclick="toggleFormatPainter()">🖌️ Format</button>
            </div>
            <div class="rgroup-label">Clipboard</div>
        </div>

        <!-- Font -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:320px">
                <select class="rsel" id="fontFamily" title="Font" onchange="setFont(this.value)" style="width:165px">
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Arial">Arial</option>
                    <option value="Calibri">Calibri</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Verdana">Verdana</option>
                    <option value="Tahoma">Tahoma</option>
                    <option value="Courier New">Courier New</option>
                    <option value="Cambria">Cambria</option>
                </select>
                <select class="rsel" id="fontSize" title="Dimensiune font" onchange="setFontSize(this.value)" style="width:48px">
                    <option>8</option>
                    <option>9</option>
                    <option>10</option>
                    <option>11</option>
                    <option>12</option>
                    <option selected>16</option>
                    <option>18</option>
                    <option>20</option>
                    <option>24</option>
                    <option>28</option>
                    <option>36</option>
                    <option>48</option>
                    <option>72</option>
                </select>
                <button class="rbtn" title="Mărește fontul" onclick="growFont(1)">A▲</button>
                <button class="rbtn" title="Micșorează fontul" onclick="growFont(-1)">A▼</button>
                <button class="rbtn" title="Schimbă registrul" onclick="changeCase()">Aa</button>
                <button class="rbtn" title="Golește formatarea" onclick="cmd('removeFormat')">🧹</button>
                <div class="rsep"></div>
                <button class="rbtn" id="b_bold" title="Aldin (Ctrl+B)" onclick="cmd('bold')"><b>B</b></button>
                <button class="rbtn" id="b_italic" title="Cursiv (Ctrl+I)" onclick="cmd('italic')"><i>I</i></button>
                <button class="rbtn" id="b_underline" title="Subliniat (Ctrl+U)" onclick="cmd('underline')"><u>U</u></button>
                <button class="rbtn" id="b_strike" title="Tăiat" onclick="cmd('strikeThrough')"><s>S</s></button>
                <button class="rbtn" title="Indice" onclick="cmd('subscript')">x₂</button>
                <button class="rbtn" title="Exponent" onclick="cmd('superscript')">x²</button>
                <label class="rbtn" title="Culoare evidențiere" style="position:relative">🖍️
                    <input type="color" id="hiliteColor" value="#ffff00" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="setHilite(this.value)"></label>
                <label class="rbtn" title="Culoare font" style="position:relative;font-weight:700;color:#c00">A
                    <input type="color" id="foreColor" value="#cc0000" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="setColor(this.value)"></label>
            </div>
            <div class="rgroup-label">Font</div>
        </div>

        <!-- Paragraph -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:230px">
                <button class="rbtn" title="Marcatori" onclick="cmd('insertUnorderedList')">• ≡</button>
                <button class="rbtn" title="Numerotare" onclick="cmd('insertOrderedList')">1. ≡</button>
                <button class="rbtn" title="Micșorează indentul" onclick="cmd('outdent')">⇤</button>
                <button class="rbtn" title="Mărește indentul" onclick="cmd('indent')">⇥</button>
                <button class="rbtn" id="btnMarks" title="Afișează marcaje de paragraf" onclick="toggleMarks()">¶</button>
                <div class="rsep"></div>
                <button class="rbtn" id="al_left" title="Aliniere stânga" onclick="cmd('justifyLeft')">⬅</button>
                <button class="rbtn" id="al_center" title="Centrat" onclick="cmd('justifyCenter')">⬌</button>
                <button class="rbtn" id="al_right" title="Aliniere dreapta" onclick="cmd('justifyRight')">➡</button>
                <button class="rbtn" id="al_just" title="Stânga-dreapta" onclick="cmd('justifyFull')">☰</button>
                <select class="rsel" title="Spațiere între rânduri" onchange="setLineHeight(this.value)" style="width:46px">
                    <option value="">↕</option>
                    <option value="1">1.0</option>
                    <option value="1.15">1.15</option>
                    <option value="1.5">1.5</option>
                    <option value="2">2.0</option>
                </select>
                <label class="rbtn" title="Umbrire (fundal)" style="position:relative">🪣
                    <input type="color" id="bgColor" value="#ffff99" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="cmd('backColor', this.value)"></label>
            </div>
            <div class="rgroup-label">Paragraf</div>
        </div>

        <!-- Styles -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:140px">
                <select class="rsel" id="styleSel" title="Stiluri" onchange="applyStyle(this.value)" style="width:128px">
                    <option value="p">Normal</option>
                    <option value="h1">Titlu 1</option>
                    <option value="h2">Titlu 2</option>
                    <option value="h3">Titlu 3</option>
                    <option value="h4">Titlu 4</option>
                    <option value="blockquote">Citat</option>
                    <option value="pre">Cod</option>
                </select>
            </div>
            <div class="rgroup-label">Stiluri</div>
        </div>

        <!-- Insert -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:140px">
                <button class="rbtn" title="Inserează link" onclick="insertLink()">🔗</button>
                <button class="rbtn" title="Inserează imagine" onclick="insertImage()">🖼️</button>
                <button class="rbtn" title="Inserează tabel 2×2" onclick="insertTable()">▦</button>
                <button class="rbtn" title="Linie orizontală" onclick="cmd('insertHorizontalRule')">―</button>
            </div>
            <div class="rgroup-label">Inserare</div>
        </div>

        <!-- Editing + extra (Mini Dreamweaver) -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:230px">
                <button class="rbtn" title="Anulează (Ctrl+Z)" onclick="doUndo()">↶ Undo</button>
                <button class="rbtn" title="Refă (Ctrl+Y)" onclick="doRedo()">↷ Redo</button>
                <button class="rbtn" title="Găsește / Înlocuiește (Ctrl+H)" onclick="toggleFind()">🔍</button>
                <button class="rbtn" title="Selectează tot (Ctrl+A)" onclick="cmd('selectAll')">▤</button>
                <button class="rbtn" title="Re-paginează pe coli A4 (Alt+P)" onclick="repaginate()">⇲¶</button>
                <div class="rsep"></div>
                <button class="rbtn" title="Diacritice românești" onclick="toggleDiac()" style="font-size:15px">Ă</button>
                <button class="rbtn" title="Traducere (Google Translate)" onclick="openTranslate()">🌐</button>
            </div>
            <div class="rgroup-label">Editare / Extra</div>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">
        <div class="sidebar" id="sidebar">
            <h3>Documente (docx · doc · odt · pdf)</h3>
            <div id="fileList"></div>
        </div>
        <div class="canvas" id="canvas">
            <div id="pages" contenteditable="true" spellcheck="false"></div>
            <div id="pdfPages"></div>
        </div>
    </div>

    <div class="statusbar">
        <span id="stPage">Pagina 1 / 1</span>
        <span id="stWords">0 cuvinte</span>
        <span id="stMode">—</span>
        <span id="stInfo" style="margin-left:auto"></span>
    </div>

    <!-- START OVERLAY: deschidere fisier (drag&drop + recente) -->
    <div class="overlay" id="startOverlay">
        <div class="overlay-card">
            <div class="overlay-title">Deschide un document</div>
            <div class="overlay-sub">Scrie calea completă, trage un fișier (docx · doc · odt · pdf), sau click pe zona de mai jos.</div>
            <div class="overlay-path">
                <input type="text" id="ovPathInput" placeholder="Ex: e:/Carte/.../document.docx"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();openFromOverlayPath();}">
                <button onclick="openFromOverlayPath()">Deschide</button>
            </div>
            <div class="overlay-row">
                <div class="drop-zone" id="dropZone" onclick="pickFile()">
                    <div class="big">📄⤓</div>
                    <div class="lbl">Drag &amp; Drop fișier aici</div>
                    <div class="hint">sau click pentru a alege un fișier (.docx .doc .odt .pdf)</div>
                </div>
                <div class="recent-panel" id="recentPanel" style="display:none">
                    <h5>Fișiere recente</h5>
                    <div id="recentList"></div>
                </div>
            </div>
            <input type="file" id="filePicker" accept=".docx,.doc,.odt,.pdf" style="display:none"
                onchange="if(this.files[0]) openDroppedFile(this.files[0]); this.value='';">
            <div class="overlay-actions">
                <button onclick="hideOverlay()">Continuă la editor</button>
            </div>
        </div>
    </div>

    <!-- POPUP: Find & Replace -->
    <div class="popup" id="findPopup">
        <h4 id="findHandle">Găsește & Înlocuiește <span class="close-x" onclick="toggleFind()">&times;</span></h4>
        <div class="body">
            <input type="text" id="findInput" placeholder="Găsește…">
            <input type="text" id="replaceInput" placeholder="Înlocuiește cu…">
            <div class="row">
                <button onclick="findNext()">Următorul</button>
                <button onclick="replaceOne()">Înlocuiește</button>
                <button class="primary" onclick="replaceAll()">Înlocuiește tot</button>
            </div>
        </div>
    </div>

    <!-- POPUP: Open by path -->
    <div class="popup" id="pathPopup">
        <h4 id="pathHandle">Deschide după cale <span class="close-x" onclick="closePath()">&times;</span></h4>
        <div class="body">
            <input type="text" id="pathInput" placeholder="e:/Carte/.../document.docx">
            <div class="row">
                <button class="primary" onclick="doOpenByPath()">Deschide</button>
            </div>
        </div>
    </div>

    <!-- POPUP: Diacritice -->
    <div class="popup" id="diacPopup" style="width:300px">
        <h4 id="diacHandle">Diacritice <span class="close-x" onclick="toggleDiac()">&times;</span></h4>
        <div class="body">
            <div class="diac-grid" id="diacGrid"></div>
            <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:12px">
                <tr style="color:#605e5c"><th style="text-align:left">Literă</th><th style="text-align:left">Combinație</th></tr>
                <tr><td>ă</td><td>Ctrl + A</td></tr>
                <tr><td>â</td><td>Alt + A</td></tr>
                <tr><td>î</td><td>Ctrl + I</td></tr>
                <tr><td>Î</td><td>Alt + I</td></tr>
                <tr><td>ș</td><td>Ctrl + Shift + S</td></tr>
                <tr><td>Ș</td><td>Alt + S</td></tr>
                <tr><td>ț</td><td>Alt + T</td></tr>
                <tr><td>Ț</td><td>Alt + Shift + T</td></tr>
            </table>
        </div>
    </div>

    <!-- MODAL: confirmare salvare la inchidere -->
    <div class="overlay" id="confirmOverlay" style="z-index:4000">
        <div class="overlay-card" style="width:min(460px,92vw)">
            <div class="overlay-title" style="font-size:19px">Salvezi modificările?</div>
            <div class="overlay-sub" id="confirmMsg">Documentul are modificări nesalvate.</div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button id="cfCancel" style="background:transparent;border:1px solid #3a3f4f;color:#cdd3df;border-radius:6px;padding:8px 16px;cursor:pointer">Anulează</button>
                <button id="cfDiscard" style="background:#5a3030;border:1px solid #7a3a3a;color:#ffd7d7;border-radius:6px;padding:8px 16px;cursor:pointer">Nu salva</button>
                <button id="cfSave" style="background:#107c10;border:none;color:#fff;border-radius:6px;padding:8px 18px;cursor:pointer;font-weight:600">Salvează</button>
            </div>
        </div>
    </div>

    <div id="toast"></div>

    <!-- Libs: html-docx-js (html→docx la salvare), pdf.js (randare PDF). DOCX/ODT se convertesc server-side. -->
    <script src="https://cdn.jsdelivr.net/npm/html-docx-js@0.3.1/dist/html-docx.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        const SELF = location.pathname; // /htmleditor/Word Editor/index.php
        const api = (qs) => SELF + '?' + qs;
        let currentFile = null;    // cale absoluta a fisierului deschis
        let currentExt = null;
        let currentDir = '';       // subdir relativ in sidebar
        const page = document.getElementById('pages');   // wrapper editabil ce contine coli A4
        const canvasEl = document.getElementById('canvas');

        // ── Paginare tip MS Word: imparte continutul in coli A4 separate ──
        const CM = 96 / 2.54;                  // px / cm la 96dpi
        const PAGE_LIMIT = (29.7 - 2.0) * CM;  // y maxim (de la marginea de sus a colii) unde se poate termina continutul

        function makeSheet() { const d = document.createElement('div'); d.className = 'page'; return d; }

        function overflows(sheet) {
            const last = sheet.lastChild;
            if (!last) return false;
            let bottom;
            if (last.nodeType === 1) { bottom = last.offsetTop + last.offsetHeight; }
            else {
                const r = document.createRange(); r.selectNode(last);
                bottom = r.getBoundingClientRect().bottom - sheet.getBoundingClientRect().top;
            }
            return bottom > PAGE_LIMIT;
        }

        function prependTo(parent, node) {
            if (node.nodeType === 3 && parent.firstChild && parent.firstChild.nodeType === 3)
                parent.firstChild.nodeValue = node.nodeValue + parent.firstChild.nodeValue;
            else parent.insertBefore(node, parent.firstChild);
        }

        // muta ultimul "cuvant" (sau nod) din bloc in fata remainder-ului
        function moveLastWord(block, rem) {
            const last = block.lastChild;
            if (!last) return;
            if (last.nodeType === 3) {
                const t = last.nodeValue, m = t.match(/\s*\S+\s*$/);
                if (m && m.index > 0) { last.nodeValue = t.slice(0, m.index); prependTo(rem, document.createTextNode(m[0])); }
                else { block.removeChild(last); prependTo(rem, last); }
            } else { block.removeChild(last); prependTo(rem, last); }
        }
        function sheetOverflowPx(sheet) {
            const last = sheet.lastChild; if (!last) return -1;
            let bottom;
            if (last.nodeType === 1) bottom = last.offsetTop + last.offsetHeight;
            else { const r = document.createRange(); r.selectNode(last); bottom = r.getBoundingClientRect().bottom - sheet.getBoundingClientRect().top; }
            return bottom - PAGE_LIMIT;
        }
        // Imparte un bloc prea inalt: muta coada in "remainder" in loturi proportionale
        // cu depasirea (putine reflow-uri, in loc de unul per cuvant).
        function splitTallBlock(sheet) {
            const block = sheet.lastElementChild;
            if (!block || block.nodeType !== 1) return null;
            const rem = block.cloneNode(false);
            let guard = 0;
            while (block.childNodes.length && guard++ < 200000) {
                const over = sheetOverflowPx(sheet);   // 1 reflow / iteratie
                if (over <= 0) break;
                const batch = Math.max(1, Math.floor(over / 3));  // ~ cuvinte de mutat
                for (let k = 0; k < batch && block.lastChild; k++) moveLastWord(block, rem);
            }
            if (!block.childNodes.length) { // nimic nu a incaput: revin, accept depasirea
                while (rem.childNodes.length) block.appendChild(rem.firstChild);
                return null;
            }
            return rem.childNodes.length ? rem : null;
        }

        // Inaltimea utila de continut a unei coli (sub padding-top, peste padding-bottom)
        const USABLE = (29.7 - 2.2 - 2.0) * CM;

        // Paginare RAPIDA: masoara toate blocurile o singura data intr-o coala-proba
        // (1 reflow), apoi imparte pe pagini matematic — fara reflow per bloc.
        // 225 pagini: ~10s (vechi) → sub 0.3s (nou).
        function setDocHtml(html) {
            showEditor();
            page.innerHTML = '';
            const src = document.createElement('div');
            src.innerHTML = (html && html.trim()) ? html : '';
            const blocks = [];
            src.childNodes.forEach(n => {
                if (n.nodeType === 3 && !n.nodeValue.trim()) return;
                if (n.nodeType === 3) { const w = document.createElement('p'); w.appendChild(n.cloneNode(true)); blocks.push(w); return; }
                blocks.push(n);
            });
            if (!blocks.length) {
                const s = makeSheet(); s.classList.add('placeholder'); page.appendChild(s);
                numberInfo(); updateWordCount(); return;
            }

            // FAZA 1 — masoara intr-o coala-proba (off-screen, inaltime auto)
            const probe = makeSheet();
            probe.style.cssText = 'min-height:0;visibility:hidden;position:absolute;left:-99999px;top:0';
            page.appendChild(probe);
            blocks.forEach(b => probe.appendChild(b));
            const measured = blocks.map(b => ({ node: b, top: b.offsetTop, h: b.offsetHeight }));  // 1 reflow
            page.removeChild(probe);

            // FAZA 2 — imparte pe coli dupa pozitiile masurate (fara reflow)
            let sheet = makeSheet(); page.appendChild(sheet);
            let pageStart = measured.length ? measured[0].top : 0;
            let tooTall = false;
            for (const m of measured) {
                if (m.h > USABLE) tooTall = true;
                if (sheet.childNodes.length > 0 && (m.top - pageStart) + m.h > USABLE) {
                    sheet = makeSheet(); page.appendChild(sheet);
                    pageStart = m.top;
                }
                sheet.appendChild(m.node);
            }

            // FAZA 3 — corectie DOAR daca exista blocuri mai inalte decat o pagina
            // (rar: paragrafe uriase). Sparge la nivel de cuvant.
            if (tooTall) {
                let s = page.firstElementChild;
                let guard = 0;
                while (s && guard++ < 100000) {
                    if (overflows(s)) {
                        if (s.childNodes.length === 1) {
                            const rem = splitTallBlock(s);
                            const ns = makeSheet(); s.after(ns);
                            if (rem) { ns.appendChild(rem); s = ns; continue; }
                            else { s = ns; continue; }
                        } else {
                            const last = s.lastChild; const ns = makeSheet(); s.after(ns);
                            ns.insertBefore(last, ns.firstChild); s = ns; continue;
                        }
                    }
                    s = s.nextElementSibling;
                }
                // elimina eventualele coli goale ramase
                page.querySelectorAll('.page').forEach(sh => { if (!sh.childNodes.length) sh.remove(); });
            }

            numberInfo(); updateWordCount();
        }

        function getDocHtml() {
            const sheets = page.querySelectorAll('.page');
            if (!sheets.length) return page.innerHTML;
            return Array.from(sheets).map(s => s.innerHTML).join('\n');
        }

        function repaginate() {
            const h = getDocHtml();
            setInfo('Se paginează…');
            setTimeout(() => { setDocHtml(h); setInfo('Paginat ' + page.querySelectorAll('.page').length + ' coli'); }, 10);
        }

        let pageCount = 1;
        function numberInfo() {
            pageCount = page.querySelectorAll('.page').length || 1;
            updatePageStatus();
        }
        function updatePageStatus() {
            const sheets = page.querySelectorAll('.page');
            if (!sheets.length) { document.getElementById('stPage').textContent = 'Pagina 1 / 1'; return; }
            const mid = canvasEl.scrollTop + canvasEl.clientHeight / 2;
            let cur = 1;
            sheets.forEach((s, idx) => { if (s.offsetTop - canvasEl.offsetTop <= mid) cur = idx + 1; });
            document.getElementById('stPage').textContent = 'Pagina ' + cur + ' / ' + sheets.length;
        }
        function gotoPage(delta) {
            const sheets = page.querySelectorAll('.page');
            if (!sheets.length) return;
            const mid = canvasEl.scrollTop + canvasEl.clientHeight / 2;
            let cur = 0;
            sheets.forEach((s, idx) => { if (s.offsetTop - canvasEl.offsetTop <= mid) cur = idx; });
            const target = Math.max(0, Math.min(sheets.length - 1, cur + delta));
            canvasEl.scrollTo({ top: sheets[target].offsetTop - canvasEl.offsetTop - 12, behavior: 'smooth' });
        }
        canvasEl.addEventListener('scroll', () => { clearTimeout(window._pgT); window._pgT = setTimeout(updatePageStatus, 60); });

        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        // ── Toast ──
        let toastTimer = null;
        function toast(msg, ms = 2200) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.classList.remove('show'), ms);
        }
        function setInfo(s) { document.getElementById('stInfo').textContent = s; }

        // ── Sidebar / file list ──
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('hidden');
        }
        async function loadFileList(dir = '') {
            currentDir = dir;
            try {
                const r = await fetch(api('action=list&dir=' + encodeURIComponent(dir)));
                const j = await r.json();
                const box = document.getElementById('fileList');
                box.innerHTML = '';
                if (dir) {
                    const up = document.createElement('div');
                    up.className = 'file-item dir';
                    up.innerHTML = '<span class="ico">📁</span>.. (înapoi)';
                    const parent = dir.split('/').slice(0, -1).join('/');
                    up.onclick = () => loadFileList(parent);
                    box.appendChild(up);
                }
                (j.items || []).forEach(it => {
                    const el = document.createElement('div');
                    el.className = 'file-item' + (it.type === 'dir' ? ' dir' : '');
                    const ico = it.type === 'dir' ? '📁'
                        : it.ext === 'pdf' ? '📕'
                            : it.ext === 'odt' ? '📘'
                                : '📄';
                    el.innerHTML = '<span class="ico">' + ico + '</span>' + escapeHtml(it.name);
                    el.title = it.name;
                    if (it.type === 'dir') el.onclick = () => loadFileList(it.path);
                    else el.onclick = () => openFile(it.path, it.ext);
                    box.appendChild(el);
                });
                if (!j.items || !j.items.length) {
                    box.innerHTML += '<div style="color:#a19f9d;padding:6px">(gol)</div>';
                }
            } catch (e) {
                toast('Eroare listare: ' + e.message);
            }
        }

        function escapeHtml(s) {
            return s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        }

        const OLE2_MSG = '<p style="color:#a00">Acest „.docx" este de fapt un document <b>Word vechi (.doc binar)</b> '
            + 'cu extensia schimbată.<br>Deschide-l în Word și salvează-l real ca <b>.docx</b> (OOXML), apoi reîncarcă-l aici.</p>';
        const DOC_MSG = '<p style="color:#a00">Formatul <b>.doc</b> (binar vechi) nu poate fi editat direct.<br>'
            + 'Deschide-l în Word și salvează ca <b>.docx</b>, apoi reîncarcă-l aici.</p>';

        // ── Deschidere din cale (server) — folosita de sidebar, recente, cale ──
        async function openFile(path, ext) {
            ext = (ext || path.split('.').pop()).toLowerCase();
            // daca fisierul e deja deschis intr-un tab → doar comuta
            const existing = tabs.find(t => t.path === path || t.file === path);
            if (existing) { activateTab(existing.id); return; }
            setInfo('Se încarcă…');
            hideOverlay();
            try {
                if (ext === 'docx') {
                    const j = await (await fetch(api('action=docx2html&file=' + encodeURIComponent(path)))).json();
                    if (!j.ok) throw new Error(j.error);
                    if (j.ole2) { renderDoc(OLE2_MSG); afterOpen(path, 'doc', path); return; }
                    renderDoc(j.html || '<p><br></p>'); afterOpen(path, ext, path);
                } else if (ext === 'odt') {
                    const j = await (await fetch(api('action=odt2html&file=' + encodeURIComponent(path)))).json();
                    if (!j.ok) throw new Error(j.error);
                    renderDoc(j.html || '<p><br></p>'); afterOpen(j.file || path, ext, path);
                } else if (ext === 'pdf') {
                    await loadPdf(api('action=raw&file=' + encodeURIComponent(path)));
                    afterOpen(path, ext, path, true);
                } else if (ext === 'doc') {
                    renderDoc(DOC_MSG); afterOpen(path, ext, path);
                } else { throw new Error('Extensie nesuportată: ' + ext); }
            } catch (e) {
                renderDoc('<p style="color:#a00">Eroare la deschidere: ' + escapeHtml(e.message) + '</p>'); setInfo('Eroare');
            }
        }

        // ── Deschidere din fisier local (drag&drop / file picker) ──
        //  handle = FileSystemFileHandle (dacă browserul îl oferă) → permite salvare/overwrite direct pe disc
        async function openDroppedFile(file, handle) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            setInfo('Se încarcă…'); hideOverlay();
            // atașează handle-ul pe tab-ul rezultat (doar pentru docx — scriem docx peste docx)
            const attach = () => { const t = curTab(); if (t && handle && ext === 'docx') t.handle = handle; };
            try {
                if (ext === 'docx') {
                    const fd = new FormData(); fd.append('f', file);
                    const j = await (await fetch(api('action=docx2html'), { method: 'POST', body: fd })).json();
                    if (!j.ok) throw new Error(j.error);
                    if (j.ole2) { renderDoc(OLE2_MSG); afterOpen(file.name, 'doc', null); return; }
                    renderDoc(j.html || '<p><br></p>'); afterOpen(file.name, ext, null); attach();
                    await resolveDroppedPath(file, ext);   // află calea reală pe disc → overwrite la save
                } else if (ext === 'pdf') {
                    const buf = await file.arrayBuffer();
                    await loadPdf({ data: new Uint8Array(buf) });
                    afterOpen(file.name, ext, null, true);
                } else if (ext === 'odt') {
                    const fd = new FormData(); fd.append('f', file);
                    const j = await (await fetch(api('action=odt2html'), { method: 'POST', body: fd })).json();
                    if (!j.ok) throw new Error(j.error);
                    renderDoc(j.html || '<p><br></p>'); afterOpen(file.name, ext, null);
                } else if (ext === 'doc') {
                    renderDoc(DOC_MSG); afterOpen(file.name, ext, null);
                } else { throw new Error('Extensie nesuportată: ' + ext); }
            } catch (e) {
                renderDoc('<p style="color:#a00">Eroare la deschidere: ' + escapeHtml(e.message) + '</p>'); setInfo('Eroare');
            }
        }

        // Drag&drop docx: caută calea reală pe disc (după nume+dimensiune) → permite overwrite tăcut
        async function resolveDroppedPath(file, ext) {
            if (ext !== 'docx') return;
            try {
                const j = await (await fetch(api('action=findpath&name=' + encodeURIComponent(file.name) + '&size=' + file.size))).json();
                if (j.ok && j.matches && j.matches.length === 1) {
                    const real = j.matches[0];
                    const t = curTab();
                    if (t) { t.path = real; t.file = real; t.savedHtml = getDocHtml(); }
                    currentFile = real; currentExt = 'docx';
                    // persistă în istoricul de durată (localStorage) și scoate intrarea de sesiune (drag&drop)
                    sessionDropped = sessionDropped.filter(e => e.name !== file.name);
                    recordRecent({ path: real, name: real.split(/[\\/]/).pop(), ext: 'docx' });
                    renderTabs();
                    setInfo('Sursă: ' + real);
                }
            } catch (e) { /* fără cale → la save va folosi handle sau va întreba */ }
        }

        // randeaza continut docx/odt (non-pdf) si ascunde randarea pdf
        function renderDoc(html) {
            document.getElementById('pdfPages').innerHTML = '';
            document.getElementById('pdfPages').style.display = 'none';
            setDocHtml(html);
        }

        // creeaza/actualizeaza tab-ul si seteaza starea curenta
        function afterOpen(file, ext, path, isPdf) {
            currentFile = path; currentExt = ext;
            document.getElementById('stMode').textContent = (ext || '').toUpperCase();
            setInfo('Deschis');
            registerOpenedTab(file, ext, path, !!isPdf);
            // istoric: fisiere cu cale (reopenabile) → localStorage; drag&drop → cache de sesiune
            if (path) recordRecent({ path, name: file.split(/[\\/]/).pop(), ext });
            else recordRecent({ path: null, name: file, ext, isPdf: !!isPdf, html: getDocHtml() });
            updateWordCount();
            toast('Deschis: ' + (file || '').split('/').pop());
        }
        function showEditor() {
            page.style.display = 'flex';
            document.getElementById('pdfPages').style.display = 'none';
        }
        function showPdf() { document.getElementById('pdfPages').style.display = 'flex'; }

        // ── PDF render + extragere text (src = URL string SAU {data:Uint8Array}) ──
        async function loadPdf(src) {
            const pdf = await pdfjsLib.getDocument(src).promise;
            const container = document.getElementById('pdfPages');
            container.innerHTML = '';
            let textHtml = '';
            for (let n = 1; n <= pdf.numPages; n++) {
                const pg = await pdf.getPage(n);
                const vp = pg.getViewport({ scale: 1.4 });
                const cv = document.createElement('canvas');
                cv.width = vp.width; cv.height = vp.height;
                container.appendChild(cv);
                await pg.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
                const tc = await pg.getTextContent();
                textHtml += '<p>' + escapeHtml(tc.items.map(i => i.str).join(' ')) + '</p>';
            }
            const bar = document.createElement('div');
            bar.style.cssText = 'margin:8px;color:#605e5c;font-size:12px;text-align:center';
            bar.innerHTML = 'PDF randat mai sus (' + pdf.numPages + ' pagini). '
                + 'Textul extras e paginat mai jos — modifică-l și „Salvează" îl scrie ca .docx.';
            container.appendChild(bar);
            showPdf();
            setDocHtml(textHtml || '<p><br></p>');
        }

        // ── UNDO/REDO unificat ── snapshot-uri HTML, ca să prindă ORICE schimbare
        // (tastare, butoane execCommand ȘI operații directe pe DOM: Format Painter,
        //  dimensiune font, spațiere, replace etc. — pe care undo-ul nativ nu le vede).
        let typingActive = false, typingTimer = null, suppressSnap = false;
        const UNDO_MAX = 60;
        function curTab() { return tabs.find(t => t.id === activeId) || null; }

        // poziția caretului ca offset de caractere de la începutul documentului (robust la re-paginare)
        function caretOffset() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            const r = sel.getRangeAt(0);
            if (!page.contains(r.startContainer)) return null;
            const pre = document.createRange();
            pre.selectNodeContents(page);
            try { pre.setEnd(r.startContainer, r.startOffset); } catch (e) { return null; }
            return pre.toString().length;   // nr. de caractere de la început până la caret (text/element)
        }
        function setCaretByOffset(n) {
            if (n == null) return false;
            let count = 0, node;
            const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            while ((node = w.nextNode())) {
                const len = node.nodeValue.length;
                if (count + len >= n) {
                    const r = document.createRange();
                    r.setStart(node, Math.max(0, Math.min(len, n - count)));
                    r.collapse(true);
                    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
                    const el = node.parentElement;
                    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'center' });
                    return true;
                }
                count += len;
            }
            return false;
        }
        function snapshot() { return { html: getDocHtml(), caret: caretOffset(), scroll: canvasEl.scrollTop }; }
        function restoreSnap(s) {
            setDocHtml(s.html);
            page.focus();
            if (!setCaretByOffset(s.caret)) canvasEl.scrollTop = s.scroll || 0;   // fallback: scroll
            else if (s.caret == null) canvasEl.scrollTop = s.scroll || 0;
        }
        function pushState() {                 // snapshot pe stiva tab-ului activ
            const t = curTab(); if (!t) return;
            (t.undo || (t.undo = [])).push(snapshot());
            if (t.undo.length > UNDO_MAX) t.undo.shift();
            if (t.redo) t.redo.length = 0;     // golire in-place (păstrează referința)
        }
        function recordUndo() { pushState(); typingActive = false; }   // operații discrete (butoane)
        function runCmd(fn) {            // execCommand + înregistrare ca un singur pas
            recordUndo(); suppressSnap = true;
            try { page.focus(); fn(); } finally { suppressSnap = false; }
            refreshActive(); updateWordCount();
        }
        function doUndo() {
            const t = curTab();
            if (!t || !t.undo || !t.undo.length) { toast('Nimic de anulat'); return; }
            (t.redo || (t.redo = [])).push(snapshot());
            restoreSnap(t.undo.pop());
            setInfo('Undo'); typingActive = false;
        }
        function doRedo() {
            const t = curTab();
            if (!t || !t.redo || !t.redo.length) { toast('Nimic de refăcut'); return; }
            t.undo.push(snapshot());
            restoreSnap(t.redo.pop());
            setInfo('Redo'); typingActive = false;
        }
        // tastarea: un snapshot per „rafală" (grupează tastele rapide, granularitate ca în editoare)
        page.addEventListener('beforeinput', () => {
            if (suppressSnap) return;
            if (!typingActive) { pushState(); typingActive = true; }
            clearTimeout(typingTimer); typingTimer = setTimeout(() => { typingActive = false; }, 600);
        });

        // ── Editing commands ──
        function focusPage() { page.focus(); }
        function cmd(name, val = null) {
            runCmd(() => document.execCommand(name, false, val === null ? undefined : val));
        }
        function setFont(f) { cmd('fontName', f); }
        function setColor(c) { cmd('foreColor', c); }
        function setHilite(c) { runCmd(() => { document.execCommand('hiliteColor', false, c) || document.execCommand('backColor', false, c); }); }
        function setFontSize(pt) {
            // execCommand fontSize ia 1..7; folosim CSS pe selecție via span
            runCmd(() => {
                document.execCommand('fontSize', false, '7');
                page.querySelectorAll('font[size="7"]').forEach(f => {
                    f.removeAttribute('size');
                    f.style.fontSize = pt + 'pt';
                });
            });
        }
        function growFont(dir) {
            const sizes = [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36, 48, 72];
            const cur = parseInt(document.getElementById('fontSize').value) || 16;
            let idx = sizes.indexOf(cur);
            if (idx === -1) idx = sizes.findIndex(s => s >= cur);
            idx = Math.max(0, Math.min(sizes.length - 1, idx + dir));
            document.getElementById('fontSize').value = sizes[idx];
            setFontSize(sizes[idx]);
        }
        function changeCase() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return;
            const txt = sel.toString();
            if (!txt) return;
            const up = txt.toUpperCase();
            const repl = (txt === up) ? txt.toLowerCase() : up;
            runCmd(() => document.execCommand('insertText', false, repl));
        }
        function setLineHeight(v) {
            const block = getBlock();
            if (block && v) { recordUndo(); block.style.lineHeight = v; }
        }
        function applyStyle(tag) {
            runCmd(() => document.execCommand('formatBlock', false, tag));
        }
        function getBlock() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            let n = sel.getRangeAt(0).startContainer;
            while (n && n !== page && !(n.nodeType === 1 && !n.classList.contains('page')
                && /^(P|H1|H2|H3|H4|H5|H6|LI|BLOCKQUOTE|PRE)$/.test(n.tagName)))
                n = n.parentNode;
            return (n && n !== page) ? n : null;
        }
        function refreshActive() {
            [['bold', 'b_bold'], ['italic', 'b_italic'], ['underline', 'b_underline'], ['strikeThrough', 'b_strike'],
            ['justifyLeft', 'al_left'], ['justifyCenter', 'al_center'], ['justifyRight', 'al_right'], ['justifyFull', 'al_just']]
                .forEach(([c, id]) => {
                    try { document.getElementById(id).classList.toggle('active', document.queryCommandState(c)); } catch (e) { }
                });
        }

        // ── Insert helpers ──
        function insertLink() {
            const url = prompt('URL link:', 'https://');
            if (url) cmd('createLink', url);
        }
        function insertImage() {
            const url = prompt('URL imagine:', 'https://');
            if (url) cmd('insertImage', url);
        }
        function insertTable() {
            const html = '<table border="1" style="border-collapse:collapse;width:100%">'
                + '<tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table><p><br></p>';
            runCmd(() => document.execCommand('insertHTML', false, html));
        }

        // ── Format Painter ── capturează stilul din sursă, apoi:
        //   • CLICK simplu pe țintă (fără tragere)  → aplică pe TOT paragraful
        //   • SELECT cu mouse-ul (tragere)          → aplică DOAR pe textul selectat
        // Distincția se face măsurând mișcarea mouse-ului între mousedown și mouseup
        // + starea selecției (colapsată = click, ne-colapsată = selecție).
        let painter = null;
        function disarmPainter() { painter = null; document.getElementById('btnPainter').classList.remove('active'); }
        function toggleFormatPainter() {
            if (painter) { disarmPainter(); toast('Format Painter oprit'); return; }
            const sel = window.getSelection();
            let n = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
            if (n && n.nodeType === 3) n = n.parentNode;
            if (!n || !page.contains(n)) { toast('Pune cursorul/selectează în textul-sursă, apoi click pe Format'); return; }
            const cs = getComputedStyle(n);
            painter = {
                fontFamily: cs.fontFamily, fontSize: cs.fontSize, color: cs.color,
                fontWeight: cs.fontWeight, fontStyle: cs.fontStyle,
                textDecoration: cs.textDecorationLine, background: cs.backgroundColor
            };
            document.getElementById('btnPainter').classList.add('active');
            toast('Stil copiat — click pe paragraf SAU selectează doar câteva cuvinte');
        }
        function painterCss() {
            let c = 'font-family:' + painter.fontFamily + ';font-size:' + painter.fontSize + ';color:' + painter.color
                + ';font-weight:' + painter.fontWeight + ';font-style:' + painter.fontStyle + ';';
            if (painter.textDecoration && painter.textDecoration !== 'none') c += 'text-decoration:' + painter.textDecoration + ';';
            if (painter.background && !/rgba?\(0,\s*0,\s*0,\s*0\)|transparent/.test(painter.background)) c += 'background-color:' + painter.background + ';';
            return c;
        }
        // Înfășoară DOAR porțiunile de text din range în <span> stilat (păstrează structura paragrafelor)
        function wrapRangeTextNodes(range) {
            const sc = range.startContainer, so = range.startOffset, ec = range.endContainer, eo = range.endOffset;
            const anc = range.commonAncestorContainer;
            const rootEl = anc.nodeType === 1 ? anc : anc.parentNode;
            const nodes = [];
            const w = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT);
            let nn; while ((nn = w.nextNode())) { try { if (range.intersectsNode(nn)) nodes.push(nn); } catch (e) { } }
            if (!nodes.length && sc.nodeType === 3) nodes.push(sc);
            const css = painterCss();
            for (const tn of nodes) {
                if (!tn.nodeValue) continue;
                const start = (tn === sc) ? so : 0;
                const end = (tn === ec) ? eo : tn.nodeValue.length;
                if (start >= end) continue;
                let mid = tn;
                if (start > 0) mid = tn.splitText(start);     // mid = [start..]
                if (end - start < mid.nodeValue.length) mid.splitText(end - start);  // taie coada
                const span = document.createElement('span');
                span.style.cssText = css;
                mid.parentNode.insertBefore(span, mid);
                span.appendChild(mid);
            }
        }
        // selectionOnly=true → aplică pe textul selectat; false → pe tot paragraful
        function applyPainter(selectionOnly) {
            if (!painter) return false;
            const sel = window.getSelection();
            if (!sel.rangeCount || !page.contains(sel.getRangeAt(0).startContainer)) { disarmPainter(); return false; }
            let range = sel.getRangeAt(0);
            const useSel = selectionOnly && !range.collapsed && sel.toString().trim().length > 0;
            if (!useSel) {
                const blk = getBlock(); if (!blk) { disarmPainter(); return false; }
                range = document.createRange(); range.selectNodeContents(blk);
            }
            recordUndo();   // Format Painter modifică direct DOM-ul → înregistrează pentru undo
            wrapRangeTextNodes(range);
            disarmPainter();
            sel.removeAllRanges();
            updateWordCount();
            toast(useSel ? 'Format aplicat pe selecție' : 'Format aplicat pe paragraf');
            return true;
        }

        // ── Formatting marks ──
        function toggleMarks() {
            page.classList.toggle('show-marks');
            const on = page.classList.contains('show-marks');
            document.getElementById('btnMarks').classList.toggle('active', on);
            let st = document.getElementById('marksStyle');
            if (on && !st) {
                st = document.createElement('style'); st.id = 'marksStyle';
                st.textContent = '.show-marks p::after{content:"¶";color:#2b579a;opacity:.5}';
                document.head.appendChild(st);
            }
        }

        // ── Paste: text curat ──
        page.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            const html = text.split(/\r?\n\r?\n/).map(par =>
                '<p>' + escapeHtml(par).replace(/\r?\n/g, '<br>') + '</p>').join('');
            document.execCommand('insertHTML', false, html || '<p><br></p>');
        });
        function doPaste() {
            navigator.clipboard.readText().then(t => {
                page.focus();
                document.execCommand('insertText', false, t);
            }).catch(() => toast('Folosește Ctrl+V'));
        }

        // ── Word count ──
        function updateWordCount() {
            const txt = page.innerText.trim();
            const w = txt ? txt.split(/\s+/).length : 0;
            document.getElementById('stWords').textContent = w + ' cuvinte';
        }
        page.addEventListener('input', updateWordCount);
        page.addEventListener('keyup', refreshActive);

        // ── Format Painter: detectează click vs tragere (select) ──
        let painterDown = null;
        page.addEventListener('mousedown', e => { if (painter) painterDown = { x: e.clientX, y: e.clientY }; });
        page.addEventListener('mouseup', e => {
            refreshActive();
            if (!painter) return;
            const moved = painterDown ? Math.hypot(e.clientX - painterDown.x, e.clientY - painterDown.y) : 0;
            painterDown = null;
            setTimeout(() => {
                const sel = window.getSelection();
                const dragged = moved > 4 && sel.rangeCount && !sel.getRangeAt(0).collapsed && sel.toString().trim().length > 0;
                applyPainter(dragged);   // tragere → doar selecția; click → tot paragraful
            }, 0);
        });

        // ── Diacritice prin combinații de taste (preluate din index V.4.php) ──
        //  Ctrl+A → ă | Ctrl+I → î | Ctrl+Shift+S → ș
        //  Alt+A → â | Alt+I → Î | Alt+S → Ș | Alt+T → ț | Alt+Shift+T → Ț
        page.addEventListener('keydown', (e) => {
            if (e.metaKey) return;
            let d = null;
            const k = (e.key || '').toLowerCase();
            if (e.ctrlKey && !e.altKey) {
                if (!e.shiftKey && k === 'a') d = 'ă';
                else if (!e.shiftKey && k === 'i') d = 'î';
                else if (e.shiftKey && k === 's') d = 'ș';
            } else if (e.altKey && !e.ctrlKey) {
                if (k === 't') d = e.shiftKey ? 'Ț' : 'ț';
                else if (!e.shiftKey && k === 's') d = 'Ș';
                else if (!e.shiftKey && k === 'i') d = 'Î';
                else if (!e.shiftKey && k === 'a') d = 'â';
            }
            if (d) { e.preventDefault(); e.stopPropagation(); document.execCommand('insertText', false, d); }
        });

        // ── Save → docx ──
        //  • fișier deschis cu „handle" (drag&drop / file picker)  → suprascrie ACELAȘI fișier, fără întrebare
        //  • fișier cu cale pe server (sidebar / cale / recente)   → overwrite automat, fără întrebare
        //  • fișier nou (nesalvat, fără cale)                      → întreabă unde să-l salveze
        async function ensureWritable(handle) {
            const opts = { mode: 'readwrite' };
            if ((await handle.queryPermission(opts)) === 'granted') return true;
            return (await handle.requestPermission(opts)) === 'granted';
        }
        async function saveDocx() {
            const t = curTab();
            const inner = getDocHtml();
            const full = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                + '<style>body{font-family:"Times New Roman",serif;font-size:12pt}</style></head><body>'
                + inner + '</body></html>';
            if (typeof htmlDocx === 'undefined') { toast('html-docx-js neîncărcat'); return false; }
            const blob = htmlDocx.asBlob(full);

            // 1) cale cunoscută pe disc (sidebar / cale / drag&drop rezolvat) → overwrite tăcut prin server (fără prompt de permisiune)
            if (currentFile) {
                const b64 = await blobToBase64(blob);
                let target = currentFile;
                if (currentExt && currentExt !== 'docx') {
                    target = target.replace(/\.(pdf|odt|doc)$/i, '.docx');
                    if (!/\.docx$/i.test(target)) target += '.docx';
                }
                setInfo('Se salvează…');
                const fd = new FormData(); fd.append('file', target); fd.append('content', b64);
                try {
                    const r = await fetch(api('action=savebin'), { method: 'POST', body: fd });
                    const j = await r.json();
                    if (j.ok) {
                        toast('Salvat: ' + j.file.split('/').pop() + ' (' + j.bytes + ' B)');
                        setInfo('Salvat ' + new Date().toLocaleTimeString());
                        currentFile = j.file; currentExt = 'docx';
                        document.getElementById('fileName').textContent = j.file;
                        if (t) { t.savedHtml = inner; t.html = inner; t.path = j.file; t.ext = 'docx'; t.file = j.file; }
                        renderTabs();
                        return true;
                    } else { toast('Eroare: ' + j.error); setInfo('Eroare salvare'); return false; }
                } catch (e) { toast('Eroare salvare: ' + e.message); return false; }
            }

            // 2) handle direct (drag&drop / picker, fără cale rezolvată pe server) → suprascrie fișierul original
            if (t && t.handle) {
                try {
                    setInfo('Se salvează…');
                    if (!await ensureWritable(t.handle)) { toast('Permisiune de scriere refuzată'); setInfo('Anulat'); return false; }
                    const w = await t.handle.createWritable();
                    await w.write(blob); await w.close();
                    t.savedHtml = inner; t.html = inner; t.ext = 'docx';
                    toast('Salvat: ' + (t.file || t.handle.name)); setInfo('Salvat ' + new Date().toLocaleTimeString());
                    return true;
                } catch (e) { toast('Eroare salvare: ' + e.message); setInfo('Eroare salvare'); return false; }
            }

            // 3) fără cale și fără handle → fișier nou → întreabă unde să salvez
            const b64 = await blobToBase64(blob);
            let target = await askSavePath('e:/Carte/document.docx');
            if (!target) return false;
            if (target === '__handle__') return saveDocx();   // handle setat de showSaveFilePicker → re-salvează prin el
            if (currentExt && currentExt !== 'docx') {
                target = target.replace(/\.(pdf|odt|doc)$/i, '.docx');
                if (!/\.docx$/i.test(target)) target += '.docx';
            }
            setInfo('Se salvează…');
            const fd = new FormData();
            fd.append('file', target);
            fd.append('content', b64);
            try {
                const r = await fetch(api('action=savebin'), { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    toast('Salvat: ' + j.file.split('/').pop() + ' (' + j.bytes + ' B)');
                    setInfo('Salvat ' + new Date().toLocaleTimeString());
                    currentFile = j.file; currentExt = 'docx';
                    document.getElementById('fileName').textContent = j.file;
                    if (t) { t.savedHtml = inner; t.html = inner; t.path = j.file; t.ext = 'docx'; t.file = j.file; }
                    renderTabs();
                    return true;
                } else { toast('Eroare: ' + j.error); setInfo('Eroare salvare'); return false; }
            } catch (e) { toast('Eroare salvare: ' + e.message); return false; }
        }
        function blobToBase64(blob) {
            return new Promise((res) => {
                const fr = new FileReader();
                fr.onload = () => res(fr.result.split(',')[1]);
                fr.readAsDataURL(blob);
            });
        }

        // ── Find & Replace ──
        function toggleFind() { document.getElementById('findPopup').classList.toggle('open'); }
        function findNext() {
            const q = document.getElementById('findInput').value;
            if (q) window.find ? window.find(q) : toast('Browser fără window.find');
        }
        function replaceOne() {
            const q = document.getElementById('findInput').value;
            const r = document.getElementById('replaceInput').value;
            const sel = window.getSelection();
            if (sel.toString() === q && q) { runCmd(() => document.execCommand('insertText', false, r)); }
            findNext();
        }
        function replaceAll() {
            const q = document.getElementById('findInput').value;
            const r = document.getElementById('replaceInput').value;
            if (!q) return;
            recordUndo();   // înregistrează tot batch-ul ca un singur undo
            const walker = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            let cnt = 0, node;
            while ((node = walker.nextNode())) {
                if (node.nodeValue.includes(q)) {
                    node.nodeValue = node.nodeValue.split(q).join(r); cnt++;
                }
            }
            if (!cnt) { const t = curTab(); if (t && t.undo) t.undo.pop(); }   // nimic schimbat → scoate snapshot-ul
            toast(cnt + ' înlocuiri'); if (cnt) repaginate(); else updateWordCount();
        }

        // ── Open by path ──
        function openByPath() { document.getElementById('pathPopup').classList.add('open'); document.getElementById('pathInput').focus(); }
        function closePath() { document.getElementById('pathPopup').classList.remove('open'); }
        function doOpenByPath() {
            const p = document.getElementById('pathInput').value.trim();
            if (!p) return;
            closePath();
            openFile(p, p.split('.').pop());
        }

        // ── Translate ──
        function openTranslate() {
            const sel = window.getSelection().toString().trim();
            const q = sel ? encodeURIComponent(sel) : '';
            window.open('https://translate.google.com/?sl=auto&tl=ro&text=' + q + '&op=translate', '_blank');
        }

        // ── Diacritice ──
        const DIAC = ['ă', 'â', 'î', 'ș', 'ț', 'Ă', 'Â', 'Î', 'Ș', 'Ț', '„', '”', '«', '»', '–', '—', '…'];
        function buildDiac() {
            const g = document.getElementById('diacGrid');
            DIAC.forEach(ch => {
                const b = document.createElement('button');
                b.textContent = ch;
                b.onclick = () => { page.focus(); document.execCommand('insertText', false, ch); };
                g.appendChild(b);
            });
        }
        function toggleDiac() { document.getElementById('diacPopup').classList.toggle('open'); }

        // ── Draggable popups ──
        function makeDraggable(handleId, popupId) {
            const h = document.getElementById(handleId), p = document.getElementById(popupId);
            let sx, sy, ox, oy, drag = false;
            h.addEventListener('mousedown', e => {
                if (e.target.classList.contains('close-x')) return;
                drag = true; sx = e.clientX; sy = e.clientY;
                const r = p.getBoundingClientRect(); ox = r.left; oy = r.top;
                p.style.right = 'auto';
            });
            document.addEventListener('mousemove', e => {
                if (!drag) return;
                p.style.left = (ox + e.clientX - sx) + 'px';
                p.style.top = (oy + e.clientY - sy) + 'px';
            });
            document.addEventListener('mouseup', () => drag = false);
        }

        // ── TABURI (deschide mai multe documente, alternează între ele) ──
        let tabs = [];
        let activeId = null;
        let tabSeq = 0;

        function registerOpenedTab(file, ext, path, isPdf) {
            const html = getDocHtml();
            let t = tabs.find(x => (path && x.path === path) || (!path && x.file === file && !x.path));
            if (!t) { t = { id: ++tabSeq, file, ext, path: path || null, isPdf: !!isPdf, html, savedHtml: html, undo: [], redo: [], handle: null }; tabs.push(t); }
            else { t.html = html; t.savedHtml = html; t.ext = ext; t.isPdf = !!isPdf; t.undo = []; t.redo = []; t.handle = null; }
            activeId = t.id;
            renderTabs();
        }
        // un tab e „murdar" daca textul curent difera de cel salvat/deschis (baseline)
        function isTabDirty(t) {
            if (!t) return false;
            const cur = (t.id === activeId) ? getDocHtml() : t.html;
            return cur !== t.savedHtml;
        }
        function renderTabs() {
            const bar = document.getElementById('tabBar');
            bar.innerHTML = '';
            tabs.forEach(t => {
                const el = document.createElement('div');
                el.className = 'etab' + (t.id === activeId ? ' active' : '');
                const nm = (t.file || '(nou)').split('/').pop();
                const nameEl = document.createElement('span'); nameEl.className = 'etab-name'; nameEl.textContent = nm; nameEl.title = t.file || '';
                nameEl.onclick = () => activateTab(t.id);
                const x = document.createElement('span'); x.className = 'etab-x'; x.textContent = '×'; x.title = 'Închide tab';
                x.onclick = (e) => { e.stopPropagation(); closeTab(t.id); };
                el.appendChild(nameEl); el.appendChild(x);
                bar.appendChild(el);
            });
            const plus = document.createElement('div'); plus.className = 'etab etab-new'; plus.textContent = '+'; plus.title = 'Deschide alt fișier'; plus.onclick = () => showOverlay();
            bar.appendChild(plus);
        }
        function activateTab(id) {
            const cur = tabs.find(t => t.id === activeId);
            if (cur && cur.id !== id) cur.html = getDocHtml();   // salvează editările curente în tab
            const t = tabs.find(t => t.id === id);
            if (!t) return;
            activeId = id;
            currentFile = t.path; currentExt = t.ext;
            document.getElementById('stMode').textContent = (t.ext || '').toUpperCase();
            document.getElementById('pdfPages').innerHTML = '';
            document.getElementById('pdfPages').style.display = 'none';
            setDocHtml(t.html || '');
            if (t.isPdf && t.path) loadPdf(api('action=raw&file=' + encodeURIComponent(t.path)));
            renderTabs(); updateWordCount();
            setInfo('Tab: ' + (t.file || '').split('/').pop());
        }
        async function closeTab(id) {
            const idx = tabs.findIndex(t => t.id === id);
            if (idx < 0) return;
            const tab = tabs[idx];
            // sincronizeaza continutul curent in tab (pentru istoric + verificare „murdar")
            if (id === activeId) tab.html = getDocHtml();
            // modificari nesalvate → intreaba
            if (isTabDirty(tab)) {
                const choice = await confirmSave(tab.file);
                if (choice === 'cancel') return;            // anuleaza inchiderea
                if (choice === 'save') {
                    if (activeId !== tab.id) { activateTab(tab.id); tab.html = getDocHtml(); }
                    const ok = await saveDocx();
                    if (!ok) return;                        // salvare esuata → nu inchide
                }
                // 'discard' → continua inchiderea fara salvare
            }
            touchRecentOnClose(tab);   // ordonare istoric după data închiderii
            const realIdx = tabs.findIndex(t => t.id === id);
            tabs.splice(realIdx, 1);
            if (activeId === id) {
                if (tabs.length) { activeId = null; activateTab(tabs[Math.max(0, realIdx - 1)].id); }
                else {
                    activeId = null; currentFile = null; currentExt = null; setDocHtml('');
                    document.getElementById('stMode').textContent = '—'; renderTabs(); showOverlay();
                }
            } else renderTabs();
        }
        function closeActiveTab() { if (activeId) closeTab(activeId); else showOverlay(); }

        // ── Modal confirmare salvare → 'save' | 'discard' | 'cancel' ──
        function confirmSave(fileName) {
            return new Promise(resolve => {
                const ov = document.getElementById('confirmOverlay');
                document.getElementById('confirmMsg').textContent =
                    'Documentul „' + ((fileName || '').split(/[\\/]/).pop() || 'fără nume') + '" are modificări nesalvate.';
                ov.classList.add('open');
                const sv = document.getElementById('cfSave'), ds = document.getElementById('cfDiscard'), cx = document.getElementById('cfCancel');
                function done(val) {
                    ov.classList.remove('open');
                    sv.onclick = ds.onclick = cx.onclick = null;
                    document.removeEventListener('keydown', onKey, true);
                    resolve(val);
                }
                function onKey(e) {
                    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done('cancel'); }
                    else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); done('save'); }
                }
                sv.onclick = () => done('save');
                ds.onclick = () => done('discard');
                cx.onclick = () => done('cancel');
                document.addEventListener('keydown', onKey, true);
                setTimeout(() => sv.focus(), 30);
            });
        }

        // ── START OVERLAY (deschidere fișier: cale / drag&drop / recente) ──
        function showOverlay() { renderRecent(); document.getElementById('startOverlay').classList.add('open'); setTimeout(() => document.getElementById('ovPathInput').focus(), 50); }
        function hideOverlay() { document.getElementById('startOverlay').classList.remove('open'); }
        function openFromOverlayPath() {
            const p = document.getElementById('ovPathInput').value.trim();
            if (!p) return;
            openFile(p, p.split('.').pop());
        }
        (function () {
            const dz = document.getElementById('dropZone');
            const tb = document.getElementById('tabBar');
            const hasFiles = (e) => e.dataTransfer && [...(e.dataTransfer.types || [])].includes('Files');
            // indiciu vizual pe zona de drop din overlay
            ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
            ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, () => dz.classList.remove('drag')));
            // indiciu vizual pe bara de taburi (drop direct pe linia tab-urilor, fara „+")
            ['dragenter', 'dragover'].forEach(ev => tb.addEventListener(ev, e => { if (hasFiles(e)) { e.preventDefault(); tb.classList.add('drop-target'); } }));
            ['dragleave', 'drop'].forEach(ev => tb.addEventListener(ev, () => tb.classList.remove('drop-target')));
            // orice fișier tras oriunde în fereastră → îl deschide (ca tab nou)
            window.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
            window.addEventListener('drop', async e => {
                if (!(e.dataTransfer.files && e.dataTransfer.files.length)) return;
                e.preventDefault();
                const file = e.dataTransfer.files[0];
                // încearcă să obții un handle către fișierul real (Chrome) → permite overwrite la salvare
                const item = e.dataTransfer.items && e.dataTransfer.items[0];
                let handle = null;
                if (item && item.getAsFileSystemHandle) {
                    try { const h = await item.getAsFileSystemHandle(); if (h && h.kind === 'file') handle = h; } catch (_) { }
                }
                openDroppedFile(file, handle);
            });
        })();

        // alegere fișier (click pe zona de drop): showOpenFilePicker (cu handle) sau input clasic
        async function pickFile() {
            if (window.showOpenFilePicker) {
                try {
                    const [h] = await window.showOpenFilePicker({
                        types: [{ description: 'Documente', accept: { 'application/octet-stream': ['.docx', '.doc', '.odt', '.pdf'] } }]
                    });
                    const file = await h.getFile();
                    openDroppedFile(file, h);
                    return;
                } catch (e) { if (e && e.name === 'AbortError') return; }
            }
            document.getElementById('filePicker').click();   // fallback
        }
        // salvare fișier nou: showSaveFilePicker (dialog nativ + handle) sau prompt pentru cale server
        async function askSavePath(def) {
            const t = curTab();
            if (window.showSaveFilePicker) {
                try {
                    const h = await window.showSaveFilePicker({
                        suggestedName: (t && t.file ? t.file.split(/[\\/]/).pop().replace(/\.\w+$/, '') : 'document') + '.docx',
                        types: [{ description: 'Document Word', accept: { 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'] } }]
                    });
                    if (t) t.handle = h;     // ține minte handle-ul → salvările următoare suprascriu direct
                    return '__handle__';     // semnal: re-salvează prin handle
                } catch (e) { if (e && e.name === 'AbortError') return null; }
            }
            return prompt('Salvează ca (cale .docx):', def);
        }

        // ── Istoric fișiere (ultimele 15, ordonate după data deschiderii/închiderii) ──
        //  • fișiere cu cale (sidebar/cale/recente) → persistă în localStorage (reopenabile oricând)
        //  • fișiere drag&drop (fără cale pe disc) → cache de sesiune cu HTML-ul (reopenabile cât e deschisă aplicația)
        const RECENT_KEY = 'wordEditorRecent';   // [{path,name,ext,ts}]
        const MAX_RECENT = 15;
        let sessionDropped = [];                 // [{name,ext,isPdf,html,ts}]

        function getRecentStore() { try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; } catch (e) { return []; } }
        function recordRecent(entry) {
            entry.ts = Date.now();
            if (entry.path) {
                let r = getRecentStore().filter(e => e.path !== entry.path);
                r.unshift({ path: entry.path, name: entry.name, ext: entry.ext, ts: entry.ts });
                if (r.length > MAX_RECENT) r = r.slice(0, MAX_RECENT);
                localStorage.setItem(RECENT_KEY, JSON.stringify(r));
            } else {
                sessionDropped = sessionDropped.filter(e => e.name !== entry.name);
                sessionDropped.unshift({ name: entry.name, ext: entry.ext, isPdf: entry.isPdf, html: entry.html, ts: entry.ts });
                if (sessionDropped.length > MAX_RECENT) sessionDropped = sessionDropped.slice(0, MAX_RECENT);
            }
            renderRecent();
        }
        // la închiderea unui tab → actualizează data (ordonare după închidere) și mută în vârf
        function touchRecentOnClose(tab) {
            if (!tab) return;
            if (tab.path) recordRecent({ path: tab.path, name: (tab.file || '').split(/[\\/]/).pop(), ext: tab.ext });
            else recordRecent({ path: null, name: tab.file, ext: tab.ext, isPdf: tab.isPdf, html: tab.html });
        }
        function allRecent() {
            const persistent = getRecentStore().map(e => ({ path: e.path, name: e.name, ext: e.ext, ts: e.ts }));
            const dropped = sessionDropped.map(e => ({ path: null, name: e.name, ext: e.ext, ts: e.ts }));
            const merged = [...persistent, ...dropped].sort((a, b) => b.ts - a.ts);
            const seen = new Set(), out = [];
            for (const e of merged) { if (seen.has(e.name)) continue; seen.add(e.name); out.push(e); }
            return out.slice(0, MAX_RECENT);
        }
        function renderRecent() {
            const list = document.getElementById('recentList');
            const panel = document.getElementById('recentPanel');
            const items = allRecent();
            if (!items.length) { panel.style.display = 'none'; return; }
            panel.style.display = 'block';
            list.innerHTML = '';
            items.forEach(e => {
                const ext = (e.ext || e.name.split('.').pop() || '').toLowerCase();
                const ico = ext === 'pdf' ? '📕' : ext === 'odt' ? '📘' : ext === 'doc' ? '📃' : '📄';
                const folder = e.path ? (e.path.replace(/\\/g, '/').split('/').filter(Boolean).slice(-2, -1)[0] || '') : 'drag&drop';
                const el = document.createElement('div'); el.className = 'recent-item'; el.title = e.path || (e.name + ' (drag&drop)');
                el.innerHTML = '<span>' + ico + '</span><span class="rn">' + escapeHtml(e.name) + '</span><span class="rf">' + escapeHtml(folder) + '</span>';
                el.onclick = () => e.path ? openFile(e.path, ext) : reopenDropped(e.name);
                list.appendChild(el);
            });
        }
        function reopenDropped(name) {
            const d = sessionDropped.find(x => x.name === name);
            if (!d) { toast('Fișierul a fost deschis prin drag&drop — trage-l din nou (istoric expirat)'); return; }
            // daca e deja deschis intr-un tab → comuta
            const ex = tabs.find(t => !t.path && t.file === name);
            if (ex) { hideOverlay(); activateTab(ex.id); return; }
            hideOverlay();
            renderDoc(d.html);
            afterOpen(name, d.ext, null, d.isPdf);
        }

        // ── Keyboard shortcuts ──
        document.addEventListener('keydown', e => {
            // Esc inchide overlay-ul de deschidere (daca e deschis)
            if (e.key === 'Escape' && document.getElementById('startOverlay').classList.contains('open')) {
                e.preventDefault(); hideOverlay(); return;
            }
            if (e.ctrlKey && e.key.toLowerCase() === 's') { e.preventDefault(); saveDocx(); }
            else if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase() === 'z') { e.preventDefault(); doUndo(); }
            else if (e.ctrlKey && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { e.preventDefault(); doRedo(); }
            else if (e.ctrlKey && e.key.toLowerCase() === 'h') { e.preventDefault(); toggleFind(); }
            else if (e.altKey && e.key.toLowerCase() === 'p') { e.preventDefault(); repaginate(); }
            else if (e.ctrlKey && e.key.toLowerCase() === 'o') { e.preventDefault(); showOverlay(); }
            // Ctrl+PageDown / Ctrl+PageUp = navigare pagina cu pagina (ca „browse by page" din Word)
            else if (e.ctrlKey && e.key === 'PageDown') { e.preventDefault(); gotoPage(1); }
            else if (e.ctrlKey && e.key === 'PageUp') { e.preventDefault(); gotoPage(-1); }
        });

        // ── Avertizare la închiderea aplicației dacă există modificări nesalvate ──
        // (browserul nu permite un pop-up propriu pe unload; afișează dialogul nativ.)
        window.addEventListener('beforeunload', e => {
            const cur = tabs.find(t => t.id === activeId);
            if (cur) cur.html = getDocHtml();
            if (tabs.some(t => isTabDirty(t))) { e.preventDefault(); e.returnValue = ''; return ''; }
        });

        // ── Init ──
        buildDiac();
        makeDraggable('findHandle', 'findPopup');
        makeDraggable('pathHandle', 'pathPopup');
        makeDraggable('diacHandle', 'diacPopup');
        setDocHtml('');   // o coala goala cu placeholder
        renderTabs();
        loadFileList('');
        renderRecent();
        showOverlay();    // popup de deschidere la pornire (ca în index V.4.php)
    </script>
</body>

</html>
