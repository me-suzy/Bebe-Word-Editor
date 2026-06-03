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

    // Nu lăsa warning-urile/notice-urile PHP (HTML) să polueze răspunsurile JSON
    // (altfel clientul primește „<br /> <b>Warning…" și r.json() crapă). Erorile rămân logate.
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');

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

    if ($action === 'autocorect_check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $words = isset($data['words']) && is_array($data['words']) ? $data['words'] : [];
        $dictCandidates = [
            __DIR__ . DIRECTORY_SEPARATOR . 'AutoCorect' . DIRECTORY_SEPARATOR . 'autocorect-words.txt',
            __DIR__ . DIRECTORY_SEPARATOR . 'autocorect-words.txt',
        ];
        $dictFile = '';
        foreach ($dictCandidates as $candidate) {
            if (is_file($candidate)) { $dictFile = $candidate; break; }
        }
        if (!$words || !is_file($dictFile)) {
            echo json_encode(['ok' => is_file($dictFile), 'found' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $normWord = function ($w) {
            $w = trim((string) $w);
            $w = strtr($w, [
                'Ş' => 'Ș', 'ş' => 'ș', 'Ţ' => 'Ț', 'ţ' => 'ț',
                'Á' => 'A', 'á' => 'a', 'É' => 'E', 'é' => 'e',
                'Í' => 'I', 'í' => 'i', 'Ó' => 'O', 'ó' => 'o',
                'Ú' => 'U', 'ú' => 'u'
            ]);
            $w = mb_strtolower($w, 'UTF-8');
            return preg_match('/^[a-zăâîșț]{2,40}$/u', $w) ? $w : '';
        };

        $wanted = [];
        foreach ($words as $w) {
            $n = $normWord($w);
            if ($n !== '') $wanted[$n] = true;
        }
        if (!$wanted) {
            echo json_encode(['ok' => true, 'found' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $found = [];
        $fh = @fopen($dictFile, 'rb');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if (isset($wanted[$line])) {
                    $found[$line] = true;
                    if (count($found) >= count($wanted)) break;
                }
            }
            fclose($fh);
        }

        echo json_encode(['ok' => true, 'found' => array_keys($found)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dex_user_words') {
        $userWordsFile = __DIR__ . DIRECTORY_SEPARATOR . 'user-words.txt';
        $normUserWord = function ($w) {
            $w = trim((string) $w);
            $w = mb_strtolower($w, 'UTF-8');
            return preg_match('/^\p{L}{2,40}$/u', $w) ? $w : '';
        };

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $words = [];
            if (is_file($userWordsFile)) {
                $lines = @file($userWordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) {
                    foreach ($lines as $line) {
                        $word = $normUserWord($line);
                        if ($word !== '') $words[$word] = true;
                    }
                }
            }
            $out = array_keys($words);
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            echo json_encode(['ok' => true, 'exists' => is_file($userWordsFile), 'words' => $out, 'file' => $userWordsFile], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $words = isset($data['words']) && is_array($data['words']) ? $data['words'] : [];
            $out = [];
            foreach ($words as $w) {
                $word = $normUserWord($w);
                if ($word !== '') $out[$word] = true;
            }
            $list = array_keys($out);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            $text = $list ? (implode("\n", $list) . "\n") : '';
            $ok = @file_put_contents($userWordsFile, $text, LOCK_EX);
            if ($ok === false) {
                echo json_encode(['ok' => false, 'error' => 'Nu pot scrie user-words.txt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'words' => $list, 'file' => $userWordsFile], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Metoda neacceptata'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'list') {
        global $ALLOWED_EXT;
        // path absolut (orice partiție/folder) sau ::drives (lista partițiilor); gol = $ROOT implicit
        $path = isset($_GET['path']) ? $_GET['path'] : (isset($_GET['dir']) ? $_GET['dir'] : '');
        $path = str_replace('\\', '/', $path);

        // lista partițiilor (This PC)
        if ($path === '::drives') {
            $drives = [];
            foreach (range('A', 'Z') as $L) {
                if (@is_dir($L . ':/'))
                    $drives[] = ['type' => 'dir', 'path' => $L . ':/', 'name' => $L . ':'];
            }
            echo json_encode(['ok' => true, 'mode' => 'drives', 'path' => '::drives', 'parent' => null, 'items' => $drives], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($path === '')
            $path = rtrim($ROOT, '/');
        $scanPath = $path;                       // ex: "E:/" sau "E:/Carte/BB"
        $out = [];
        if (is_dir($scanPath)) {
            $items = @scandir($scanPath);
            if ($items) foreach ($items as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                $full = rtrim($scanPath, '/') . '/' . $f;
                if (is_dir($full)) {
                    $out[] = ['type' => 'dir', 'path' => $full, 'name' => $f];
                } else {
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, $ALLOWED_EXT))
                        $out[] = ['type' => 'file', 'path' => $full, 'name' => $f, 'ext' => $ext];
                }
            }
        }
        usort($out, function ($a, $b) {
            if ($a['type'] !== $b['type'])
                return $a['type'] === 'dir' ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });
        // calea „înapoi"
        $norm = rtrim($scanPath, '/');
        if (preg_match('#^[A-Za-z]:$#', $norm)) {
            $parent = '::drives';               // suntem la rădăcina unei partiții → înapoi la lista de partiții
        } else {
            $parent = preg_replace('#/[^/]+$#', '', $norm);
            if (preg_match('#^[A-Za-z]:$#', $parent))
                $parent .= '/';                 // "E:" → "E:/"
            if ($parent === '')
                $parent = '::drives';
        }
        echo json_encode(['ok' => true, 'path' => $norm, 'parent' => $parent, 'items' => $out], JSON_UNESCAPED_UNICODE);
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
            echo json_encode(['ok' => false, 'error' => 'Conținut invalid (base64)']);
            exit;
        }
        // fișier existent dar read-only → încearcă să-l faci scriibil
        if (file_exists($full) && !is_writable($full))
            @chmod($full, 0666);
        $ok = @file_put_contents($full, $bytes);   // @ ca să nu emită warning HTML
        if ($ok === false) {
            $err = error_get_last();
            $msg = ($err && !empty($err['message'])) ? preg_replace('#^file_put_contents\([^)]*\):\s*#i', '', $err['message']) : '';
            $hint = 'Nu pot scrie fișierul. Probabil e deschis în Word/alt program (blocat) sau e read-only. Închide-l acolo și reîncearcă.';
            echo json_encode(['ok' => false, 'error' => $hint, 'detail' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'file' => $full, 'bytes' => strlen($bytes)]);
        exit;
    }

    // Cauta calea reala pe disc a unui fisier dupa nume (+ dimensiune) — pentru drag&drop,
    // ca sa putem face overwrite pe fisierul original fara sa intrebam unde.
    // Backup automat in folderul local Temp, fara sa modifice fisierul original.
    if ($action === 'autobackup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'Temp';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0777, true)) {
            echo json_encode(['ok' => false, 'error' => 'Nu pot crea folderul Temp']);
            exit;
        }

        $name = isset($_POST['fileName']) ? (string) $_POST['fileName'] : 'document.docx';
        $name = basename(str_replace('\\', '/', $name));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._-]+/u', '_', $base);
        $base = trim($base, '._-');
        if ($base === '') $base = 'document';
        if (mb_strlen($base, 'UTF-8') > 80) $base = mb_substr($base, 0, 80, 'UTF-8');
        $stamp = date('Ymd_His');

        $b64 = isset($_POST['content']) ? (string) $_POST['content'] : '';
        $b64 = preg_replace('#^data:[^,]+,#', '', $b64);
        if ($b64 !== '') {
            $bytes = base64_decode($b64, true);
            if ($bytes === false) {
                echo json_encode(['ok' => false, 'error' => 'Backup invalid (base64)']);
                exit;
            }
            $target = $tempDir . DIRECTORY_SEPARATOR . 'autosave_' . $stamp . '_' . $base . '.docx';
            $ok = @file_put_contents($target, $bytes);
            if ($ok === false) {
                echo json_encode(['ok' => false, 'error' => 'Nu pot scrie backup-ul in Temp']);
                exit;
            }
            echo json_encode(['ok' => true, 'file' => $target, 'bytes' => strlen($bytes)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $html = isset($_POST['html']) ? (string) $_POST['html'] : '';
        if ($html !== '') {
            $target = $tempDir . DIRECTORY_SEPARATOR . 'autosave_' . $stamp . '_' . $base . '.html';
            $ok = @file_put_contents($target, "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body>" . $html . "</body></html>");
            if ($ok === false) {
                echo json_encode(['ok' => false, 'error' => 'Nu pot scrie backup-ul HTML in Temp']);
                exit;
            }
            echo json_encode(['ok' => true, 'file' => $target, 'bytes' => strlen($html)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Backup fara continut']);
        exit;
    }

    if ($action === 'bookmarks') {
        $bookmarksFile = __DIR__ . DIRECTORY_SEPARATOR . 'semne-de-carte.json';

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $bookmarks = [];
            if (is_file($bookmarksFile)) {
                $raw = @file_get_contents($bookmarksFile);
                $decoded = json_decode($raw ?: '{}', true);
                if (is_array($decoded)) $bookmarks = $decoded;
            }
            echo json_encode(['ok' => true, 'file' => $bookmarksFile, 'bookmarks' => (object) $bookmarks], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw ?: '{}', true);
            $bookmarks = isset($data['bookmarks']) && is_array($data['bookmarks']) ? $data['bookmarks'] : [];
            $json = json_encode((object) $bookmarks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                echo json_encode(['ok' => false, 'error' => 'JSON invalid pentru semne-de-carte'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $tmp = $bookmarksFile . '.tmp';
            $ok = @file_put_contents($tmp, $json . "\n", LOCK_EX);
            if ($ok === false || !@rename($tmp, $bookmarksFile)) {
                @unlink($tmp);
                echo json_encode(['ok' => false, 'error' => 'Nu pot scrie semne-de-carte.json'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'file' => $bookmarksFile, 'bookmarks' => (object) $bookmarks], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Metoda neacceptata'], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

        .topbtn.bookmark-btn {
            min-width: 36px;
            padding-left: 8px;
            padding-right: 8px;
            background: #b91c1c;
            border-color: #7f1d1d;
            font-weight: 800;
            letter-spacing: .3px;
        }

        .topbtn.bookmark-btn.has-bookmark {
            background: #047857;
            border-color: #065f46;
        }

        .topbtn.bookmark-btn:hover {
            filter: brightness(1.08);
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

        /* ── Ribbon stil Word: Paste mare, butoane cu etichetă, split-button cu caret ── */
        .clip-row {
            display: flex;
            align-items: stretch;
            gap: 3px;
        }

        .rbtn-big {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            min-width: 52px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 3px;
            cursor: pointer;
            padding: 3px 6px;
            color: #201f1e;
        }

        .rbtn-big:hover {
            background: var(--btn-hover);
            border-color: #c8c6c4;
        }

        .rbtn-big .big-ico {
            font-size: 20px;
            line-height: 1;
        }

        .rbtn-big .big-lbl {
            font-size: 11px;
        }

        .rbtn-big .caret {
            font-size: 8px;
            color: #605e5c;
        }

        .clip-col {
            display: flex;
            flex-direction: column;
            gap: 1px;
            justify-content: center;
        }

        .rbtn-lbl {
            display: flex;
            align-items: center;
            gap: 5px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 3px;
            cursor: pointer;
            padding: 2px 7px 2px 4px;
            font-size: 12px;
            color: #201f1e;
            white-space: nowrap;
            text-align: left;
        }

        .rbtn-lbl:hover {
            background: var(--btn-hover);
            border-color: #c8c6c4;
        }

        .rbtn-lbl.active {
            background: var(--accent-light);
            border-color: #9db8de;
        }

        .rbtn-lbl .ei {
            color: var(--accent);
            width: 16px;
            text-align: center;
        }

        .rbtn .caret {
            font-size: 8px;
            color: #605e5c;
            margin-left: 1px;
        }

        /* split button: acțiune principală + caret separat, lipite vizual */
        .rsplit {
            display: inline-flex;
            align-items: stretch;
        }

        .rsplit .rbtn {
            border-radius: 3px 0 0 3px;
            padding-right: 3px;
        }

        .rsplit .rcaret {
            min-width: 14px;
            padding: 0 2px;
            border-radius: 0 3px 3px 0;
            border-left: 1px solid transparent;
        }

        .rsplit:hover .rbtn,
        .rsplit:hover .rcaret {
            border-color: #c8c6c4;
        }

        .rgroup-label {
            position: relative;
        }

        .rlaunch {
            font-size: 9px;
            color: #a19f9d;
            margin-left: 4px;
            cursor: pointer;
        }

        /* meniu pop generic (Change Case, Borders, Line spacing, etc.) */
        .pop-menu {
            position: fixed;
            z-index: 1700;
            background: #fff;
            border: 1px solid #c8c6c4;
            border-radius: 4px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .22);
            padding: 4px;
            min-width: 180px;
            user-select: none;
        }

        .pm-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 6px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }

        .pm-item:hover {
            background: var(--accent-light);
        }

        .pm-item .pmi {
            width: 20px;
            text-align: center;
            color: var(--accent);
        }

        .pm-sep {
            height: 1px;
            background: #e1dfdd;
            margin: 4px 2px;
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

        .sb-toolbar {
            display: flex;
            gap: 4px;
            margin-bottom: 4px;
        }

        .sb-toolbar button {
            flex: 1;
            background: #fff;
            border: 1px solid #c8c6c4;
            border-radius: 4px;
            padding: 4px 6px;
            cursor: pointer;
            font-size: 12px;
        }

        .sb-toolbar button:hover {
            background: var(--btn-hover);
        }

        .sb-path {
            font-size: 11px;
            color: #605e5c;
            background: #eee;
            border-radius: 4px;
            padding: 3px 6px;
            margin-bottom: 6px;
            word-break: break-all;
            display: none;
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
            position: relative;
            flex: 1;
            overflow: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #edebe9;
            scroll-behavior: smooth;
        }

        .bookmark-marker {
            position: absolute;
            z-index: 80;
            display: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #b91c1c;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .28);
            color: #fff;
            font: 800 13px/18px "Segoe UI", Tahoma, sans-serif;
            text-align: center;
            pointer-events: none;
            user-select: none;
        }

        .bookmark-marker.show {
            display: block;
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

        /* interfața (bara de sus, ribbon, status, sidebar) NU trebuie să intre în selecția de text din document */
        .topbar,
        .ribbon,
        .statusbar,
        .sidebar {
            user-select: none;
            -webkit-user-select: none;
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

        /* Compare documents */
        .compare-card {
            width: min(760px, 94vw);
            color: #201f1e;
            background: #f7f7f7;
            border-radius: 10px;
            padding: 18px 22px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, .42);
        }

        .compare-titlebar {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            margin-bottom: 14px;
        }

        .compare-titlebar .spacer {
            flex: 1;
        }

        .compare-x {
            border: none;
            background: transparent;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            color: #201f1e;
        }

        .compare-doc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 28px;
            margin-bottom: 18px;
        }

        .compare-doc-box {
            border-right: 1px solid #c8c6c4;
            padding-right: 18px;
        }

        .compare-doc-box:last-child {
            border-right: none;
            padding-right: 0;
        }

        .compare-label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .compare-picker-row,
        .compare-label-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
        }

        .compare-file-name,
        .compare-label-row input {
            flex: 1;
            min-width: 0;
            height: 27px;
            border: 1px solid #b8b8b8;
            background: #fff;
            padding: 4px 8px;
            font-size: 13px;
            color: #201f1e;
        }

        .compare-file-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: flex;
            align-items: center;
        }

        .compare-browse {
            height: 27px;
            border: 1px solid #b8b8b8;
            background: #fff;
            border-radius: 3px;
            cursor: pointer;
            padding: 0 10px;
        }

        .compare-settings {
            border-top: 1px solid #d6d6d6;
            padding-top: 10px;
            font-size: 13px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 28px;
        }

        .compare-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 18px;
        }

        .compare-actions button {
            min-width: 110px;
            border: 1px solid #c8c6c4;
            background: #fff;
            border-radius: 6px;
            padding: 8px 18px;
            cursor: pointer;
        }

        .compare-actions button.primary {
            background: #107c10;
            color: #fff;
            border-color: #107c10;
            font-weight: 600;
        }

        .compare-actions button:disabled {
            opacity: .55;
            cursor: default;
        }

        .compare-result-card {
            width: min(1320px, 96vw);
            height: min(860px, 92vh);
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
            color: #201f1e;
            border-radius: 10px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, .42);
            overflow: hidden;
        }

        .compare-result-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #1f2937;
            color: #fff;
        }

        .compare-result-head .spacer {
            flex: 1;
        }

        .compare-result-head button {
            border: 1px solid rgba(255, 255, 255, .35);
            background: rgba(255, 255, 255, .12);
            color: #fff;
            border-radius: 5px;
            padding: 6px 10px;
            cursor: pointer;
        }

        .compare-result-head button.active {
            background: rgba(34, 197, 94, .24);
            border-color: rgba(134, 239, 172, .70);
        }

        .compare-result-meta {
            color: #9ca3af;
            font-size: 12px;
            padding-left: 6px;
        }

        .compare-panes {
            flex: 1;
            min-height: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-top: 1px solid #d1d5db;
            overflow: hidden;
        }

        .compare-pane {
            min-width: 0;
            min-height: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #d1d5db;
            overflow: hidden;
        }

        .compare-pane:last-child {
            border-right: none;
        }

        .compare-pane-title {
            padding: 9px 12px;
            background: #e5e7eb;
            border-bottom: 1px solid #d1d5db;
            font-weight: 700;
            font-size: 13px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .compare-pane-body {
            flex: 1;
            min-height: 0;
            overflow-x: auto;
            overflow-y: scroll;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            padding: 18px 22px;
            background: #fff;
            font-family: "Times New Roman", serif;
            font-size: 17px;
            line-height: 1.55;
        }

        .compare-block {
            margin: 0 0 12px;
            white-space: pre-wrap;
        }

        .compare-empty-block {
            color: #9ca3af;
            font-style: italic;
        }

        .compare-word-diff,
        .compare-word-diac,
        .compare-word-text {
            text-decoration-line: underline;
            text-decoration-style: wavy;
            text-decoration-thickness: 1.5px;
            text-underline-offset: 3px;
            border-radius: 2px;
        }

        .compare-word-diff,
        .compare-word-diac {
            text-decoration-color: #16a34a;
            background: rgba(34, 197, 94, .10);
        }

        .compare-result-card.hide-diacritics .compare-word-diff,
        .compare-result-card.hide-diacritics .compare-word-diac {
            text-decoration: none;
            background: transparent;
        }

        .compare-word-text {
            text-decoration-color: #dc2626;
            background: rgba(239, 68, 68, .12);
        }

        @media (max-width: 800px) {
            .compare-doc-grid,
            .compare-settings,
            .compare-panes {
                grid-template-columns: 1fr;
            }

            .compare-doc-box {
                border-right: none;
                border-bottom: 1px solid #c8c6c4;
                padding: 0 0 12px;
            }

            .compare-result-card {
                height: 94vh;
            }
        }

        /* ── Grup Editing (Find / Replace / Select), stil Word ── */
        .editing-col {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .ebtn {
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 3px;
            cursor: pointer;
            padding: 3px 8px 3px 5px;
            font-size: 12.5px;
            color: #201f1e;
            white-space: nowrap;
            text-align: left;
        }

        .ebtn:hover {
            background: var(--btn-hover);
            border-color: #c8c6c4;
        }

        .ebtn .ei {
            color: var(--accent);
            font-size: 13px;
            width: 15px;
            text-align: center;
        }

        .ebtn .caret {
            margin-left: auto;
            font-size: 9px;
            color: #605e5c;
        }

        .select-menu {
            position: fixed;
            z-index: 1500;
            background: #fff;
            border: 1px solid #c8c6c4;
            border-radius: 4px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .22);
            padding: 4px;
            min-width: 290px;
            display: none;
        }

        .select-menu.open {
            display: block;
        }

        .select-menu .item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 7px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
        }

        .select-menu .item:hover {
            background: var(--accent-light);
        }

        .select-menu .item .mi {
            color: var(--accent);
            width: 18px;
            text-align: center;
        }

        .select-menu .sep {
            height: 1px;
            background: #e1dfdd;
            margin: 4px 2px;
        }

        /* ── Dialog Find and Replace (stil Word) ── */
        .fr-dialog {
            position: fixed;
            top: 90px;
            left: 50%;
            transform: translateX(-50%);
            width: 560px;
            max-width: 95vw;
            background: #f3f2f1;
            border: 1px solid #b9b7b4;
            border-radius: 6px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, .35);
            z-index: 2500;
            display: none;
            font-size: 13px;
            color: #201f1e;
        }

        .fr-dialog.open {
            display: block;
        }

        .fr-titlebar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            background: #fff;
            border-bottom: 1px solid #e1dfdd;
            border-radius: 6px 6px 0 0;
            cursor: move;
            font-weight: 600;
        }

        .fr-titlebar .x {
            cursor: pointer;
            color: #605e5c;
            font-size: 16px;
        }

        .fr-tabs {
            display: flex;
            gap: 2px;
            padding: 8px 12px 0;
        }

        .fr-tab {
            padding: 5px 16px;
            border: 1px solid #c8c6c4;
            border-bottom: none;
            background: #e6e4e2;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
        }

        .fr-tab.active {
            background: #fff;
            font-weight: 600;
        }

        .fr-body {
            background: #fff;
            border: 1px solid #c8c6c4;
            margin: 0 12px;
            padding: 14px;
        }

        .fr-field {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .fr-field label {
            width: 86px;
            text-align: right;
            color: #201f1e;
        }

        .fr-field input[type=text] {
            flex: 1;
            padding: 4px 7px;
            border: 1px solid #8a8886;
            border-radius: 2px;
            font-size: 13px;
        }

        .fr-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .fr-actions.left {
            justify-content: space-between;
        }

        .fr-btn {
            padding: 5px 14px;
            border: 1px solid #8a8886;
            background: #fdfdfd;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12.5px;
            min-width: 84px;
        }

        .fr-btn:hover {
            background: #eaeaea;
        }

        .fr-btn:disabled {
            color: #a19f9d;
            cursor: default;
            background: #f3f2f1;
        }

        .fr-options {
            border-top: 1px solid #e1dfdd;
            margin-top: 12px;
            padding-top: 10px;
            display: none;
        }

        .fr-options.open {
            display: block;
        }

        .fr-opt-title {
            font-weight: 600;
            color: #605e5c;
            margin-bottom: 8px;
        }

        .fr-search-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .fr-checks {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 18px;
        }

        .fr-checks label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
        }

        .fr-checks label.disabled {
            color: #a19f9d;
        }

        .fr-format-row {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            border-top: 1px solid #e1dfdd;
            padding-top: 10px;
        }

        .fr-footer {
            padding: 10px 12px;
        }

        /* ── Galerie stiluri (tip Word) ── */
        .style-gallery {
            position: fixed;
            z-index: 1600;
            background: #fff;
            border: 1px solid #c8c6c4;
            border-radius: 5px;
            box-shadow: 0 8px 26px rgba(0, 0, 0, .25);
            padding: 8px;
            width: 560px;
            max-width: 95vw;
            display: none;
            user-select: none;
        }

        .style-gallery.open {
            display: block;
        }

        .sg-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
        }

        .sg-item {
            position: relative;
            border: 1px solid #e1dfdd;
            border-radius: 3px;
            padding: 8px 6px;
            min-height: 44px;
            cursor: pointer;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            white-space: nowrap;
            font-size: 13px;
            color: #201f1e;
        }

        .sg-star {
            position: absolute;
            top: 1px;
            right: 3px;
            color: #f5c518;
            font-size: 13px;
            text-shadow: 0 0 1px #b8860b;
            pointer-events: none;
        }

        .style-gallery.editmode .sg-item {
            border-color: #f5c518;
        }

        .sg-item:hover {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent) inset;
        }

        .sg-item.custom {
            border-style: dashed;
        }

        .sg-footer {
            border-top: 1px solid #e1dfdd;
            margin-top: 8px;
            padding-top: 6px;
        }

        .sg-foot-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
        }

        .sg-foot-item:hover {
            background: var(--accent-light);
        }

        .sg-ico {
            font-weight: 700;
            width: 20px;
            text-align: center;
        }

        /* aspectul fiecărui stil (folosit și ca preview în galerie, și în document) */
        .st-h1 {
            color: #2e74b5;
            font-size: 16pt;
            font-weight: 600;
        }

        .st-h2 {
            color: #2e74b5;
            font-size: 13pt;
            font-weight: 600;
        }

        .st-h3 {
            color: #1f4e79;
            font-size: 12pt;
            font-weight: 600;
        }

        .st-title {
            color: #000;
            font-size: 26pt;
            font-weight: 400;
        }

        .st-subtitle {
            color: #8a8886;
            font-size: 12pt;
            letter-spacing: .5px;
        }

        .st-quote {
            color: #404040;
            font-style: italic;
        }

        .st-intense-quote {
            color: #2e74b5;
            font-style: italic;
            border-top: 1px solid #2e74b5;
            border-bottom: 1px solid #2e74b5;
            padding: 4px 0;
        }

        .st-emphasis {
            font-style: italic;
        }

        .st-intense-emphasis {
            font-style: italic;
            color: #2e74b5;
        }

        .st-strong {
            font-weight: 700;
        }

        .st-book-title {
            font-weight: 700;
            font-style: italic;
        }

        .st-nospacing {
            margin: 0 !important;
        }

        .st-code {
            font-family: "Courier New", monospace;
            background: #f3f3f3;
            color: #333;
        }

        /* DEX — cuvinte greșite / fără diacritice (subliniere ondulată roșie) */
        .dex-bad {
            text-decoration: underline wavy #d13438;
            text-decoration-skip-ink: none;
            text-underline-offset: 2px;
        }

        #btnDex.active {
            background: var(--accent-light);
            border-color: #9db8de;
        }

        .dex-context-menu {
            position: fixed;
            display: none;
            min-width: 230px;
            max-width: min(320px, calc(100vw - 16px));
            background: #fff;
            color: #201f1e;
            border: 1px solid #c8c6c4;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .22);
            z-index: 4500;
            overflow: hidden;
            font-size: 13px;
        }

        .dex-context-menu.open {
            display: block;
        }

        .dex-context-head {
            padding: 8px 11px;
            border-bottom: 1px solid #edebe9;
            color: #605e5c;
            line-height: 1.35;
        }

        .dex-context-word {
            color: #201f1e;
            font-weight: 600;
            word-break: break-word;
        }

        .dex-context-menu button {
            display: block;
            width: 100%;
            padding: 8px 11px;
            border: 0;
            background: #fff;
            color: #201f1e;
            text-align: left;
            cursor: pointer;
            font: inherit;
        }

        .dex-context-menu button:hover:not(:disabled) {
            background: #f3f2f1;
        }

        .dex-context-menu button:disabled {
            color: #8a8886;
            cursor: default;
        }

        .dex-context-input {
            width: calc(100% - 22px);
            margin: 8px 11px 6px;
            padding: 7px 8px;
            border: 1px solid #c8c6c4;
            border-radius: 4px;
            font: inherit;
            box-sizing: border-box;
        }

        .dex-context-menu button.dex-danger {
            color: #a4262c;
        }

        .dex-context-menu button.dex-danger:hover:not(:disabled) {
            background: #fde7e9;
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
        <button class="topbtn" onclick="openCompareDialog()" title="Compara doua documente">Compare</button>
        <button class="topbtn bookmark-btn" id="bookmarkBtn" onclick="toggleBookmark(event)" title="Semn de carte">T</button>
        <button class="topbtn" onclick="closeActiveTab()" title="Inchide tab-ul curent">Inchide tab</button>
        <button class="topbtn primary" onclick="saveDocx()">💾 Salveaza (Ctrl+S)</button>
        <button class="topbtn" onclick="closeApplication()" title="Iesire cu confirmare salvare">Iesire</button>
    </div>

    <!-- RIBBON (Word classic Home tab + extra din Mini Dreamweaver) -->
    <div class="ribbon" id="ribbon">
        <!-- Clipboard -->
        <div class="rgroup">
            <div class="clip-row">
                <button class="rbtn-big" title="Lipește (Ctrl+V)" onclick="doPaste()">
                    <span class="big-ico">📋</span><span class="big-lbl">Paste</span><span class="caret">▾</span>
                </button>
                <div class="clip-col">
                    <button class="rbtn-lbl" title="Decupează (Ctrl+X)" onclick="cmd('cut')"><span class="ei">✂</span> Cut</button>
                    <button class="rbtn-lbl" title="Copiază (Ctrl+C)" onclick="cmd('copy')"><span class="ei">⧉</span> Copy</button>
                    <button class="rbtn-lbl" id="btnPainter" title="Descriptor de formate: click pe sursă → click aici → selectează/click pe țintă" onclick="toggleFormatPainter()"><span class="ei">🖌️</span> Format Painter</button>
                </div>
            </div>
            <div class="rgroup-label">Clipboard <span class="rlaunch" title="(grup)">⤡</span></div>
        </div>

        <!-- Font -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:360px">
                <select class="rsel" id="fontFamily" title="Font" onchange="setFont(this.value)" style="width:160px">
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
                <button class="rbtn" title="Mărește fontul" onclick="growFont(1)">A<sup style="font-size:8px">▲</sup></button>
                <button class="rbtn" title="Micșorează fontul" onclick="growFont(-1)">A<sub style="font-size:8px">▼</sub></button>
                <button class="rbtn" title="Schimbă registrul" onclick="changeCaseMenu(event)">Aa<span class="caret">▾</span></button>
                <button class="rbtn" title="Golește toată formatarea" onclick="clearAllFormatting()"><span style="position:relative">A<span style="position:absolute;right:-4px;bottom:-2px;color:#c0392b;font-size:9px">✕</span></span></button>
            </div>
            <div class="rgroup-row" style="max-width:360px">
                <button class="rbtn" id="b_bold" title="Aldin (Ctrl+B)" onclick="cmd('bold')"><b>B</b></button>
                <button class="rbtn" id="b_italic" title="Cursiv (Ctrl+I)" onclick="cmd('italic')"><i>I</i></button>
                <button class="rbtn" id="b_underline" title="Subliniat (Ctrl+U)" onclick="cmd('underline')"><u>U</u></button>
                <button class="rbtn" id="b_strike" title="Tăiat" onclick="cmd('strikeThrough')"><s>ab</s></button>
                <button class="rbtn" title="Indice" onclick="cmd('subscript')">x<sub>2</sub></button>
                <button class="rbtn" title="Exponent" onclick="cmd('superscript')">x<sup>2</sup></button>
                <button class="rbtn" title="Efecte text" onclick="textEffectsMenu(event)"><span style="text-shadow:1px 1px 0 #9aa3b2">A</span><span class="caret">▾</span></button>
                <span class="rsplit" title="Culoare evidențiere">
                    <label class="rbtn" style="position:relative"><span style="border-bottom:3px solid #ffd400">🖍️</span>
                        <input type="color" id="hiliteColor" value="#ffff00" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="setHilite(this.value)"></label>
                    <button class="rbtn rcaret" title="Alege culoare evidențiere" onclick="document.getElementById('hiliteColor').click()"><span class="caret">▾</span></button>
                </span>
                <span class="rsplit" title="Culoare font">
                    <label class="rbtn" style="position:relative;font-weight:700"><span style="border-bottom:3px solid #c00">A</span>
                        <input type="color" id="foreColor" value="#cc0000" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="setColor(this.value)"></label>
                    <button class="rbtn rcaret" title="Alege culoare font" onclick="document.getElementById('foreColor').click()"><span class="caret">▾</span></button>
                </span>
            </div>
            <div class="rgroup-label">Font <span class="rlaunch" title="(grup)">⤡</span></div>
        </div>

        <!-- Paragraph -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:300px">
                <span class="rsplit">
                    <button class="rbtn" title="Marcatori" onclick="cmd('insertUnorderedList')">•≡</button>
                    <button class="rbtn rcaret" title="Stil marcatori" onclick="bulletsMenu(event)"><span class="caret">▾</span></button>
                </span>
                <span class="rsplit">
                    <button class="rbtn" title="Numerotare" onclick="cmd('insertOrderedList')">1.≡</button>
                    <button class="rbtn rcaret" title="Format numerotare" onclick="numberingMenu(event)"><span class="caret">▾</span></button>
                </span>
                <button class="rbtn" title="Listă pe mai multe niveluri" onclick="multilevelList()">⁞≡</button>
                <button class="rbtn" title="Micșorează indentul" onclick="cmd('outdent')">⇤</button>
                <button class="rbtn" title="Mărește indentul" onclick="cmd('indent')">⇥</button>
                <button class="rbtn" title="Sortează alfabetic paragrafele selectate" onclick="sortParagraphs(event)">A↓Z</button>
                <button class="rbtn" id="btnMarks" title="Afișează marcaje de paragraf (¶)" onclick="toggleMarks()">¶</button>
            </div>
            <div class="rgroup-row" style="max-width:300px">
                <button class="rbtn" id="al_left" title="Aliniere stânga (Ctrl+L)" onclick="cmd('justifyLeft')">☰</button>
                <button class="rbtn" id="al_center" title="Centrat (Ctrl+E)" onclick="cmd('justifyCenter')">≡</button>
                <button class="rbtn" id="al_right" title="Aliniere dreapta (Ctrl+R)" onclick="cmd('justifyRight')">☰</button>
                <button class="rbtn" id="al_just" title="Stânga-dreapta (Ctrl+J)" onclick="cmd('justifyFull')">▤</button>
                <button class="rbtn" title="Spațiere între rânduri" onclick="lineSpacingMenu(event)">↕<span class="caret">▾</span></button>
                <span class="rsplit" title="Umbrire (fundal)">
                    <label class="rbtn" style="position:relative"><span style="border-bottom:3px solid #ffd400">🪣</span>
                        <input type="color" id="bgColor" value="#ffff99" style="position:absolute;inset:0;opacity:0;cursor:pointer" onchange="applyShading(this.value)"></label>
                    <button class="rbtn rcaret" title="Alege culoare umbrire" onclick="document.getElementById('bgColor').click()"><span class="caret">▾</span></button>
                </span>
                <button class="rbtn" title="Borduri" onclick="bordersMenu(event)">▦<span class="caret">▾</span></button>
            </div>
            <div class="rgroup-label">Paragraf</div>
        </div>

        <!-- Styles (galerie tip Word) -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:150px">
                <button class="rsel" id="styleBtn" title="Stiluri" onclick="toggleStyleGallery(event)"
                    style="width:138px;display:flex;align-items:center;justify-content:space-between;background:#fff;cursor:pointer">
                    <span id="styleBtnLabel">Normal</span><span style="font-size:9px;color:#605e5c">▾</span>
                </button>
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

        <!-- Extra (Mini Dreamweaver) -->
        <div class="rgroup">
            <div class="rgroup-row" style="max-width:200px">
                <button class="rbtn" title="Anulează (Ctrl+Z)" onclick="doUndo()">↶ Undo</button>
                <button class="rbtn" title="Refă (Ctrl+Y)" onclick="doRedo()">↷ Redo</button>
                <button class="rbtn" title="Re-paginează pe coli A4 (Alt+P)" onclick="repaginate()">⇲¶</button>
                <div class="rsep"></div>
                <button class="rbtn" title="Diacritice românești" onclick="toggleDiac()" style="font-size:15px">Ă</button>
                <button class="rbtn" title="Traducere (Google Translate)" onclick="openTranslate()">🌐</button>
                <button class="rbtn" id="btnDex" title="DEX — verifică ortografia românească (online + AutoCorect local + dicționar personal)" onclick="toggleDex()" style="font-weight:700;color:#2b579a">DEX</button>
            </div>
            <div class="rgroup-label">Extra</div>
        </div>

        <!-- Editing (stil Word: Find / Replace / Select) -->
        <div class="rgroup">
            <div class="editing-col">
                <button class="ebtn" title="Găsește (Ctrl+F)" onclick="openFindReplace('find')"><span class="ei">🔍</span> Find <span class="caret">▾</span></button>
                <button class="ebtn" title="Înlocuiește (Ctrl+H)" onclick="openFindReplace('replace')"><span class="ei">⇄</span> Replace</button>
                <button class="ebtn" id="btnSelect" title="Selectează" onclick="toggleSelectMenu(event)"><span class="ei">▦</span> Select <span class="caret">▾</span></button>
            </div>
            <div class="rgroup-label">Editing</div>
        </div>
    </div>

    <!-- Meniu „Select" (ca în Word) -->
    <div class="select-menu" id="selectMenu">
        <div class="item" onclick="selSelectAll()"><span class="mi">▣</span> Select All <span style="margin-left:auto;color:#a19f9d;font-size:11px">Ctrl+A</span></div>
        <div class="item" onclick="selSelectObjects()"><span class="mi">⬚</span> Select Objects</div>
        <div class="item" onclick="selSelectSimilar()"><span class="mi">¶</span> Select All Text With Similar Formatting</div>
        <div class="sep"></div>
        <div class="item" onclick="selOpenSelectionPane()"><span class="mi">☰</span> Selection Pane…</div>
    </div>

    <!-- MAIN -->
    <div class="main">
        <div class="sidebar" id="sidebar">
            <div class="sb-toolbar">
                <button onclick="loadFileList('::drives')" title="Toate partițiile (This PC)">💻 This PC</button>
                <button onclick="loadFileList('')" title="Folder implicit">🏠</button>
            </div>
            <div id="sbPath" class="sb-path" title=""></div>
            <h3 id="sbRecentHdr" style="display:none">Recente</h3>
            <div id="sbRecent"></div>
            <h3>Foldere &amp; fișiere</h3>
            <div id="fileList"></div>
        </div>
        <div class="canvas" id="canvas">
            <div id="pages" contenteditable="true" spellcheck="false"></div>
            <div id="bookmarkMarker" class="bookmark-marker" contenteditable="false" aria-hidden="true">T</div>
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

    <!-- DIALOG: Find and Replace (stil Word) -->
    <div class="fr-dialog" id="frDialog">
        <div class="fr-titlebar" id="frTitle">Find and Replace <span class="x" onclick="closeFR()">&times;</span></div>
        <div class="fr-tabs">
            <div class="fr-tab" id="frTabFind" onclick="frSwitchTab('find')">Find</div>
            <div class="fr-tab" id="frTabReplace" onclick="frSwitchTab('replace')">Replace</div>
            <div class="fr-tab" id="frTabGoto" onclick="frSwitchTab('goto')">Go To</div>
        </div>
        <div class="fr-body">
            <!-- Find / Replace -->
            <div id="frPaneFR">
                <div class="fr-field"><label for="frFind">Find what:</label><input type="text" id="frFind"></div>
                <div class="fr-field" id="frReplaceField"><label for="frReplace">Replace with:</label><input type="text" id="frReplace"></div>
                <div class="fr-actions left">
                    <button class="fr-btn" id="frMoreBtn" onclick="frToggleMore()">&lt;&lt; Less</button>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="fr-btn" id="frReplaceBtn" onclick="frReplace()">Replace</button>
                        <button class="fr-btn" id="frReplaceAllBtn" onclick="frReplaceAll()">Replace All</button>
                        <button class="fr-btn" onclick="frFindNext(false)">Find Next</button>
                        <button class="fr-btn" onclick="closeFR()">Cancel</button>
                    </div>
                </div>

                <div class="fr-options open" id="frOptions">
                    <div class="fr-opt-title">Search Options</div>
                    <div class="fr-search-row">
                        <label for="frDir">Search:</label>
                        <select id="frDir" class="rsel" style="height:26px">
                            <option value="all">All</option>
                            <option value="down">Down</option>
                            <option value="up">Up</option>
                        </select>
                    </div>
                    <div class="fr-checks">
                        <label><input type="checkbox" id="frMatchCase"> Match case</label>
                        <label><input type="checkbox" id="frPrefix"> Match prefix</label>
                        <label><input type="checkbox" id="frWhole"> Find whole words only</label>
                        <label><input type="checkbox" id="frSuffix"> Match suffix</label>
                        <label><input type="checkbox" id="frWildcards"> Use wildcards (regex)</label>
                        <label class="disabled"><input type="checkbox" disabled> Ignore punctuation characters</label>
                        <label class="disabled"><input type="checkbox" disabled> Sounds like (English)</label>
                        <label><input type="checkbox" id="frIgnoreSpace"> Ignore white-space characters</label>
                        <label class="disabled"><input type="checkbox" disabled> Find all word forms (English)</label>
                    </div>
                    <div class="fr-format-row">
                        <button class="fr-btn" onclick="toast('Format pe selecție: folosește butoanele din ribbon')">Format ▾</button>
                        <button class="fr-btn" id="frSpecialBtn" onclick="frSpecial(event)">Special ▾</button>
                        <button class="fr-btn" onclick="document.getElementById('frFind').value='';document.getElementById('frReplace').value='';">No Formatting</button>
                    </div>
                </div>
            </div>

            <!-- Go To -->
            <div id="frPaneGoto" style="display:none">
                <div class="fr-field"><label for="frGoto">Pagina:</label><input type="text" id="frGoto" placeholder="Nr. pagină (1…N)"
                        onkeydown="if(event.key==='Enter'){event.preventDefault();frGoTo();}"></div>
                <div class="fr-actions">
                    <button class="fr-btn" onclick="frGoToRel(-1)">Previous</button>
                    <button class="fr-btn" onclick="frGoToRel(1)">Next</button>
                    <button class="fr-btn" onclick="frGoTo()">Go To</button>
                    <button class="fr-btn" onclick="closeFR()">Cancel</button>
                </div>
            </div>
        </div>
        <div class="fr-footer"></div>
    </div>

    <!-- mini-meniu pentru „Special ▾" -->
    <div class="select-menu" id="frSpecialMenu" style="min-width:200px">
        <div class="item" onclick="frInsertSpecial('^p')"><span class="mi">¶</span> Paragraph Mark (^p)</div>
        <div class="item" onclick="frInsertSpecial('^t')"><span class="mi">⇥</span> Tab Character (^t)</div>
        <div class="item" onclick="frInsertSpecial('^?')"><span class="mi">?</span> Any Character (^?)</div>
        <div class="item" onclick="frInsertSpecial('^#')"><span class="mi">#</span> Any Digit (^#)</div>
    </div>

    <!-- Selection Pane -->
    <div class="popup" id="selPane" style="width:280px">
        <h4 id="selPaneHandle">Selection Pane <span class="close-x" onclick="document.getElementById('selPane').classList.remove('open')">&times;</span></h4>
        <div class="body" id="selPaneBody" style="max-height:300px;overflow:auto"></div>
    </div>

    <!-- Galerie stiluri (tip Word) -->
    <div class="style-gallery" id="styleGallery">
        <div class="sg-grid" id="sgGrid"></div>
        <div class="sg-footer">
            <div class="sg-foot-item" onmousedown="event.preventDefault()" onclick="openCreateStyle()"><span class="sg-ico" style="color:#107c10">A₊</span> Create a Style</div>
            <div class="sg-foot-item" id="sgEditToggle" onmousedown="event.preventDefault()" onclick="toggleEditStyleMode()"><span class="sg-ico" style="color:#2b579a">✎</span> Edit a Style</div>
            <div class="sg-foot-item" onmousedown="event.preventDefault()" onclick="clearFormatting()"><span class="sg-ico" style="color:#b146c2">A⬦</span> Clear Formatting</div>
        </div>
    </div>

    <!-- Dialog „Create a Style" -->
    <div class="overlay" id="csOverlay" style="z-index:3500">
        <div class="overlay-card" style="width:min(440px,92vw)">
            <div class="overlay-title" style="font-size:19px" id="csTitle">Creează un stil nou</div>
            <div class="overlay-sub">Definește formatarea și salvează stilul ca să-l refolosești.</div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <label>Nume stil:<br><input type="text" id="csName" placeholder="Ex: Subtitlu roșu" style="width:100%;background:#15161d;border:1px solid #3a3f4f;border-radius:6px;padding:8px;color:#eee"></label>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <label>Font:<br>
                        <select id="csFont" style="background:#15161d;border:1px solid #3a3f4f;border-radius:6px;padding:7px;color:#eee">
                            <option value="">(moștenit)</option>
                            <option>Times New Roman</option>
                            <option>Arial</option>
                            <option>Calibri</option>
                            <option>Georgia</option>
                            <option>Verdana</option>
                            <option>Tahoma</option>
                            <option>Courier New</option>
                        </select></label>
                    <label>Mărime (pt):<br><input type="number" id="csSize" min="6" max="120" placeholder="(moștenit)" style="width:110px;background:#15161d;border:1px solid #3a3f4f;border-radius:6px;padding:7px;color:#eee"></label>
                    <label>Culoare:<br><input type="color" id="csColor" value="#000000" style="width:48px;height:36px;border:1px solid #3a3f4f;border-radius:6px;background:#15161d"></label>
                </div>
                <div style="display:flex;gap:16px;align-items:center">
                    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="csBold"> Aldin</label>
                    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="csItalic"> Cursiv</label>
                    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="csUnderline"> Subliniat</label>
                    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="csColorOn" checked> Aplică culoarea</label>
                </div>
                <div id="csPreview" style="background:#fff;color:#000;border-radius:6px;padding:12px;min-height:38px">Exemplu de text cu acest stil</div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                <button onclick="document.getElementById('csOverlay').classList.remove('open')" style="background:transparent;border:1px solid #3a3f4f;color:#cdd3df;border-radius:6px;padding:8px 16px;cursor:pointer">Anulează</button>
                <button onclick="saveCustomStyle()" style="background:#107c10;border:none;color:#fff;border-radius:6px;padding:8px 18px;cursor:pointer;font-weight:600">💾 Salvează stilul</button>
            </div>
        </div>
    </div>

    <!-- Dialog: Inserează imagine (din computer sau din web) -->
    <div class="overlay" id="imgOverlay" style="z-index:3500">
        <div class="overlay-card" style="width:min(460px,92vw)">
            <div class="overlay-title" style="font-size:19px">Inserează imagine</div>
            <div class="overlay-sub">Alege o imagine de pe computer sau lipește un link de pe web.</div>
            <div style="display:flex;flex-direction:column;gap:14px">
                <div style="display:flex;align-items:center;gap:10px">
                    <button onclick="document.getElementById('imgFilePicker').click()"
                        style="background:var(--accent);border:none;color:#fff;border-radius:6px;padding:10px 16px;cursor:pointer;font-weight:600;white-space:nowrap">🖼️ Din computer…</button>
                    <span style="color:#9aa3b2;font-size:13px">(jpg, png, gif, webp…)</span>
                    <input type="file" id="imgFilePicker" accept="image/*" style="display:none"
                        onchange="if(this.files[0]) imgFromFile(this.files[0]); this.value='';">
                </div>
                <div style="display:flex;align-items:center;gap:8px;color:#6b7280;font-size:12px">
                    <span style="flex:1;height:1px;background:#3a3f4f"></span> sau din web <span style="flex:1;height:1px;background:#3a3f4f"></span>
                </div>
                <div style="display:flex;gap:8px">
                    <input type="text" id="imgUrl" placeholder="https://exemplu.com/imagine.jpg"
                        style="flex:1;background:#15161d;border:1px solid #3a3f4f;border-radius:6px;padding:9px 11px;color:#eee;font-size:14px"
                        onkeydown="if(event.key==='Enter'){event.preventDefault();imgFromUrl();}">
                    <button onclick="imgFromUrl()" style="background:#107c10;border:none;color:#fff;border-radius:6px;padding:0 16px;cursor:pointer;font-weight:600">Inserează</button>
                </div>
            </div>
            <div style="text-align:right;margin-top:18px">
                <button onclick="closeImgDialog()" style="background:transparent;border:1px solid #3a3f4f;color:#cdd3df;border-radius:6px;padding:8px 16px;cursor:pointer">Anulează</button>
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

    <!-- MODAL: Compare Documents -->
    <div class="overlay" id="compareOverlay" style="z-index:4100">
        <div class="compare-card">
            <div class="compare-titlebar">
                <span>Compare Documents</span>
                <span class="spacer"></span>
                <button class="compare-x" type="button" onclick="closeCompareDialog()" title="Inchide">&times;</button>
            </div>
            <div class="compare-doc-grid">
                <div class="compare-doc-box">
                    <label class="compare-label">Original document</label>
                    <div class="compare-picker-row">
                        <div class="compare-file-name" id="compareOriginalName">Alege fișierul A...</div>
                        <button class="compare-browse" type="button" onclick="pickCompareFile('a')" title="Alege fișier">Folder</button>
                    </div>
                    <div class="compare-label-row">
                        <label class="compare-label" style="margin:0;white-space:nowrap">Label changes with</label>
                        <input type="text" id="compareOriginalLabel" placeholder="Autor A">
                    </div>
                </div>
                <div class="compare-doc-box">
                    <label class="compare-label">Revised document</label>
                    <div class="compare-picker-row">
                        <div class="compare-file-name" id="compareRevisedName">Alege fișierul B...</div>
                        <button class="compare-browse" type="button" onclick="pickCompareFile('b')" title="Alege fișier">Folder</button>
                    </div>
                    <div class="compare-label-row">
                        <label class="compare-label" style="margin:0;white-space:nowrap">Label changes with</label>
                        <input type="text" id="compareRevisedLabel" placeholder="Autor B">
                    </div>
                </div>
            </div>
            <div class="compare-settings">
                <label><input type="checkbox" checked disabled> Insertions and deletions</label>
                <label><input type="checkbox" checked disabled> Tables</label>
                <label><input type="checkbox" checked disabled> Moves</label>
                <label><input type="checkbox" checked disabled> Headers and footers</label>
                <label><input type="checkbox" checked disabled> Comments</label>
                <label><input type="checkbox" checked disabled> Footnotes and endnotes</label>
                <label><input type="checkbox" checked disabled> Formatting</label>
                <label><input type="checkbox" checked disabled> Textboxes</label>
                <label><input type="checkbox" id="compareCaseChanges" checked> Case changes</label>
                <label><input type="checkbox" checked disabled> Word level</label>
            </div>
            <div class="compare-actions">
                <button type="button" onclick="closeCompareDialog()">Cancel</button>
                <button type="button" class="primary" id="compareOkBtn" onclick="runCompareDocs()">OK</button>
            </div>
            <input type="file" id="compareFileA" accept=".docx,.odt,.pdf" style="display:none" onchange="handleCompareFile('a', this.files[0]); this.value='';">
            <input type="file" id="compareFileB" accept=".docx,.odt,.pdf" style="display:none" onchange="handleCompareFile('b', this.files[0]); this.value='';">
        </div>
    </div>

    <div class="overlay" id="compareResultOverlay" style="z-index:4200">
        <div class="compare-result-card">
            <div class="compare-result-head">
                <strong>Compare result</strong>
                <span class="compare-result-meta" id="compareResultMeta"></span>
                <span class="spacer"></span>
                <button type="button" id="compareAlignBtn" onclick="toggleCompareParagraphAlign()">Aliniere paragrafe: ON</button>
                <button type="button" id="compareDiacriticsBtn" onclick="toggleCompareDiacritics()">Diacritice: ON</button>
                <button type="button" id="compareSyncBtn" onclick="toggleCompareScrollSyncMode()">Scroll legat: ON</button>
                <button type="button" onclick="openCompareDialog(true)">Compara alte fisiere</button>
                <button type="button" onclick="closeCompareResult()">Inchide</button>
            </div>
            <div class="compare-panes">
                <div class="compare-pane">
                    <div class="compare-pane-title" id="comparePaneATitle">A</div>
                    <div class="compare-pane-body" id="comparePaneA"></div>
                </div>
                <div class="compare-pane">
                    <div class="compare-pane-title" id="comparePaneBTitle">B</div>
                    <div class="compare-pane-body" id="comparePaneB"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="toast"></div>
    <div class="dex-context-menu" id="dexContextMenu" role="menu" aria-hidden="true">
        <div class="dex-context-head">DEX: <span class="dex-context-word" id="dexContextWord"></span></div>
        <input type="text" id="dexEditWordInput" class="dex-context-input" oninput="refreshDexContextActions()" onkeydown="dexContextInputKey(event)" aria-label="Cuvant in dictionar">
        <button type="button" id="dexAddWordBtn" onclick="addDexContextWord()">Adauga in dictionar</button>
        <button type="button" id="dexEditWordBtn" onclick="editDexContextWord()" disabled>Editeaza in dictionar</button>
        <button type="button" id="dexDeleteWordBtn" class="dex-danger" onclick="deleteDexContextWord()" disabled>Sterge din dictionar</button>
        <button type="button" onclick="hideDexContextMenu()">Inchide</button>
    </div>

    <!-- Libs: html-docx-js (html→docx la salvare), pdf.js (randare PDF). DOCX/ODT se convertesc server-side. -->
    <script src="https://cdn.jsdelivr.net/npm/html-docx-js@0.3.1/dist/html-docx.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        const SELF = location.pathname; // /htmleditor/Word Editor/index.php
        const api = (qs) => SELF + '?' + qs;
        let currentFile = null;    // cale absoluta a fisierului deschis
        let currentExt = null;
        let currentDir = '';       // subdir relativ in sidebar
        let appCloseApproved = false;
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
                // pagină goală cu un paragraf gol → se poate scrie direct (fără text-placeholder)
                const s = makeSheet(); s.innerHTML = '<p><br></p>'; page.appendChild(s);
                numberInfo(); updateWordCount(); updateBookmarkUi(); return;
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

            numberInfo(); updateWordCount(); updateBookmarkUi();
        }

        function getDocHtml() {
            const sheets = page.querySelectorAll('.page');
            let html = sheets.length ? Array.from(sheets).map(s => s.innerHTML).join('\n') : page.innerHTML;
            // scoate sublinierile DEX (sunt doar marcaje vizuale, nu fac parte din document)
            if (html.indexOf('dex-bad') !== -1) html = html.replace(/<span class="dex-bad">([\s\S]*?)<\/span>/g, '$1');
            return html;
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

        // ── Compare documents ──
        const compareState = { a: null, b: null };
        const compareViewState = { alignParagraphs: true, showDiacritics: true, syncScroll: true };

        function openCompareDialog(keepResultOpen) {
            document.getElementById('compareOverlay').classList.add('open');
            if (!keepResultOpen) closeCompareResult(false);
            updateCompareFileLabels();
        }

        function closeCompareDialog() {
            document.getElementById('compareOverlay').classList.remove('open');
        }

        function closeCompareResult(showToast) {
            document.getElementById('compareResultOverlay').classList.remove('open');
            if (showToast) toast('Compare inchis');
        }

        function pickCompareFile(side) {
            document.getElementById(side === 'a' ? 'compareFileA' : 'compareFileB').click();
        }

        function handleCompareFile(side, file) {
            if (!file) return;
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!['docx', 'odt', 'pdf'].includes(ext)) {
                toast('Compare suportă momentan .docx, .odt și .pdf');
                return;
            }
            compareState[side] = file;
            updateCompareFileLabels();
        }

        function updateCompareFileLabels() {
            document.getElementById('compareOriginalName').textContent = compareState.a ? compareState.a.name : 'Alege fișierul A...';
            document.getElementById('compareRevisedName').textContent = compareState.b ? compareState.b.name : 'Alege fișierul B...';
        }

        async function runCompareDocs() {
            if (!compareState.a || !compareState.b) {
                toast('Alege ambele fisiere pentru Compare');
                return;
            }
            const btn = document.getElementById('compareOkBtn');
            btn.disabled = true;
            btn.textContent = 'Se compară...';
            setInfo('Compare: se convertesc fișierele...');
            try {
                const [docA, docB] = await Promise.all([
                    loadCompareDocument(compareState.a),
                    loadCompareDocument(compareState.b)
                ]);
                setInfo('Compare: se calculează diferențele...');
                const ignoreCase = !document.getElementById('compareCaseChanges').checked;
                const result = buildCompareHtml(docA.blocks, docB.blocks, { ignoreCase });
                document.getElementById('comparePaneATitle').textContent = 'A: ' + compareState.a.name;
                document.getElementById('comparePaneBTitle').textContent = 'B: ' + compareState.b.name;
                document.getElementById('comparePaneA').innerHTML = result.htmlA;
                document.getElementById('comparePaneB').innerHTML = result.htmlB;
                document.getElementById('comparePaneA').scrollTop = 0;
                document.getElementById('comparePaneB').scrollTop = 0;
                const meta = [];
                if (result.textWords) meta.push(result.textWords + ' schimbari text');
                if (result.diacWords) meta.push(result.diacWords + ' diacritice');
                if (!meta.length) meta.push('0 diferente');
                document.getElementById('compareResultMeta').textContent =
                    meta.join(' / ') + ' / ' + result.diffBlocks + ' paragrafe afectate';
                closeCompareDialog();
                document.getElementById('compareResultOverlay').classList.add('open');
                updateCompareViewButtons();
                scheduleCompareBlockAlignment();
                setInfo('Compare finalizat');
                toast('Compare finalizat: ' + meta.join(' / '), 3200);
            } catch (e) {
                console.error(e);
                toast('Compare eroare: ' + (e.message || e), 4500);
                setInfo('Compare eroare');
            } finally {
                btn.disabled = false;
                btn.textContent = 'OK';
            }
        }

        async function loadCompareDocument(file) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (ext === 'docx') {
                const fd = new FormData(); fd.append('f', file);
                const j = await (await fetch(api('action=docx2html'), { method: 'POST', body: fd })).json();
                if (!j.ok) throw new Error(j.error || 'Nu pot converti DOCX');
                if (j.ole2) throw new Error(file.name + ' este DOC vechi redenumit in DOCX');
                return { name: file.name, blocks: htmlToCompareBlocks(j.html || '') };
            }
            if (ext === 'odt') {
                const fd = new FormData(); fd.append('f', file);
                const j = await (await fetch(api('action=odt2html'), { method: 'POST', body: fd })).json();
                if (!j.ok) throw new Error(j.error || 'Nu pot converti ODT');
                return { name: file.name, blocks: htmlToCompareBlocks(j.html || '') };
            }
            if (ext === 'pdf') {
                if (!window.pdfjsLib) throw new Error('pdf.js nu este incarcat');
                const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
                const blocks = [];
                for (let n = 1; n <= pdf.numPages; n++) {
                    const pg = await pdf.getPage(n);
                    const txt = await pg.getTextContent();
                    const line = txt.items.map(item => item.str || '').join(' ').replace(/\s+/g, ' ').trim();
                    if (line) blocks.push(line);
                }
                return { name: file.name, blocks };
            }
            throw new Error('Format nesuportat: ' + ext);
        }

        function htmlToCompareBlocks(html) {
            const tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            tmp.querySelectorAll('script,style,noscript').forEach(n => n.remove());
            const selector = 'h1,h2,h3,h4,h5,h6,p,li,td,th';
            let nodes = Array.from(tmp.querySelectorAll(selector));
            if (!nodes.length) nodes = Array.from(tmp.children);
            const blocks = [];
            nodes.forEach(node => {
                const text = (node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
                if (text) blocks.push(text);
            });
            if (!blocks.length) {
                const text = (tmp.innerText || tmp.textContent || '').replace(/\r/g, '').split(/\n+/).map(s => s.trim()).filter(Boolean);
                blocks.push(...text);
            }
            return blocks;
        }

        function buildCompareHtml(blocksA, blocksB, options) {
            const pairs = alignCompareBlocks(blocksA, blocksB);
            let ai = 0, bi = 0, diffWords = 0, textWords = 0, diacWords = 0, diffBlocks = 0;
            const outA = [], outB = [];
            const flushUnmatched = (endA, endB) => {
                const count = Math.max(endA - ai, endB - bi);
                for (let k = 0; k < count; k++) {
                    const aText = blocksA[ai + k] || '';
                    const bText = blocksB[bi + k] || '';
                    const rendered = compareBlockWords(aText, bText, options);
                    outA.push(rendered.htmlA);
                    outB.push(rendered.htmlB);
                    if (rendered.diffWords) {
                        diffWords += rendered.diffWords;
                        textWords += rendered.textWords || 0;
                        diacWords += rendered.diacWords || 0;
                        diffBlocks++;
                    }
                }
                ai = endA;
                bi = endB;
            };

            pairs.forEach(pair => {
                flushUnmatched(pair.a, pair.b);
                outA.push(renderCompareBlock(escapeHtml(blocksA[pair.a] || '')));
                outB.push(renderCompareBlock(escapeHtml(blocksB[pair.b] || '')));
                ai = pair.a + 1;
                bi = pair.b + 1;
            });
            flushUnmatched(blocksA.length, blocksB.length);

            return {
                htmlA: outA.join(''),
                htmlB: outB.join(''),
                diffWords,
                textWords,
                diacWords,
                diffBlocks
            };
        }

        function alignCompareBlocks(a, b) {
            const an = a.length, bn = b.length;
            const na = a.map(normalizeCompareBlock);
            const nb = b.map(normalizeCompareBlock);
            const dp = Array.from({ length: an + 1 }, () => new Uint16Array(bn + 1));
            for (let i = an - 1; i >= 0; i--) {
                for (let j = bn - 1; j >= 0; j--) {
                    dp[i][j] = na[i] && na[i] === nb[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
                }
            }
            const pairs = [];
            let i = 0, j = 0;
            while (i < an && j < bn) {
                if (na[i] && na[i] === nb[j]) {
                    pairs.push({ a: i, b: j });
                    i++; j++;
                } else if (dp[i + 1][j] >= dp[i][j + 1]) i++;
                else j++;
            }
            return pairs;
        }

        function normalizeCompareBlock(text) {
            return String(text || '').replace(/\s+/g, ' ').trim();
        }

        function tokenizeCompareText(text) {
            text = String(text || '').normalize('NFC');
            const fallbackTokens = () => {
                const re = /[\p{L}\p{N}]+(?:[-'’][\p{L}\p{N}]+)*/gu;
                const tokens = [];
                let last = 0, m;
                while ((m = re.exec(text))) {
                    if (m.index > last) tokens.push({ text: text.slice(last, m.index), word: false });
                    tokens.push({ text: m[0], word: true });
                    last = re.lastIndex;
                }
                if (last < text.length) tokens.push({ text: text.slice(last), word: false });
                return tokens;
            };

            if (typeof Intl !== 'undefined' && Intl.Segmenter) {
                if (/[\p{L}\p{N}][-'’][\p{L}\p{N}]/u.test(text)) return fallbackTokens();
                const segmenter = new Intl.Segmenter(undefined, { granularity: 'word' });
                const tokens = [];
                let wordCount = 0;
                let last = 0;
                for (const part of segmenter.segment(text)) {
                    if (part.index > last) tokens.push({ text: text.slice(last, part.index), word: false });
                    const isWord = !!part.isWordLike;
                    if (isWord) wordCount++;
                    tokens.push({ text: part.segment, word: isWord });
                    last = part.index + part.segment.length;
                }
                if (last < text.length) tokens.push({ text: text.slice(last), word: false });
                if (!wordCount && /[\p{L}\p{N}]/u.test(text)) return fallbackTokens();
                return tokens;
            }
            return fallbackTokens();
        }

        function compareWordExactKey(word, options) {
            const key = String(word || '').normalize('NFC');
            return options && options.ignoreCase ? key.toLocaleLowerCase() : key;
        }

        function compareWordBaseKey(word, options) {
            let key = String(word || '')
                .normalize('NFC')
                .replace(/[ȘŞ]/g, 'S')
                .replace(/[șş]/g, 's')
                .replace(/[ȚŢ]/g, 'T')
                .replace(/[țţ]/g, 't')
                .normalize('NFD')
                .replace(/\p{M}/gu, '')
                .normalize('NFC');
            return options && options.ignoreCase ? key.toLocaleLowerCase() : key;
        }

        function compareBlockWords(aText, bText, options) {
            if (!aText && !bText) return { htmlA: renderCompareBlock('&nbsp;'), htmlB: renderCompareBlock('&nbsp;'), diffWords: 0, textWords: 0, diacWords: 0 };
            const tokensA = tokenizeCompareText(aText), tokensB = tokenizeCompareText(bText);
            const wordsA = tokensA.filter(t => t.word).map(t => t.text);
            const wordsB = tokensB.filter(t => t.word).map(t => t.text);
            const classesA = new Array(wordsA.length).fill(!!aText && !bText ? 'text' : '');
            const classesB = new Array(wordsB.length).fill(!!bText && !aText ? 'text' : '');
            if (aText && bText) {
                const matches = alignCompareWords(wordsA, wordsB, options);
                const ops = [];
                let ai = 0, bi = 0;
                classesA.fill('text'); classesB.fill('text');
                matches.forEach(pair => {
                    if (pair.a > ai || pair.b > bi) {
                        ops.push({ type: 'text', aStart: ai, aEnd: pair.a, bStart: bi, bEnd: pair.b });
                    }
                    const cls = compareWordExactKey(wordsA[pair.a], options) === compareWordExactKey(wordsB[pair.b], options) ? '' : 'diac';
                    classesA[pair.a] = cls;
                    classesB[pair.b] = cls;
                    ops.push({ type: 'match', a: pair.a, b: pair.b });
                    ai = pair.a + 1;
                    bi = pair.b + 1;
                });
                if (ai < wordsA.length || bi < wordsB.length) {
                    ops.push({ type: 'text', aStart: ai, aEnd: wordsA.length, bStart: bi, bEnd: wordsB.length });
                }
                markCompareSemanticHunks(ops, classesA, classesB);
            }
            const textWords = countCompareClass(classesA, 'text') + countCompareClass(classesB, 'text');
            const diacWords = countCompareClass(classesA, 'diac') + countCompareClass(classesB, 'diac');
            const diffWords = textWords + diacWords;
            return {
                htmlA: renderCompareTokens(tokensA, classesA, !!aText),
                htmlB: renderCompareTokens(tokensB, classesB, !!bText),
                diffWords,
                textWords,
                diacWords
            };
        }

        function countCompareClass(classes, cls) {
            return classes.filter(item => item === cls).length;
        }

        function markCompareSemanticHunks(ops, classesA, classesB) {
            const maxBridgeWords = 3;
            let i = 0;
            while (i < ops.length) {
                if (ops[i].type !== 'match') { i++; continue; }
                const start = i;
                while (i < ops.length && ops[i].type === 'match') i++;
                const end = i;
                const prev = ops[start - 1];
                const next = ops[end];
                if (!prev || !next || prev.type !== 'text' || next.type !== 'text') continue;
                if (end - start > maxBridgeWords) continue;
                for (let j = start; j < end; j++) {
                    classesA[ops[j].a] = 'text';
                    classesB[ops[j].b] = 'text';
                }
            }
        }

        function alignCompareWords(a, b, options) {
            const an = a.length, bn = b.length;
            if (!an || !bn) return [];
            if (an * bn > 250000) return alignCompareWordsFast(a, b, options);
            const ka = a.map(w => compareWordBaseKey(w, options));
            const kb = b.map(w => compareWordBaseKey(w, options));
            const dp = Array.from({ length: an + 1 }, () => new Uint16Array(bn + 1));
            for (let i = an - 1; i >= 0; i--) {
                for (let j = bn - 1; j >= 0; j--) {
                    dp[i][j] = ka[i] === kb[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
                }
            }
            const pairs = [];
            let i = 0, j = 0;
            while (i < an && j < bn) {
                if (ka[i] === kb[j]) { pairs.push({ a: i, b: j }); i++; j++; }
                else if (dp[i + 1][j] >= dp[i][j + 1]) i++;
                else j++;
            }
            return pairs;
        }

        function alignCompareWordsFast(a, b, options) {
            const positions = new Map();
            b.forEach((word, idx) => {
                const key = compareWordBaseKey(word, options);
                if (!positions.has(key)) positions.set(key, []);
                positions.get(key).push(idx);
            });
            const used = new Set(), pairs = [];
            a.forEach((word, ai) => {
                const list = positions.get(compareWordBaseKey(word, options));
                if (!list) return;
                const bi = list.find(idx => !used.has(idx));
                if (bi === undefined) return;
                used.add(bi);
                pairs.push({ a: ai, b: bi });
            });
            return pairs.sort((x, y) => x.a - y.a || x.b - y.b);
        }

        function renderCompareTokens(tokens, classes, hasText) {
            if (!hasText) return renderCompareBlock('<span class="compare-empty-block">(lipseste)</span>');
            let wi = 0;
            const html = tokens.map(token => {
                if (!token.word) return escapeHtml(token.text);
                const word = escapeHtml(token.text);
                const cls = classes[wi++] || '';
                if (cls === 'diac') return '<span class="compare-word-diac">' + word + '</span>';
                if (cls === 'text') return '<span class="compare-word-text">' + word + '</span>';
                return word;
            }).join('');
            return renderCompareBlock(html || '&nbsp;');
        }

        function renderCompareBlock(innerHtml) {
            return '<div class="compare-block">' + innerHtml + '</div>';
        }

        function updateCompareViewButtons() {
            const alignBtn = document.getElementById('compareAlignBtn');
            const diacriticsBtn = document.getElementById('compareDiacriticsBtn');
            const syncBtn = document.getElementById('compareSyncBtn');
            const card = document.querySelector('#compareResultOverlay .compare-result-card');
            if (alignBtn) {
                alignBtn.textContent = 'Aliniere paragrafe: ' + (compareViewState.alignParagraphs ? 'ON' : 'OFF');
                alignBtn.classList.toggle('active', compareViewState.alignParagraphs);
            }
            if (diacriticsBtn) {
                diacriticsBtn.textContent = 'Diacritice: ' + (compareViewState.showDiacritics ? 'ON' : 'OFF');
                diacriticsBtn.classList.toggle('active', compareViewState.showDiacritics);
            }
            if (syncBtn) {
                syncBtn.textContent = 'Scroll legat: ' + (compareViewState.syncScroll ? 'ON' : 'OFF');
                syncBtn.classList.toggle('active', compareViewState.syncScroll);
            }
            if (card) card.classList.toggle('hide-diacritics', !compareViewState.showDiacritics);
        }

        function toggleCompareParagraphAlign() {
            compareViewState.alignParagraphs = !compareViewState.alignParagraphs;
            updateCompareViewButtons();
            scheduleCompareBlockAlignment();
        }

        function toggleCompareDiacritics() {
            compareViewState.showDiacritics = !compareViewState.showDiacritics;
            updateCompareViewButtons();
        }

        function toggleCompareScrollSyncMode() {
            compareViewState.syncScroll = !compareViewState.syncScroll;
            updateCompareViewButtons();
            if (compareViewState.syncScroll) {
                const a = document.getElementById('comparePaneA');
                const b = document.getElementById('comparePaneB');
                if (a && b) b.scrollTop = a.scrollTop;
            }
        }

        function clearCompareBlockHeights() {
            document.querySelectorAll('#comparePaneA .compare-block, #comparePaneB .compare-block').forEach(block => {
                block.style.minHeight = '';
            });
        }

        function alignCompareBlockHeights() {
            const a = document.getElementById('comparePaneA');
            const b = document.getElementById('comparePaneB');
            if (!a || !b) return;
            clearCompareBlockHeights();
            if (!compareViewState.alignParagraphs) return;
            const blocksA = Array.from(a.querySelectorAll('.compare-block'));
            const blocksB = Array.from(b.querySelectorAll('.compare-block'));
            const count = Math.max(blocksA.length, blocksB.length);
            for (let i = 0; i < count; i++) {
                const ba = blocksA[i], bb = blocksB[i];
                if (!ba || !bb) continue;
                const h = Math.max(ba.offsetHeight, bb.offsetHeight);
                if (h > 0) {
                    ba.style.minHeight = h + 'px';
                    bb.style.minHeight = h + 'px';
                }
            }
        }

        function scheduleCompareBlockAlignment() {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    alignCompareBlockHeights();
                    setTimeout(alignCompareBlockHeights, 120);
                });
            });
        }

        (function initCompareScrollSync() {
            const a = document.getElementById('comparePaneA');
            const b = document.getElementById('comparePaneB');
            if (!a || !b) return;
            let lock = false;
            const sync = (src, dst) => {
                if (!compareViewState.syncScroll) return;
                if (lock) return;
                lock = true;
                const maxSrc = Math.max(1, src.scrollHeight - src.clientHeight);
                const maxDst = Math.max(1, dst.scrollHeight - dst.clientHeight);
                dst.scrollTop = (src.scrollTop / maxSrc) * maxDst;
                lock = false;
            };
            a.addEventListener('scroll', () => sync(a, b));
            b.addEventListener('scroll', () => sync(b, a));
            window.addEventListener('resize', () => {
                if (document.getElementById('compareResultOverlay').classList.contains('open')) {
                    scheduleCompareBlockAlignment();
                }
            });
        })();

        // ── Sidebar / file list ──
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('hidden');
        }
        async function loadFileList(path = '') {
            currentDir = path;
            try {
                const r = await fetch(api('action=list&path=' + encodeURIComponent(path)));
                const j = await r.json();
                const box = document.getElementById('fileList');
                box.innerHTML = '';
                // bara cu calea curentă
                const pathBar = document.getElementById('sbPath');
                if (j.mode === 'drives') { pathBar.style.display = 'block'; pathBar.textContent = '💻 This PC (partiții)'; }
                else if (j.path) { pathBar.style.display = 'block'; pathBar.textContent = j.path; pathBar.title = j.path; }
                else pathBar.style.display = 'none';
                // „înapoi"
                if (j.parent !== null && j.parent !== undefined && j.mode !== 'drives') {
                    const up = document.createElement('div');
                    up.className = 'file-item dir';
                    up.innerHTML = '<span class="ico">⬆️</span>.. (înapoi)';
                    up.onclick = () => loadFileList(j.parent);
                    box.appendChild(up);
                }
                (j.items || []).forEach(it => {
                    const el = document.createElement('div');
                    el.className = 'file-item' + (it.type === 'dir' ? ' dir' : '');
                    const isDrive = j.mode === 'drives';
                    const ico = isDrive ? '💽'
                        : it.type === 'dir' ? '📁'
                            : it.ext === 'pdf' ? '📕'
                                : it.ext === 'odt' ? '📘'
                                    : '📄';
                    el.innerHTML = '<span class="ico">' + ico + '</span>' + escapeHtml(it.name);
                    el.title = it.path || it.name;
                    if (it.type === 'dir') el.onclick = () => loadFileList(it.path);
                    else el.onclick = () => openFile(it.path, it.ext);
                    box.appendChild(el);
                });
                if (!j.items || !j.items.length) {
                    box.innerHTML += '<div style="color:#a19f9d;padding:6px">(gol — niciun docx/odt/pdf sau subfolder)</div>';
                }
            } catch (e) {
                toast('Eroare listare: ' + e.message);
            }
        }
        // fișiere recente în sidebar (clic → deschide)
        function renderSidebarRecent() {
            const box = document.getElementById('sbRecent');
            const hdr = document.getElementById('sbRecentHdr');
            const items = (typeof allRecent === 'function') ? allRecent() : [];
            box.innerHTML = '';
            if (!items.length) { hdr.style.display = 'none'; return; }
            hdr.style.display = 'block';
            items.slice(0, 8).forEach(e => {
                const ext = (e.ext || e.name.split('.').pop() || '').toLowerCase();
                const ico = ext === 'pdf' ? '📕' : ext === 'odt' ? '📘' : '📄';
                const el = document.createElement('div'); el.className = 'file-item'; el.title = e.path || e.name;
                el.innerHTML = '<span class="ico">' + ico + '</span>' + escapeHtml(e.name);
                el.onclick = () => e.path ? openFile(e.path, ext) : reopenDropped(e.name);
                box.appendChild(el);
            });
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
            syncActiveTab();
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
            syncActiveTab();
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

        let editorSavedRange = null;
        function selectionRangeInsidePage() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            const range = sel.getRangeAt(0);
            return (page.contains(range.startContainer) && page.contains(range.endContainer)) ? range : null;
        }
        function saveEditorSelection() {
            const range = selectionRangeInsidePage();
            if (range) editorSavedRange = range.cloneRange();
            return !!range;
        }
        function restoreEditorSelection() {
            if (!editorSavedRange) return false;
            try {
                if (!page.contains(editorSavedRange.startContainer) || !page.contains(editorSavedRange.endContainer)) {
                    editorSavedRange = null;
                    return false;
                }
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(editorSavedRange.cloneRange());
                return true;
            } catch (e) {
                editorSavedRange = null;
                return false;
            }
        }
        function focusPageNoJump() {
            const sx = canvasEl.scrollLeft, sy = canvasEl.scrollTop;
            try { page.focus({ preventScroll: true }); }
            catch (e) { page.focus(); }
            canvasEl.scrollLeft = sx;
            canvasEl.scrollTop = sy;
        }
        document.addEventListener('selectionchange', () => { saveEditorSelection(); });
        const ribbonEl = document.getElementById('ribbon');
        if (ribbonEl) {
            ribbonEl.addEventListener('mousedown', e => {
                const target = e.target && e.target.nodeType === 1 ? e.target : e.target.parentElement;
                const btn = target ? target.closest('button') : null;
                if (!btn || !ribbonEl.contains(btn)) return;
                saveEditorSelection();
                e.preventDefault();
            }, true);
        }

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
        // offset-ul de început/sfârșit al selecției (pentru Find Next/Prev să avanseze corect)
        function selOffset(which) {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            const r = sel.getRangeAt(0);
            const cont = which === 'end' ? r.endContainer : r.startContainer;
            const off = which === 'end' ? r.endOffset : r.startOffset;
            if (!page.contains(cont)) return null;
            const pre = document.createRange();
            pre.selectNodeContents(page);
            try { pre.setEnd(cont, off); } catch (e) { return null; }
            return pre.toString().length;
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
            restoreEditorSelection();
            recordUndo(); suppressSnap = true;
            try {
                focusPageNoJump();
                restoreEditorSelection();
                fn();
                saveEditorSelection();
            } finally { suppressSnap = false; }
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
        function focusPage() { focusPageNoJump(); }
        const ALIGN_COMMANDS = {
            justifyLeft: 'left',
            justifyCenter: 'center',
            justifyRight: 'right',
            justifyFull: 'justify'
        };
        function setParagraphAlignment(value) {
            restoreEditorSelection();
            const blocks = blocksInSelection();
            if (!blocks.length) { toast('Pune cursorul in paragraful de aliniat'); return; }
            const keepRange = selectionRangeInsidePage();
            recordUndo(); suppressSnap = true;
            try {
                blocks.forEach(b => { b.style.textAlign = value === 'left' ? '' : value; });
            } finally { suppressSnap = false; }
            if (keepRange) {
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(keepRange.cloneRange());
                saveEditorSelection();
            }
            if (blocks[0] && blocks[0].scrollIntoView) blocks[0].scrollIntoView({ block: 'nearest' });
            appCloseApproved = false;
            refreshActive(); updateWordCount(); scheduleAutoBackup();
        }
        function cmd(name, val = null) {
            if (ALIGN_COMMANDS[name]) { setParagraphAlignment(ALIGN_COMMANDS[name]); return; }
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
        // ── Meniu pop generic (Word-style dropdowns) ──
        let openMenuEl = null;
        function closeAllMenus() { if (openMenuEl) { openMenuEl.remove(); openMenuEl = null; } }
        function showMenu(anchor, items) {
            closeAllMenus();
            const m = document.createElement('div'); m.className = 'pop-menu';
            m.onmousedown = (e) => e.preventDefault();   // păstrează selecția din document
            items.forEach(it => {
                if (it === '-') { const s = document.createElement('div'); s.className = 'pm-sep'; m.appendChild(s); return; }
                const el = document.createElement('div'); el.className = 'pm-item';
                el.innerHTML = (it.ico ? '<span class="pmi">' + it.ico + '</span>' : '') + '<span>' + it.label + '</span>';
                el.onclick = () => { closeAllMenus(); it.onclick(); };
                m.appendChild(el);
            });
            document.body.appendChild(m);
            const r = anchor.getBoundingClientRect();
            m.style.left = Math.min(r.left, window.innerWidth - m.offsetWidth - 8) + 'px';
            m.style.top = (r.bottom + 2) + 'px';
            openMenuEl = m;
        }

        // ── Change Case (registru) ──
        function changeCaseMenu(ev) {
            ev.stopPropagation();
            showMenu(ev.currentTarget, [
                { label: 'Prima literă mare (propoziție)', onclick: () => applyCase('sentence') },
                { label: 'litere mici', onclick: () => applyCase('lower') },
                { label: 'MAJUSCULE', onclick: () => applyCase('upper') },
                { label: 'Fiecare Cuvânt Cu Majusculă', onclick: () => applyCase('caps') },
                { label: 'iNVERSEAZĂ rEGISTRUL', onclick: () => applyCase('toggle') }
            ]);
        }
        function applyCase(type) {
            const sel = window.getSelection();
            if (!sel.rangeCount || !sel.toString()) { toast('Selectează textul'); return; }
            let txt = sel.toString(), out;
            if (type === 'lower') out = txt.toLowerCase();
            else if (type === 'upper') out = txt.toUpperCase();
            else if (type === 'caps') out = txt.toLowerCase().replace(/\b\p{L}/gu, c => c.toUpperCase());
            else if (type === 'sentence') { out = txt.toLowerCase().replace(/(^\s*\p{L})|([.!?]\s+\p{L})/gu, c => c.toUpperCase()); }
            else out = txt.split('').map(c => c === c.toUpperCase() ? c.toLowerCase() : c.toUpperCase()).join('');
            runCmd(() => document.execCommand('insertText', false, out));
        }
        function changeCase() { applyCase('upper'); }  // compatibilitate

        // ── Golește toată formatarea (pe selecție sau paragraf) ──
        function clearAllFormatting() {
            const sel = window.getSelection();
            page.focus();
            recordUndo(); suppressSnap = true;
            try {
                document.execCommand('removeFormat');
                document.execCommand('unlink');
                // scoate clase de stil + inline styles din blocurile vizate
                const blocks = (sel.rangeCount && !sel.getRangeAt(0).collapsed) ? blocksInRange(sel.getRangeAt(0)) : [getBlock()].filter(Boolean);
                blocks.forEach(b => { b.className = (b.className || '').replace(/\bst-[\w-]+/g, '').trim(); FONT_PROPS.forEach(p => b.style.removeProperty(p)); b.style.removeProperty('background'); });
            } finally { suppressSnap = false; }
            refreshActive(); updateWordCount();
        }

        // ── Efecte text (umbră / contur / strălucire) ──
        function textEffectsMenu(ev) {
            ev.stopPropagation();
            showMenu(ev.currentTarget, [
                { label: 'Umbră', onclick: () => applyTextEffect('text-shadow:1px 1px 2px rgba(0,0,0,.45)') },
                { label: 'Contur', onclick: () => applyTextEffect('-webkit-text-stroke:.6px #444;color:transparent') },
                { label: 'Strălucire (glow)', onclick: () => applyTextEffect('text-shadow:0 0 5px #6ea8fe') },
                { label: 'Relief (3D)', onclick: () => applyTextEffect('text-shadow:1px 1px 0 #fff,2px 2px 1px #999') },
                '-',
                { label: 'Fără efect', onclick: () => applyTextEffect('') }
            ]);
        }
        function applyTextEffect(css) {
            const sel = window.getSelection();
            if (!sel.rangeCount || sel.getRangeAt(0).collapsed) { toast('Selectează textul'); return; }
            recordUndo(); suppressSnap = true;
            try {
                const range = sel.getRangeAt(0); clampRangeToPage(range);
                const span = document.createElement('span'); span.style.cssText = css;
                try { range.surroundContents(span); } catch (e) { const f = range.extractContents(); span.appendChild(f); range.insertNode(span); }
            } finally { suppressSnap = false; }
            updateWordCount();
        }

        // ── Liste: stil marcatori / format numerotare / multinivel ──
        function bulletsMenu(ev) {
            ev.stopPropagation();
            const set = (t) => { cmd('insertUnorderedList'); const l = nearestList('UL'); if (l) l.style.listStyleType = t; };
            showMenu(ev.currentTarget, [
                { label: '● Disc', onclick: () => set('disc') },
                { label: '○ Cerc', onclick: () => set('circle') },
                { label: '■ Pătrat', onclick: () => set('square') }
            ]);
        }
        function numberingMenu(ev) {
            ev.stopPropagation();
            const set = (t) => { cmd('insertOrderedList'); const l = nearestList('OL'); if (l) l.style.listStyleType = t; };
            showMenu(ev.currentTarget, [
                { label: '1. 2. 3.', onclick: () => set('decimal') },
                { label: 'a. b. c.', onclick: () => set('lower-alpha') },
                { label: 'A. B. C.', onclick: () => set('upper-alpha') },
                { label: 'i. ii. iii.', onclick: () => set('lower-roman') },
                { label: 'I. II. III.', onclick: () => set('upper-roman') }
            ]);
        }
        function nearestList(tag) {
            let n = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).startContainer : null;
            while (n && n !== page) { if (n.nodeType === 1 && n.tagName === tag) return n; n = n.parentNode; }
            return null;
        }
        function multilevelList() {
            cmd('insertOrderedList');
            const l = nearestList('OL'); if (l) l.style.listStyleType = 'decimal';
        }

        // ── Sortează alfabetic paragrafele/elementele selectate ──
        function sortParagraphs() {
            const sel = window.getSelection();
            if (!sel.rangeCount) { toast('Selectează paragrafele de sortat'); return; }
            const blocks = blocksInRange(sel.getRangeAt(0));
            if (blocks.length < 2) { toast('Selectează cel puțin 2 paragrafe'); return; }
            const parent = blocks[0].parentNode;
            if (!blocks.every(b => b.parentNode === parent)) { toast('Paragrafele trebuie să fie în aceeași zonă'); return; }
            recordUndo(); suppressSnap = true;
            try {
                const sorted = blocks.slice().sort((a, b) => a.textContent.trim().localeCompare(b.textContent.trim(), 'ro'));
                const anchor = blocks[blocks.length - 1].nextSibling;
                sorted.forEach(b => parent.insertBefore(b, anchor));
            } finally { suppressSnap = false; }
            updateWordCount(); toast(blocks.length + ' paragrafe sortate');
        }

        // ── Spațiere între rânduri ──
        function lineSpacingMenu(ev) {
            ev.stopPropagation();
            showMenu(ev.currentTarget, ['1.0', '1.15', '1.5', '2.0', '2.5', '3.0'].map(v => ({ label: v, onclick: () => setLineHeight(v) })));
        }
        function setLineHeight(v) {
            const blocks = blocksInSelection();
            if (!blocks.length || !v) return;
            recordUndo(); suppressSnap = true;
            try { blocks.forEach(b => b.style.lineHeight = v); } finally { suppressSnap = false; }
            updateWordCount();
        }

        // ── Umbrire (fundal) pe selecție / paragraf ──
        function applyShading(color) {
            const sel = window.getSelection();
            if (sel.rangeCount && !sel.getRangeAt(0).collapsed) { cmd('backColor', color); }
            else { const b = getBlock(); if (b) { recordUndo(); b.style.backgroundColor = color; updateWordCount(); } }
        }

        // ── Borduri ──
        function bordersMenu(ev) {
            ev.stopPropagation();
            const set = (css) => {
                const blocks = blocksInSelection(); if (!blocks.length) return;
                recordUndo(); suppressSnap = true;
                try { blocks.forEach(b => { b.style.border = ''; b.style.borderTop = ''; b.style.borderBottom = ''; b.style.borderLeft = ''; b.style.borderRight = ''; if (css) Object.assign(b.style, css); if (css) b.style.padding = b.style.padding || '2px 4px'; }); }
                finally { suppressSnap = false; }
                updateWordCount();
            };
            const B = '1px solid #555';
            showMenu(ev.currentTarget, [
                { label: '▦ Toate bordurile', onclick: () => set({ border: B }) },
                { label: '▭ Contur (exterior)', onclick: () => set({ border: B }) },
                { label: '⎺ Bordură sus', onclick: () => set({ borderTop: B }) },
                { label: '⎽ Bordură jos', onclick: () => set({ borderBottom: B }) },
                '-',
                { label: '▢ Fără borduri', onclick: () => set(null) }
            ]);
        }
        function applyStyle(tag) {
            runCmd(() => document.execCommand('formatBlock', false, tag));
        }

        // ── TAB: deplasează rândul/paragraful spre dreapta (indent, ca în MS Word) ──
        const TAB_CM = 1.27;   // tab-ul implicit din Word = 0.5"
        function blocksInRange(range) {
            const all = [...page.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,blockquote,pre,div')]
                .filter(b => !b.classList.contains('page'));
            const inR = all.filter(b => { try { return range.intersectsNode(b); } catch (e) { return false; } });
            if (inR.length) return inR;
            const b = getBlock(); return b ? [b] : [];
        }
        function blocksInSelection() {
            const sel = window.getSelection();
            if (!sel.rangeCount) { const b = getBlock(); return b ? [b] : []; }
            return blocksInRange(sel.getRangeAt(0));
        }
        function insertTab() {
            const blocks = blocksInSelection(); if (!blocks.length) return;
            recordUndo(); suppressSnap = true;
            try { blocks.forEach(b => { const cur = parseFloat(b.style.marginLeft) || 0; b.style.marginLeft = (cur + TAB_CM) + 'cm'; }); }
            finally { suppressSnap = false; }
            updateWordCount();
        }
        function shiftTabOutdent() {
            const blocks = blocksInSelection(); if (!blocks.length) return;
            recordUndo(); suppressSnap = true;
            try { blocks.forEach(b => { const cur = parseFloat(b.style.marginLeft) || 0; const n = Math.max(0, cur - TAB_CM); if (n) b.style.marginLeft = n + 'cm'; else b.style.marginLeft = ''; }); }
            finally { suppressSnap = false; }
            updateWordCount();
        }
        page.addEventListener('keydown', e => {
            if (e.key === 'Tab') { e.preventDefault(); e.shiftKey ? shiftTabOutdent() : insertTab(); }
        });

        // ── GALERIE STILURI (tip Word) ──
        const BUILTIN_STYLES = [
            { id: 'normal', label: 'Normal', kind: 'block', tag: 'p', cls: '' },
            { id: 'nospacing', label: 'No Spacing', kind: 'block', tag: 'p', cls: 'st-nospacing' },
            { id: 'h1', label: 'Heading 1', kind: 'block', tag: 'h1', cls: 'st-h1' },
            { id: 'h2', label: 'Heading 2', kind: 'block', tag: 'h2', cls: 'st-h2' },
            { id: 'h3', label: 'Heading 3', kind: 'block', tag: 'h3', cls: 'st-h3' },
            { id: 'title', label: 'Title', kind: 'block', tag: 'h1', cls: 'st-title' },
            { id: 'subtitle', label: 'Subtitle', kind: 'block', tag: 'p', cls: 'st-subtitle' },
            { id: 'quote', label: 'Quote', kind: 'block', tag: 'blockquote', cls: 'st-quote' },
            { id: 'intensequote', label: 'Intense Quote', kind: 'block', tag: 'blockquote', cls: 'st-intense-quote' },
            { id: 'emphasis', label: 'Emphasis', kind: 'inline', cls: 'st-emphasis' },
            { id: 'strong', label: 'Strong', kind: 'inline', cls: 'st-strong' },
            { id: 'booktitle', label: 'Book Title', kind: 'inline', cls: 'st-book-title' },
            { id: 'code', label: 'Cod', kind: 'block', tag: 'pre', cls: 'st-code' }
        ];
        const STYLES_KEY = 'wordEditorStyles';
        const OVERRIDES_KEY = 'wordEditorStyleOverrides';
        function getCustomStyles() { try { return JSON.parse(localStorage.getItem(STYLES_KEY)) || []; } catch (e) { return []; } }
        function getOverrides() { try { return JSON.parse(localStorage.getItem(OVERRIDES_KEY)) || {}; } catch (e) { return {}; } }
        // aspectul „efectiv": dacă există un override editat, el controlează complet (css inline), ignoră clasa
        function effective(s) {
            const ov = getOverrides()[s.id];
            if (ov) return Object.assign({}, s, { css: ov, cls: '' });
            return Object.assign({}, s, { css: s.css || '', cls: s.cls || '' });
        }
        let styleSel = null;       // {start,end,collapsed} — selecția reținută la deschiderea galeriei
        let styleEditMode = false; // dacă e activ, click pe stil → editare (nu aplicare)

        const FONT_PROPS = ['font-size', 'color', 'font-weight', 'font-style', 'text-decoration', 'font-family', 'background', 'background-color'];
        function blockOf(node) {
            let n = (node && node.nodeType === 3) ? node.parentNode : node;
            while (n && n !== page && !(n.nodeType === 1 && !n.classList.contains('page')
                && /^(P|H1|H2|H3|H4|H5|H6|LI|BLOCKQUOTE|PRE|DIV)$/.test(n.tagName))) n = n.parentNode;
            return (n && n !== page) ? n : null;
        }

        function toggleStyleGallery(ev) {
            const g = document.getElementById('styleGallery');
            if (g.classList.contains('open')) { closeStyleGallery(); return; }
            const sel = window.getSelection();
            if (sel.rangeCount && page.contains(sel.getRangeAt(0).startContainer)) {
                styleSel = { start: selOffset('start'), end: selOffset('end'), collapsed: sel.getRangeAt(0).collapsed };
            } else styleSel = null;
            renderStyleGallery();
            const r = document.getElementById('styleBtn').getBoundingClientRect();
            g.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 580)) + 'px';
            g.style.top = (r.bottom + 3) + 'px';
            g.classList.add('open');
            ev && ev.stopPropagation();
        }
        function closeStyleGallery() {
            stylePreviewLeave();
            const g = document.getElementById('styleGallery');
            g.classList.remove('open');
            if (styleEditMode) { styleEditMode = false; g.classList.remove('editmode'); document.getElementById('sgEditToggle').style.background = ''; }
        }

        function renderStyleGallery() {
            const grid = document.getElementById('sgGrid'); grid.innerHTML = '';
            document.getElementById('styleGallery').classList.toggle('editmode', styleEditMode);
            const add = (s, custom) => {
                const rs = effective(s);
                const el = document.createElement('div');
                el.className = 'sg-item' + (custom ? ' custom' : '');
                if (rs.cls) el.classList.add(rs.cls);
                if (rs.css) el.style.cssText = rs.css;
                el.textContent = s.label;
                el.title = styleEditMode ? ('Editează: ' + s.label) : s.label;
                if (styleEditMode) { const star = document.createElement('span'); star.className = 'sg-star'; star.textContent = '★'; el.appendChild(star); }
                el.onmousedown = (e) => e.preventDefault();
                el.onmouseenter = () => stylePreviewEnter(s);
                el.onmouseleave = () => stylePreviewLeave();
                el.onclick = () => applyNamedStyle(s);
                grid.appendChild(el);
            };
            BUILTIN_STYLES.forEach(s => add(s, false));
            getCustomStyles().forEach(s => add(s, true));
        }

        // înlocuiește textul selectat cu un span curat (suprascrie complet formatarea veche)
        function applyInlineReplacing(range, rs) {
            const text = range.toString();
            if (!text) return;
            const blk = blockOf(range.startContainer) || page;
            range.deleteContents();
            let node;
            if (rs.cls || rs.css) {
                node = document.createElement('span');
                if (rs.cls) node.className = rs.cls;
                if (rs.css) node.style.cssText = rs.css;
                node.textContent = text;
            } else node = document.createTextNode(text);
            range.insertNode(node);
            // dezfă span-urile-părinte care înconjoară exact conținutul nostru (ex: vechea mărime) → suprascriere curată
            let cur = node;
            while (cur.parentNode && cur.parentNode !== blk && cur.parentNode.tagName === 'SPAN'
                && cur.parentNode.textContent === cur.textContent) {
                const par = cur.parentNode;
                par.parentNode.insertBefore(cur, par);
                par.parentNode.removeChild(par);
            }
            blk.querySelectorAll('span:empty').forEach(sp => sp.remove());
            blk.normalize();
            const nr = document.createRange();
            if (node.nodeType === 1) nr.selectNodeContents(node);
            else { nr.setStart(node, 0); nr.setEnd(node, node.nodeValue.length); }
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(nr);
        }
        function setBlockStyle(b, rs) {
            b.className = (b.className || '').replace(/\bst-[\w-]+/g, '').trim();
            FONT_PROPS.forEach(p => b.style.removeProperty(p));   // curăță override-uri vechi (ex: mărime rămasă)
            if (rs.cls) b.classList.add(rs.cls);
            if (rs.css) b.style.cssText = (b.getAttribute('style') || '') + ';' + rs.css;
        }
        function applyBlockStyle(rs, blocks) {
            if (rs.tag) document.execCommand('formatBlock', false, rs.tag);
            const sel = window.getSelection();
            const targets = sel.rangeCount ? blocksInRange(sel.getRangeAt(0)) : (blocks || []);
            (targets.length ? targets : blocks || []).forEach(b => setBlockStyle(b, rs));
        }

        // ── Live preview (hover) — salvează & restaurează exact ──
        let pv = null;
        function stylePreviewLeave() {
            if (!pv) return;
            if (pv.kind === 'inner' && pv.block) pv.block.innerHTML = pv.html;
            else if (pv.kind === 'blocks') pv.items.forEach(o => {
                if (o.cls != null) o.el.setAttribute('class', o.cls); else o.el.removeAttribute('class');
                if (o.style != null) o.el.setAttribute('style', o.style); else o.el.removeAttribute('style');
            });
            pv = null;
        }
        function stylePreviewEnter(s) {
            stylePreviewLeave();
            if (styleEditMode || !styleSel || styleSel.start == null) return;
            const rs = effective(s);
            const map = docTextMap();
            const range = rangeFromOffsets(map, styleSel.start, styleSel.collapsed ? styleSel.start : styleSel.end);
            if (!styleSel.collapsed) {
                const blocks = blocksInRange(range);
                if (blocks.length <= 1) {                 // selecție într-un singur bloc → preview inline (identic cu aplicarea)
                    const blk = blockOf(range.startContainer) || blocks[0]; if (!blk) return;
                    pv = { kind: 'inner', block: blk, html: blk.innerHTML };
                    const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
                    applyInlineReplacing(range, rs);
                    return;
                }
                pv = { kind: 'blocks', items: blocks.map(b => ({ el: b, cls: b.getAttribute('class'), style: b.getAttribute('style') })) };
                blocks.forEach(b => setBlockStyle(b, rs));
            } else {
                const blk = blockOf(range.startContainer); if (!blk) return;
                pv = { kind: 'blocks', items: [{ el: blk, cls: blk.getAttribute('class'), style: blk.getAttribute('style') }] };
                setBlockStyle(blk, rs);
            }
        }

        // CLICK pe un stil: dacă suntem în Edit mode → editează; altfel aplică
        function applyNamedStyle(s) {
            if (styleEditMode) { openEditStyle(s); return; }
            stylePreviewLeave();
            const rs = effective(s);
            if (!styleSel || styleSel.start == null) { closeStyleGallery(); return; }
            const map = docTextMap();
            const range = rangeFromOffsets(map, styleSel.start, styleSel.collapsed ? styleSel.start : styleSel.end);
            page.focus();
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
            recordUndo(); suppressSnap = true;
            try {
                if (styleSel.collapsed) {
                    applyBlockStyle(rs, [blockOf(range.startContainer)].filter(Boolean));   // cursor → tot paragraful
                } else {
                    const blocks = blocksInRange(range);
                    if (blocks.length <= 1) applyInlineReplacing(range, rs);                // selecție → doar textul selectat
                    else applyBlockStyle(rs, blocks);                                        // selecție pe mai multe paragrafe
                }
            } finally { suppressSnap = false; }
            refreshActive(); updateWordCount();
            document.getElementById('styleBtnLabel').textContent = s.label;
            closeStyleGallery();
        }
        function clearFormatting() {
            closeStyleGallery();
            recordUndo(); suppressSnap = true;
            try {
                document.execCommand('removeFormat');
                const blk = getBlock();
                if (blk) { blk.className = (blk.className || '').replace(/\bst-[\w-]+/g, '').trim(); blk.removeAttribute('style'); }
            } finally { suppressSnap = false; }
            document.getElementById('styleBtnLabel').textContent = 'Normal';
            updateWordCount();
        }
        function toggleEditStyleMode() {
            styleEditMode = !styleEditMode;
            document.getElementById('sgEditToggle').style.background = styleEditMode ? 'var(--accent-light)' : '';
            renderStyleGallery();
            toast(styleEditMode ? 'Editare: click pe un stil ca să-l modifici' : 'Editare oprită');
        }

        // ── Create / Edit Style ──
        function cleanFontName(f) { return (f || '').split(',')[0].replace(/["']/g, '').trim(); }
        function rgbToHexC(rgb) { const m = (rgb || '').match(/\d+/g); if (!m) return ''; return '#' + m.slice(0, 3).map(x => (+x).toString(16).padStart(2, '0')).join(''); }
        function csBuildCss() {
            let c = '';
            const f = document.getElementById('csFont').value; if (f) c += 'font-family:' + f + ';';
            const sz = document.getElementById('csSize').value; if (sz) c += 'font-size:' + sz + 'pt;';
            if (document.getElementById('csColorOn').checked) c += 'color:' + document.getElementById('csColor').value + ';';
            if (document.getElementById('csBold').checked) c += 'font-weight:700;';
            if (document.getElementById('csItalic').checked) c += 'font-style:italic;';
            if (document.getElementById('csUnderline').checked) c += 'text-decoration:underline;';
            return c;
        }
        function csUpdatePreview() { document.getElementById('csPreview').style.cssText = 'background:#fff;border-radius:6px;padding:12px;min-height:38px;' + csBuildCss(); }
        // citește aspectul efectiv al unui stil (built-in via clasă computată, sau custom/override via css)
        function styleProps(s) {
            const rs = effective(s);
            const el = document.createElement('span');
            if (rs.cls) el.className = rs.cls;
            if (rs.css) el.style.cssText = rs.css;
            el.textContent = 'X'; el.style.position = 'absolute'; el.style.left = '-9999px';
            page.appendChild(el);
            const cs = getComputedStyle(el);
            const props = {
                fontFamily: cleanFontName(cs.fontFamily),
                fontSizePt: Math.round(parseFloat(cs.fontSize) * 72 / 96) || '',
                color: rgbToHexC(cs.color) || '#000000',
                bold: (parseInt(cs.fontWeight) >= 600 || cs.fontWeight === 'bold'),
                italic: cs.fontStyle === 'italic',
                underline: /underline/.test(cs.textDecorationLine || cs.textDecoration || '')
            };
            el.remove();
            return props;
        }
        let csSavedRange = null, csEditTarget = null;
        function openCreateStyle() {
            stylePreviewLeave();
            document.getElementById('styleGallery').classList.remove('open');
            csEditTarget = null;
            document.getElementById('csTitle').textContent = 'Creează un stil nou';
            csSavedRange = null;
            if (styleSel && styleSel.start != null && !styleSel.collapsed) {
                const map = docTextMap();
                csSavedRange = rangeFromOffsets(map, styleSel.start, styleSel.end);
            }
            ['csName', 'csSize'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('csFont').value = '';
            document.getElementById('csColor').value = '#000000';
            ['csBold', 'csItalic', 'csUnderline'].forEach(id => document.getElementById(id).checked = false);
            document.getElementById('csColorOn').checked = true;
            document.getElementById('csOverlay').classList.add('open');
            csUpdatePreview();
            setTimeout(() => document.getElementById('csName').focus(), 30);
        }
        function openEditStyle(s) {
            styleEditMode = false; document.getElementById('sgEditToggle').style.background = '';
            document.getElementById('styleGallery').classList.remove('open', 'editmode');
            csEditTarget = s; csSavedRange = null;
            const p = styleProps(s);
            document.getElementById('csTitle').textContent = 'Editează stilul: ' + s.label;
            document.getElementById('csName').value = s.label;
            document.getElementById('csFont').value = p.fontFamily || '';
            document.getElementById('csSize').value = p.fontSizePt || '';
            document.getElementById('csColor').value = p.color || '#000000';
            document.getElementById('csColorOn').checked = true;
            document.getElementById('csBold').checked = p.bold;
            document.getElementById('csItalic').checked = p.italic;
            document.getElementById('csUnderline').checked = p.underline;
            document.getElementById('csOverlay').classList.add('open');
            csUpdatePreview();
            setTimeout(() => document.getElementById('csName').focus(), 30);
        }
        function saveCustomStyle() {
            const name = document.getElementById('csName').value.trim();
            if (!name) { toast('Dă un nume stilului'); return; }
            const css = csBuildCss();
            if (csEditTarget) {                       // EDITARE stil existent
                const s = csEditTarget; csEditTarget = null;
                if (String(s.id).indexOf('cust_') === 0) {
                    const list = getCustomStyles().map(x => x.id === s.id ? Object.assign({}, x, { label: name, css: css }) : x);
                    localStorage.setItem(STYLES_KEY, JSON.stringify(list));
                } else {                              // built-in → salvează override
                    const ov = getOverrides(); ov[s.id] = css; localStorage.setItem(OVERRIDES_KEY, JSON.stringify(ov));
                }
                document.getElementById('csOverlay').classList.remove('open');
                document.getElementById('csTitle').textContent = 'Creează un stil nou';
                toast('Stil actualizat: ' + name);
                return;
            }
            // CREARE stil nou
            const style = { id: 'cust_' + Date.now(), label: name, kind: 'inline', cls: '', css: css };
            const list = getCustomStyles().filter(s => s.label !== name);
            list.push(style);
            localStorage.setItem(STYLES_KEY, JSON.stringify(list));
            document.getElementById('csOverlay').classList.remove('open');
            toast('Stil salvat: ' + name);
            if (csSavedRange) {
                page.focus();
                const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(csSavedRange);
                applyNamedStyle(style);
                csSavedRange = null;
            }
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
            const blk = getBlock() || (editorSavedRange ? blockOf(editorSavedRange.startContainer) : null);
            if (blk) {
                const align = normalizeTextAlign(getComputedStyle(blk).textAlign) || 'left';
                [['al_left', 'left'], ['al_center', 'center'], ['al_right', 'right'], ['al_just', 'justify']]
                    .forEach(([id, val]) => {
                        const el = document.getElementById(id);
                        if (el) el.classList.toggle('active', align === val);
                    });
            }
        }

        // ── Insert helpers ──
        function insertLink() {
            const url = prompt('URL link:', 'https://');
            if (url) cmd('createLink', url);
        }
        // ── Inserare imagine: din computer (fișier local) sau din web (URL) ──
        let imgSavedRange = null;
        function insertImage() {
            const sel = window.getSelection();
            imgSavedRange = (sel.rangeCount && page.contains(sel.getRangeAt(0).startContainer)) ? sel.getRangeAt(0).cloneRange() : null;
            document.getElementById('imgUrl').value = '';
            document.getElementById('imgOverlay').classList.add('open');
            setTimeout(() => document.getElementById('imgUrl').focus(), 30);
        }
        function closeImgDialog() { document.getElementById('imgOverlay').classList.remove('open'); }
        function imgFromFile(file) {
            if (!file) return;
            if (!/^image\//.test(file.type)) { toast('Alege un fișier imagine'); return; }
            const fr = new FileReader();
            fr.onload = () => insertImageSrc(fr.result);   // data URL (base64) → se salvează în docx
            fr.readAsDataURL(file);
        }
        function imgFromUrl() {
            const u = document.getElementById('imgUrl').value.trim();
            if (!u) { toast('Scrie un URL'); return; }
            insertImageSrc(u);
        }
        function insertImageSrc(src) {
            closeImgDialog();
            page.focus();
            if (imgSavedRange) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(imgSavedRange); imgSavedRange = null; }
            const safe = src.replace(/"/g, '&quot;');
            runCmd(() => document.execCommand('insertHTML', false, '<img src="' + safe + '" style="max-width:100%">'));
            toast('Imagine inserată');
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
            restoreEditorSelection();
            const sel = window.getSelection();
            let n = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
            if (n && n.nodeType === 3) n = n.parentNode;
            if (!n || !page.contains(n)) { toast('Pune cursorul/selectează în textul-sursă, apoi click pe Format'); return; }
            const cs = getComputedStyle(n);
            const sourceBlock = blockOf(n);
            const bcs = sourceBlock ? getComputedStyle(sourceBlock) : null;
            painter = {
                fontFamily: cs.fontFamily, fontSize: cs.fontSize, color: cs.color,
                fontWeight: cs.fontWeight, fontStyle: cs.fontStyle,
                textDecoration: cs.textDecorationLine, background: cs.backgroundColor,
                textAlign: normalizeTextAlign(bcs ? bcs.textAlign : '')
            };
            document.getElementById('btnPainter').classList.add('active');
            toast('Stil copiat — click pe paragraf SAU selectează doar câteva cuvinte');
        }
        function normalizeTextAlign(value) {
            const v = String(value || '').toLowerCase();
            if (v === 'center' || v === 'right' || v === 'justify') return v;
            if (v === 'end' || v === '-webkit-right') return 'right';
            if (v === 'left' || v === 'start' || v === '-webkit-left') return 'left';
            return '';
        }
        function applyPainterBlockStyle(block) {
            if (!block || !painter || !painter.textAlign) return;
            block.style.textAlign = painter.textAlign === 'left' ? '' : painter.textAlign;
        }
        function placeCaretAtBlockEnd(block) {
            if (!block) return;
            const range = document.createRange();
            range.selectNodeContents(block);
            range.collapse(false);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            saveEditorSelection();
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
        // limitează un range la interiorul zonei editabile (#pages), dacă a depășit (ex: bara de status)
        function clampRangeToPage(range) {
            if (!page.contains(range.endContainer)) {
                const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT); let last = null, n;
                while ((n = w.nextNode())) last = n;
                if (last) range.setEnd(last, last.nodeValue.length);
            }
            if (!page.contains(range.startContainer)) {
                const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT); const first = w.nextNode();
                if (first) range.setStart(first, 0);
            }
        }
        function applyPainter(selectionOnly) {
            if (!painter) return false;
            const sel = window.getSelection();
            if (!sel.rangeCount || !page.contains(sel.getRangeAt(0).startContainer)) { disarmPainter(); return false; }
            let range = sel.getRangeAt(0);
            const useSel = selectionOnly && !range.collapsed && sel.toString().trim().length > 0;
            let targetBlocks = [];
            if (!useSel) {
                const blk = getBlock(); if (!blk) { disarmPainter(); return false; }
                targetBlocks = [blk];
                range = document.createRange(); range.selectNodeContents(blk);
            } else {
                clampRangeToPage(range);   // dacă selecția a depășit zona editabilă (ex: bara de jos), o limităm la document
            }
            recordUndo();   // Format Painter modifică direct DOM-ul → înregistrează pentru undo
            if (useSel && !targetBlocks.length) targetBlocks = blocksInRange(range);
            targetBlocks.forEach(applyPainterBlockStyle);
            wrapRangeTextNodes(range);
            const keepBlock = targetBlocks[targetBlocks.length - 1] || blockOf(range.startContainer);
            disarmPainter();
            placeCaretAtBlockEnd(keepBlock);
            appCloseApproved = false;
            updateWordCount();
            scheduleAutoBackup();
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
        page.addEventListener('input', () => {
            appCloseApproved = false;
            saveEditorSelection();
            updateWordCount();
            refreshActive();
            scheduleAutoBackup();
        });
        page.addEventListener('keyup', () => { saveEditorSelection(); refreshActive(); });

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
        function makeDocxBlob(inner) {
            const full = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                + '<style>body{font-family:"Times New Roman",serif;font-size:12pt}</style></head><body>'
                + inner + '</body></html>';
            return (typeof htmlDocx === 'undefined') ? null : htmlDocx.asBlob(full);
        }
        async function saveDocx() {
            const t = curTab();
            const inner = getDocHtml();
            const blob = makeDocxBlob(inner);
            if (!blob) { toast('html-docx-js neîncărcat'); return false; }

            // 1) cale cunoscută pe disc (sidebar / cale / drag&drop rezolvat) → overwrite tăcut prin server (fără prompt de permisiune)
            if (currentFile) {
                const b64 = await blobToBase64(blob);
                let target = currentFile;
                if (currentExt && currentExt !== 'docx') {
                    target = target.replace(/\.(pdf|odt|doc)$/i, '.docx');
                    if (!/\.docx$/i.test(target)) target += '.docx';
                }
                return await postSavebin(target, b64, inner);
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
            return await postSavebin(target, b64, inner);
        }
        // POST la savebin + parsare SIGURĂ (text→JSON), ca să nu crape pe warning-uri HTML
        async function postSavebin(target, b64, inner) {
            setInfo('Se salvează…');
            const fd = new FormData(); fd.append('file', target); fd.append('content', b64);
            let txt;
            try {
                const r = await fetch(api('action=savebin'), { method: 'POST', body: fd });
                txt = await r.text();
            } catch (e) { toast('Eroare rețea la salvare: ' + e.message); setInfo('Eroare salvare'); return false; }
            let j;
            try { j = JSON.parse(txt); }
            catch (e) {
                console.error('Răspuns savebin neașteptat:', txt);
                toast('Eroare salvare: răspuns invalid de la server');
                setInfo('Eroare salvare'); return false;
            }
            if (j.ok) {
                toast('Salvat: ' + j.file.split('/').pop() + ' (' + j.bytes + ' B)');
                setInfo('Salvat ' + new Date().toLocaleTimeString());
                currentFile = j.file; currentExt = 'docx';
                document.getElementById('fileName').textContent = j.file;
                const t = curTab();
                if (t) { t.savedHtml = inner; t.html = inner; t.path = j.file; t.ext = 'docx'; t.file = j.file; }
                renderTabs();
                updateBookmarkUi();
                return true;
            }
            toast(j.error || 'Eroare salvare');
            if (j.detail) console.warn('savebin detail:', j.detail);
            setInfo('Eroare salvare');
            return false;
        }
        function blobToBase64(blob) {
            return new Promise((res) => {
                const fr = new FileReader();
                fr.onload = () => res(fr.result.split(',')[1]);
                fr.readAsDataURL(blob);
            });
        }

        // ── Find and Replace (dialog stil Word) ──
        function openFindReplace(tab) {
            const d = document.getElementById('frDialog');
            d.classList.add('open');
            frSwitchTab(tab || 'find');
            // resetează căutarea: gol dacă nu e selecție; altfel prefill din selecție (ca în Word)
            const q = (window.getSelection().toString() || '').trim();
            document.getElementById('frFind').value = (q && q.length < 80) ? q : '';
            setTimeout(() => {
                const el = (tab === 'goto' ? document.getElementById('frGoto') : document.getElementById('frFind'));
                el.focus(); if (el.select) el.select();
            }, 30);
        }
        function closeFR() { document.getElementById('frDialog').classList.remove('open'); }
        function frSwitchTab(tab) {
            frTab = tab;
            ['find', 'replace', 'goto'].forEach(t => document.getElementById('frTab' + t[0].toUpperCase() + t.slice(1)).classList.toggle('active', t === tab));
            document.getElementById('frPaneFR').style.display = (tab === 'goto') ? 'none' : 'block';
            document.getElementById('frPaneGoto').style.display = (tab === 'goto') ? 'block' : 'none';
            // în tab-ul Find ascundem câmpul „Replace with" și butoanele de replace
            const isRep = tab === 'replace';
            document.getElementById('frReplaceField').style.display = isRep ? 'flex' : 'none';
            document.getElementById('frReplaceBtn').style.display = isRep ? 'inline-block' : 'none';
            document.getElementById('frReplaceAllBtn').style.display = isRep ? 'inline-block' : 'none';
        }
        let frTab = 'find';
        function frToggleMore() {
            const o = document.getElementById('frOptions'); const b = document.getElementById('frMoreBtn');
            o.classList.toggle('open');
            b.textContent = o.classList.contains('open') ? '<< Less' : 'More >>';
        }
        function frOpts() {
            return {
                matchCase: document.getElementById('frMatchCase').checked,
                whole: document.getElementById('frWhole').checked,
                wildcards: document.getElementById('frWildcards').checked,
                prefix: document.getElementById('frPrefix').checked,
                suffix: document.getElementById('frSuffix').checked,
                ignoreSpace: document.getElementById('frIgnoreSpace').checked,
                dir: document.getElementById('frDir').value
            };
        }
        function frBuildRegex() {
            const o = frOpts();
            let q = document.getElementById('frFind').value;
            if (!q) return null;
            if (!o.wildcards) q = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            if (o.ignoreSpace) q = q.replace(/\s+/g, '\\s+');
            let p = q;
            if (o.whole) p = '\\b' + p + '\\b';
            else { if (o.prefix) p = '\\b' + p; if (o.suffix) p = p + '\\b'; }
            try { return new RegExp(p, 'g' + (o.matchCase ? '' : 'i')); } catch (e) { toast('Expresie invalidă'); return null; }
        }
        // hartă text → noduri, pentru a construi Range din offset-uri de caractere
        function docTextMap() {
            const nodes = [], w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            let n, text = '', pos = 0;
            while ((n = w.nextNode())) { nodes.push({ node: n, start: pos }); text += n.nodeValue; pos += n.nodeValue.length; }
            return { text, nodes };
        }
        function rangeFromOffsets(map, s, e) {
            // la o graniță între noduri: START → nodul URMĂTOR (offset 0); END → nodul ANTERIOR (la final).
            // Altfel selecția ar „atinge" paragraful vecin și stilul s-ar aplica și pe el.
            const find = (off, isEnd) => {
                for (let i = 0; i < map.nodes.length; i++) {
                    const it = map.nodes[i], end = it.start + it.node.nodeValue.length;
                    if (off < end || (off === end && (isEnd || i === map.nodes.length - 1)))
                        return { node: it.node, offset: Math.max(0, off - it.start) };
                }
                const last = map.nodes[map.nodes.length - 1];
                return { node: last.node, offset: last.node.nodeValue.length };
            };
            const a = find(s, false), b = find(e, true);
            const r = document.createRange(); r.setStart(a.node, a.offset); r.setEnd(b.node, b.offset); return r;
        }
        let frMatchSig = '', frLastIdx = -1;
        function frFindNext(forceBack) {
            const re = frBuildRegex(); if (!re) { toast('Scrie ce cauți'); return; }
            const o = frOpts();
            const back = forceBack === true || o.dir === 'up';
            const map = docTextMap();
            const matches = []; let m;
            while ((m = re.exec(map.text))) { matches.push({ s: m.index, e: m.index + m[0].length }); if (m.index === re.lastIndex) re.lastIndex++; }
            if (!matches.length) { toast('Nu am găsit „' + document.getElementById('frFind').value + '"'); frLastIdx = -1; return; }
            const sig = document.getElementById('frFind').value + '|' + JSON.stringify(o) + '|' + map.text.length;
            if (sig !== frMatchSig) {
                // căutare nouă (text/opțiuni schimbate) → pornește de la poziția caretului
                frMatchSig = sig;
                const from = back ? (selOffset('start') || 0) : (selOffset('end') || 0);
                let idx = back ? [...matches.keys()].reverse().find(i => matches[i].e <= from)
                    : matches.findIndex(x => x.s >= from);
                frLastIdx = (idx === undefined || idx < 0) ? (back ? matches.length - 1 : 0) : idx;
            } else {
                // aceeași căutare → avansează ciclic (wrap la capăt)
                frLastIdx = back ? (frLastIdx - 1 + matches.length) % matches.length
                    : (frLastIdx + 1) % matches.length;
            }
            const target = matches[frLastIdx];
            const r = rangeFromOffsets(map, target.s, target.e);
            page.focus();
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            (r.startContainer.parentElement || page).scrollIntoView({ block: 'center' });
            setInfo('Rezultat ' + (frLastIdx + 1) + ' / ' + matches.length);
        }
        function frReplace() {
            const sel = window.getSelection();
            const repl = document.getElementById('frReplace').value;
            if (sel.rangeCount && !sel.getRangeAt(0).collapsed && sel.toString().length) {
                runCmd(() => document.execCommand('insertText', false, repl));
            }
            frFindNext(false);
        }
        function frReplaceAll() {
            const re = frBuildRegex(); if (!re) { toast('Scrie ce cauți'); return; }
            const repl = document.getElementById('frReplace').value;
            recordUndo();
            const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            const list = []; let n; while ((n = w.nextNode())) list.push(n);
            let cnt = 0;
            list.forEach(node => {
                re.lastIndex = 0;
                const out = node.nodeValue.replace(re, () => { cnt++; return repl; });
                if (out !== node.nodeValue) node.nodeValue = out;
            });
            if (!cnt) { const t = curTab(); if (t && t.undo) t.undo.pop(); }
            toast(cnt + ' înlocuiri'); if (cnt) repaginate(); else updateWordCount();
        }
        function frGoTo() {
            const n = parseInt(document.getElementById('frGoto').value, 10);
            const sheets = page.querySelectorAll('.page');
            if (!(n >= 1) || !sheets.length) return;
            const s = sheets[Math.min(n, sheets.length) - 1];
            canvasEl.scrollTo({ top: s.offsetTop - canvasEl.offsetTop - 12, behavior: 'smooth' });
            setInfo('Pagina ' + Math.min(n, sheets.length));
        }
        function frGoToRel(d) { gotoPage(d); }
        // Special ▾ menu
        function frSpecial(ev) {
            const m = document.getElementById('frSpecialMenu');
            const r = ev.currentTarget.getBoundingClientRect();
            m.style.left = r.left + 'px'; m.style.top = (r.bottom + 2) + 'px';
            m.classList.toggle('open');
            ev.stopPropagation();
        }
        function frInsertSpecial(code) {
            const inp = document.getElementById('frFind');
            inp.value += code; inp.focus();
            document.getElementById('frSpecialMenu').classList.remove('open');
            // ^? → orice caracter, ^# → orice cifră (în mod wildcards/regex)
            if (code === '^?' || code === '^#') document.getElementById('frWildcards').checked = true;
            if (code === '^?') inp.value = inp.value.replace('^?', '.');
            if (code === '^#') inp.value = inp.value.replace('^#', '\\d');
            if (code === '^t') inp.value = inp.value.replace('^t', '\\t');
            if (code === '^p') { document.getElementById('frWildcards').checked = true; inp.value = inp.value.replace('^p', '\\n'); }
        }

        // ── Meniul „Select" ──
        function toggleSelectMenu(ev) {
            const m = document.getElementById('selectMenu');
            const r = document.getElementById('btnSelect').getBoundingClientRect();
            m.style.left = r.left + 'px'; m.style.top = (r.bottom + 2) + 'px';
            m.classList.toggle('open');
            ev.stopPropagation();
        }
        function closeSelectMenu() { document.getElementById('selectMenu').classList.remove('open'); }
        function selSelectAll() {
            closeSelectMenu(); page.focus();
            const r = document.createRange(); r.selectNodeContents(page);
            const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            setInfo('Tot textul selectat');
        }
        function selSelectObjects() {
            closeSelectMenu();
            const objs = page.querySelectorAll('img, table');
            page.querySelectorAll('.obj-outline').forEach(o => o.classList.remove('obj-outline'));
            objs.forEach(o => o.classList.add('obj-outline'));
            if (!document.getElementById('objOutlineStyle')) {
                const st = document.createElement('style'); st.id = 'objOutlineStyle';
                st.textContent = '.obj-outline{outline:2px solid #2b579a !important;outline-offset:1px}';
                document.head.appendChild(st);
            }
            if (objs.length) objs[0].scrollIntoView({ block: 'center' });
            toast(objs.length + ' obiecte (imagini/tabele) marcate');
        }
        function selSelectSimilar() {
            closeSelectMenu();
            const sel = window.getSelection();
            let n = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
            if (n && n.nodeType === 3) n = n.parentElement;
            if (!n || !page.contains(n)) { toast('Pune cursorul în textul de referință'); return; }
            const cs = getComputedStyle(n);
            const key = (el) => { const c = getComputedStyle(el); return c.fontWeight + '|' + c.fontStyle + '|' + Math.round(parseFloat(c.fontSize)) + '|' + c.fontFamily; };
            const ref = key(n);
            page.querySelectorAll('.sim-mark').forEach(o => o.classList.remove('sim-mark'));
            if (!document.getElementById('simStyle')) {
                const st = document.createElement('style'); st.id = 'simStyle';
                st.textContent = '.sim-mark{background:rgba(43,87,154,.18)}';
                document.head.appendChild(st);
            }
            let cnt = 0;
            page.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,span,strong,em,b,i,u').forEach(el => {
                if (el.textContent.trim() && key(el) === ref) { el.classList.add('sim-mark'); cnt++; }
            });
            toast(cnt + ' fragmente cu formatare similară (evidențiate)');
        }
        function selOpenSelectionPane() {
            closeSelectMenu();
            const body = document.getElementById('selPaneBody');
            const objs = [...page.querySelectorAll('img, table, h1, h2, h3')];
            body.innerHTML = objs.length ? '' : '<div style="color:#a19f9d;padding:6px">Niciun obiect/titlu</div>';
            objs.forEach((o, i) => {
                const el = document.createElement('div'); el.className = 'file-item';
                const label = o.tagName === 'IMG' ? '🖼️ Imagine ' + (i + 1)
                    : o.tagName === 'TABLE' ? '▦ Tabel'
                        : '¶ ' + o.textContent.slice(0, 30);
                el.textContent = label;
                el.onclick = () => { o.scrollIntoView({ block: 'center' }); o.classList.add('obj-outline'); setTimeout(() => o.classList.remove('obj-outline'), 1200); };
                body.appendChild(el);
            });
            document.getElementById('selPane').classList.add('open');
        }
        // închide meniurile pop la click în altă parte
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#selectMenu') && !e.target.closest('#btnSelect')) closeSelectMenu();
            if (!e.target.closest('#frSpecialMenu') && !e.target.closest('#frSpecialBtn')) document.getElementById('frSpecialMenu').classList.remove('open');
            if (!e.target.closest('#styleGallery') && !e.target.closest('#styleBtn')) closeStyleGallery();
            if (!e.target.closest('#dexContextMenu')) hideDexContextMenu();
            if (!e.target.closest('.pop-menu')) closeAllMenus();
        });
        // preview live în dialogul „Create a Style"
        ['csFont', 'csSize', 'csColor', 'csColorOn', 'csBold', 'csItalic', 'csUnderline'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', csUpdatePreview);
        });

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

        // ── DEX: verificare ortografică românească (dicționar extern + AutoCorect local + cuvinte personale) ──
        let dexOn = false, dexNspell = null, dexLoading = false, dexContextWord = '', dexAutoAvailable = null;
        const dexAutoKnown = new Set(), dexAutoMiss = new Set();
        const DEX_USER_KEY = 'wordEditor.dexUserWords.v2';
        const DEX_OLD_USER_KEYS = ['wordEditor.dexUserWords.v1'];
        const DEX_USER_WORDS_API = api('action=dex_user_words');
        const DEX_NSPELL = 'https://cdn.jsdelivr.net/npm/nspell/+esm';
        const DEX_SOURCES = [
            {
                label: 'Rospell / Firefox (dictionary-ro 3.0.0, 181k+ intrari)',
                aff: 'https://cdn.jsdelivr.net/npm/dictionary-ro@3.0.0/index.aff',
                dic: 'https://cdn.jsdelivr.net/npm/dictionary-ro@3.0.0/index.dic'
            },
            {
                label: 'LibreOffice ro_RO (fallback)',
                aff: 'https://cdn.jsdelivr.net/gh/LibreOffice/dictionaries@master/ro/ro_RO.aff',
                dic: 'https://cdn.jsdelivr.net/gh/LibreOffice/dictionaries@master/ro/ro_RO.dic'
            }
        ];
        const DEX_WORD_RE = /[A-Za-zĂÂÎȘȚăâîșțŞşŢţ]{2,}/g;
        const DEX_WORD_ONLY_RE = /^[A-Za-zĂÂÎȘȚăâîșțŞşŢţ]{2,}$/;
        const DEX_WORD_CHAR_RE = /[A-Za-zĂÂÎȘȚăâîșțŞşŢţ]/;
        let dexUserWords = loadDexUserWords();
        let dexUserWordsFileLoaded = false;
        let dexUserWordsFileSaving = false;
        let dexUserWordsSaveTimer = null;
        async function fetchDexText(url) {
            const r = await fetch(url);
            if (!r.ok) throw new Error(url + ' -> HTTP ' + r.status);
            return r.text();
        }
        async function loadDexDict() {
            if (dexNspell) return true;
            try {
                const mod = await import(DEX_NSPELL);
                const nspell = mod.default || mod;
                let lastError = null;
                for (const source of DEX_SOURCES) {
                    try {
                        const [aff, dic] = await Promise.all([
                            fetchDexText(source.aff),
                            fetchDexText(source.dic)
                        ]);
                        dexNspell = nspell(aff, dic);
                        console.info('DEX dictionary loaded:', source.label);
                        return true;
                    } catch (e) {
                        lastError = e;
                        console.warn('DEX source failed:', source.label, e);
                    }
                }
                throw lastError || new Error('No DEX source loaded');
            } catch (e) { console.error('DEX load:', e); return false; }
        }
        async function toggleDex() {
            const btn = document.getElementById('btnDex');
            if (dexOn) { clearDex(); dexOn = false; btn.classList.remove('active'); setInfo('DEX oprit'); return; }
            if (dexLoading) return;
            dexLoading = true; setInfo('DEX: se încarcă dicționarul român…'); toast('DEX: se încarcă dicționarul (o singură dată)…', 4000);
            const [ok] = await Promise.all([loadDexDict(), loadDexUserWordsFile(true)]);
            dexLoading = false;
            const stats = await runDexCheck();
            if (!ok && !stats.localOk) {
                clearDex();
                page.setAttribute('lang', 'ro'); page.spellcheck = true;
                dexOn = true; btn.classList.add('active');
                toast('Nu pot încărca dicționarele — am activat verificarea din browser (RO)');
                return;
            }
            dexOn = true; btn.classList.add('active');
        }
        function isAllCaps(w) { return w === w.toUpperCase() && w.length > 1; }
        function hasDiacritics(w) { return /[ăâîșțĂÂÎȘȚşţŞŢ]/.test(w); }
        // cuvinte uzuale CORECTE fără diacritice (au homograf cu diacritice, dar sunt valide ca atare)
        // → nu le marcăm, ca să nu facem zgomot pe text corect
        const DEX_OK = new Set(('ca sau ta tale mai care tare sa mea meu mei mele sale noi voi lor cu un una unu unei unui ' +
            'de pe la o nu da dar prin spre din este sunt era erau are avea vor va vom poate mult multe mare mari bine tot ' +
            'toate cum unde deci doar ori sub ce cine ne le te se ma ii mi ti ale al ai va ca-i sa-i').split(' '));
        const DEX_PLAIN_OK = new Set(('muzica mintea putea sinelui impui deschizi').split(' '));
        // normalizează diacriticele vechi cu cedilă (ş ţ) la cele moderne cu virgulă (ș ț),
        // ca dicționarul (care folosește virgula) să recunoască ambele variante ca valide
        function dexNorm(w) {
            return w.replace(/ş/g, 'ș').replace(/Ş/g, 'Ș')  // ş→ș, Ş→Ș
                .replace(/ţ/g, 'ț').replace(/Ţ/g, 'Ț');     // ţ→ț, Ţ→Ț
        }
        function dexWordKey(w) {
            return dexNorm(String(w || '').trim()).toLowerCase();
        }
        function loadDexUserWords() {
            try {
                const merged = [];
                [DEX_USER_KEY, ...DEX_OLD_USER_KEYS].forEach((key, idx) => {
                    const words = JSON.parse(localStorage.getItem(key)) || [];
                    words.forEach(w => {
                        const normalized = dexWordKey(w);
                        if (idx > 0 && normalized === 'buniica') return;
                        merged.push(normalized);
                    });
                });
                const set = new Set(merged.filter(w => DEX_WORD_ONLY_RE.test(w)));
                if (!localStorage.getItem(DEX_USER_KEY) && set.size) {
                    localStorage.setItem(DEX_USER_KEY, JSON.stringify([...set].sort()));
                }
                return set;
            } catch (e) { return new Set(); }
        }
        function saveDexUserWords(immediate = false) {
            localStorage.setItem(DEX_USER_KEY, JSON.stringify([...dexUserWords].sort()));
            if (immediate) return saveDexUserWordsFile();
            queueDexUserWordsFileSave();
            return Promise.resolve(true);
        }
        async function loadDexUserWordsFile(force = false, syncBack = true) {
            if (dexUserWordsFileLoaded && !force) return true;
            try {
                const r = await fetch(DEX_USER_WORDS_API);
                const j = await r.json();
                if (!j.ok) throw new Error(j.error || 'Nu pot citi user-words.txt');
                const fileWords = new Set();
                (j.words || []).forEach(w => {
                    const key = dexWordKey(w);
                    if (DEX_WORD_ONLY_RE.test(key)) fileWords.add(key);
                });
                dexUserWordsFileLoaded = true;
                if (j.exists) {
                    dexUserWords = fileWords;
                    localStorage.setItem(DEX_USER_KEY, JSON.stringify([...dexUserWords].sort()));
                } else if (syncBack && dexUserWords.size) {
                    queueDexUserWordsFileSave();
                }
                return true;
            } catch (e) {
                console.warn('DEX user words file:', e);
                return false;
            }
        }
        function queueDexUserWordsFileSave() {
            clearTimeout(dexUserWordsSaveTimer);
            dexUserWordsSaveTimer = setTimeout(() => saveDexUserWordsFile(), 150);
        }
        async function saveDexUserWordsFile() {
            if (dexUserWordsFileSaving) return;
            dexUserWordsFileSaving = true;
            try {
                const r = await fetch(DEX_USER_WORDS_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ words: [...dexUserWords].sort() })
                });
                const j = await r.json();
                if (!j.ok) throw new Error(j.error || 'Nu pot scrie user-words.txt');
                dexUserWordsFileLoaded = true;
                return true;
            } catch (e) {
                console.warn('DEX user words save:', e);
                toast('Nu pot salva dictionarul personal in user-words.txt');
                return false;
            } finally {
                dexUserWordsFileSaving = false;
            }
        }
        function isDexUserWord(w) {
            return dexUserWords.has(dexWordKey(w));
        }
        function isDexAutoWord(w) {
            return dexAutoKnown.has(dexWordKey(w));
        }
        function isDexExternalWord(w) {
            return !!(dexNspell && dexNspell.correct(dexNorm(String(w || ''))));
        }
        function isKnownDictionaryWord(w) {
            return isDexExternalWord(w) || isDexAutoWord(w);
        }
        function isKnownDexWord(w) {
            return isDexUserWord(w) || isKnownDictionaryWord(w);
        }
        function isTrustedPlainDexWord(w) {
            const key = dexWordKey(w);
            return DEX_OK.has(key) || DEX_PLAIN_OK.has(key) || isDexUserWord(key) || isDexExternalWord(key);
        }
        async function checkDexAutoWords(words) {
            const todo = [];
            const seen = new Set();
            words.forEach(w => {
                const key = dexWordKey(w);
                if (!DEX_WORD_ONLY_RE.test(key) || dexAutoKnown.has(key) || dexAutoMiss.has(key) || seen.has(key)) return;
                seen.add(key); todo.push(key);
            });
            if (!todo.length) return dexAutoAvailable !== false;
            try {
                const r = await fetch(api('action=autocorect_check'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ words: todo })
                });
                const j = await r.json();
                if (!j.ok) { dexAutoAvailable = false; return false; }
                const found = new Set((j.found || []).map(dexWordKey));
                todo.forEach(w => (found.has(w) ? dexAutoKnown : dexAutoMiss).add(w));
                dexAutoAvailable = true;
                return true;
            } catch (e) {
                console.warn('DEX AutoCorect check:', e);
                dexAutoAvailable = false;
                return false;
            }
        }
        async function addDexUserWord(word) {
            const key = dexWordKey(word);
            if (!DEX_WORD_ONLY_RE.test(key)) { hideDexContextMenu(); return; }
            const existed = dexUserWords.has(key);
            dexUserWords.add(key);
            await saveDexUserWords(true);
            hideDexContextMenu();
            if (dexOn) runDexCheck();
            toast(existed ? ('Deja in dictionar: ' + word) : ('Adaugat in dictionar: ' + word));
        }
        async function removeDexUserWord(word) {
            const key = dexWordKey(word);
            if (!dexUserWords.has(key)) { toast('Cuvantul nu este in dictionarul personal: ' + word); refreshDexContextActions(); return; }
            dexUserWords.delete(key);
            await saveDexUserWords(true);
            hideDexContextMenu();
            if (dexOn) runDexCheck();
            toast('Sters din dictionar: ' + word);
        }
        async function renameDexUserWord(oldWord, newWord) {
            const oldKey = dexWordKey(oldWord);
            const newKey = dexWordKey(newWord);
            if (!dexUserWords.has(oldKey)) { toast('Cuvantul nu este in dictionarul personal: ' + oldWord); refreshDexContextActions(); return; }
            if (!DEX_WORD_ONLY_RE.test(newKey)) { toast('Cuvant invalid: ' + newWord); refreshDexContextActions(); return; }
            if (oldKey === newKey) { hideDexContextMenu(); toast('Nicio modificare in dictionar'); return; }
            if (dexUserWords.has(newKey)) { toast('Exista deja in dictionar: ' + newWord); refreshDexContextActions(); return; }
            dexUserWords.delete(oldKey);
            dexUserWords.add(newKey);
            await saveDexUserWords(true);
            hideDexContextMenu();
            if (dexOn) runDexCheck();
            toast('Editat in dictionar: ' + oldWord + ' -> ' + newWord);
        }
        const DEX_DIAC_OPTS = { a: ['ă', 'â'], i: ['î'], s: ['ș'], t: ['ț'] };
        // un cuvânt fără diacritice e „suspect" dacă o variantă CU diacritice e cuvânt valid (ex: viata→viață)
        function dexDiacriticCandidates(w) {
            const idxs = []; for (let i = 0; i < w.length; i++) if (DEX_DIAC_OPTS[w[i]]) idxs.push(i);
            if (!idxs.length || idxs.length > 5) return [];
            const opts = idxs.map(i => [w[i], ...DEX_DIAC_OPTS[w[i]]]);
            let total = 1; for (const o of opts) total *= o.length;
            if (total > 48) return [];
            const out = [];
            for (let mask = 1; mask < total; mask++) {
                let m = mask; const chars = w.split('');
                for (let k = 0; k < idxs.length; k++) { const o = opts[k]; const c = m % o.length; m = (m / o.length) | 0; if (c) chars[idxs[k]] = o[c]; }
                out.push(chars.join(''));
            }
            return out;
        }
        function missingDiacritics(w) {
            const candidates = dexDiacriticCandidates(w);
            if (!candidates.length) return false;
            if (isTrustedPlainDexWord(w)) return false;
            for (const candidate of candidates)
                if (isKnownDictionaryWord(candidate)) return true;
            return false;
        }
        async function runDexCheck() {
            clearDex();
            const re = DEX_WORD_RE;
            const walker = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            const nodes = []; let n;
            while ((n = walker.nextNode())) { if (n.parentElement && n.parentElement.classList.contains('dex-bad')) continue; nodes.push(n); }
            const nodeMatches = [], autoQueries = [];
            for (const node of nodes) {
                const text = node.nodeValue;
                re.lastIndex = 0;
                const matches = []; let m;
                while ((m = re.exec(text))) {
                    const word = m[0], lw = dexWordKey(word);
                    matches.push([m.index, word]);
                    autoQueries.push(lw);
                    if (!isAllCaps(word) && !hasDiacritics(word) && !DEX_OK.has(lw))
                        dexDiacriticCandidates(lw).forEach(c => autoQueries.push(c));
                }
                if (matches.length) nodeMatches.push({ node, text, matches });
            }
            const localOk = await checkDexAutoWords(autoQueries);
            let total = 0, bad = 0;
            for (const item of nodeMatches) {
                const { node, text, matches } = item;
                const frag = document.createDocumentFragment();
                let pos = 0;
                for (const [idx, word] of matches) {
                    if (idx > pos) frag.appendChild(document.createTextNode(text.slice(pos, idx)));
                    total++;
                    let flag = false;
                    const lw = dexWordKey(word);
                    if (!isDexUserWord(word) && !isAllCaps(word) && !DEX_OK.has(lw)) {
                        if (!hasDiacritics(word) && missingDiacritics(lw)) flag = true;
                        else if (!isKnownDictionaryWord(word)) flag = true;
                    }
                    if (!flag) frag.appendChild(document.createTextNode(word));
                    else { const sp = document.createElement('span'); sp.className = 'dex-bad'; sp.textContent = word; frag.appendChild(sp); bad++; }
                    pos = idx + word.length;
                }
                if (pos < text.length) frag.appendChild(document.createTextNode(text.slice(pos)));
                node.parentNode.replaceChild(frag, node);
            }
            setInfo('DEX: ' + bad + ' probleme ortografice (din ' + total + ')');
            toast(bad ? (bad + ' probleme ortografice subliniate') : 'Nicio problemă ortografică găsită');
            // sari la primul cuvânt suspect
            const first = page.querySelector('span.dex-bad');
            if (first) first.scrollIntoView({ block: 'center' });
            return { total, bad, localOk };
        }
        function clearDex() {
            page.querySelectorAll('span.dex-bad').forEach(sp => { sp.replaceWith(document.createTextNode(sp.textContent)); });
            page.normalize();
            page.removeAttribute('lang'); page.spellcheck = false;
        }
        function dexWordFromPoint(x, y, target) {
            const bad = target && target.closest ? target.closest('span.dex-bad') : null;
            if (bad && page.contains(bad)) return bad.textContent.trim();

            let range = null;
            if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(x, y);
            else if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(x, y);
                if (pos) {
                    range = document.createRange();
                    range.setStart(pos.offsetNode, pos.offset);
                }
            }
            if (!range || !range.startContainer || range.startContainer.nodeType !== Node.TEXT_NODE || !page.contains(range.startContainer)) return '';
            const text = range.startContainer.nodeValue || '';
            let off = Math.min(range.startOffset, text.length - 1);
            if (off > 0 && !DEX_WORD_CHAR_RE.test(text.charAt(off)) && DEX_WORD_CHAR_RE.test(text.charAt(off - 1))) off--;
            if (!DEX_WORD_CHAR_RE.test(text.charAt(off))) return '';
            let start = off, end = off + 1;
            while (start > 0 && DEX_WORD_CHAR_RE.test(text.charAt(start - 1))) start--;
            while (end < text.length && DEX_WORD_CHAR_RE.test(text.charAt(end))) end++;
            const word = text.slice(start, end);
            return DEX_WORD_ONLY_RE.test(word) ? word : '';
        }
        function showDexContextMenu(x, y, word) {
            const menu = document.getElementById('dexContextMenu');
            const wordEl = document.getElementById('dexContextWord');
            const input = document.getElementById('dexEditWordInput');
            if (!menu || !wordEl || !input) return;
            dexContextWord = word;
            menu.dataset.word = word;
            wordEl.textContent = word;
            input.value = word;
            refreshDexContextActions();
            menu.setAttribute('aria-hidden', 'false');
            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            menu.classList.add('open');
            requestAnimationFrame(() => {
                const r = menu.getBoundingClientRect();
                const left = Math.max(8, Math.min(x, window.innerWidth - r.width - 8));
                const top = Math.max(8, Math.min(y, window.innerHeight - r.height - 8));
                menu.style.left = left + 'px';
                menu.style.top = top + 'px';
            });
        }
        function hideDexContextMenu() {
            const menu = document.getElementById('dexContextMenu');
            if (!menu) return;
            menu.classList.remove('open');
            menu.setAttribute('aria-hidden', 'true');
        }
        function getDexContextInputWord() {
            const input = document.getElementById('dexEditWordInput');
            return input ? input.value.trim() : dexContextWord;
        }
        function getDexContextWord() {
            const menu = document.getElementById('dexContextMenu');
            return (menu && menu.dataset.word) || dexContextWord;
        }
        function refreshDexContextActions() {
            const addBtn = document.getElementById('dexAddWordBtn');
            const editBtn = document.getElementById('dexEditWordBtn');
            const deleteBtn = document.getElementById('dexDeleteWordBtn');
            const input = document.getElementById('dexEditWordInput');
            if (!addBtn || !editBtn || !deleteBtn || !input) return;
            const oldKey = dexWordKey(getDexContextWord());
            const newKey = dexWordKey(input.value);
            const selectedIsUserWord = dexUserWords.has(oldKey);
            const inputIsValid = DEX_WORD_ONLY_RE.test(newKey);
            const inputAlreadyExists = dexUserWords.has(newKey);
            addBtn.textContent = inputAlreadyExists ? 'Cuvantul este deja in dictionar' : 'Adauga in dictionar';
            addBtn.disabled = !inputIsValid || inputAlreadyExists;
            editBtn.textContent = selectedIsUserWord ? 'Salveaza editarea' : 'Editeaza (doar cuvant personal)';
            editBtn.disabled = !selectedIsUserWord || !inputIsValid || oldKey === newKey || inputAlreadyExists;
            deleteBtn.disabled = !selectedIsUserWord;
        }
        function dexContextInputKey(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const oldKey = dexWordKey(getDexContextWord());
            const newKey = dexWordKey(getDexContextInputWord());
            if (dexUserWords.has(oldKey) && oldKey !== newKey) editDexContextWord();
            else addDexContextWord();
        }
        function addDexContextWord() {
            const word = getDexContextInputWord();
            if (word) addDexUserWord(word);
        }
        function editDexContextWord() {
            const word = getDexContextInputWord();
            if (word) renameDexUserWord(getDexContextWord(), word);
        }
        function deleteDexContextWord() {
            const word = getDexContextWord();
            if (word) removeDexUserWord(word);
        }
        function openDexContextFromEvent(e) {
            const word = dexWordFromPoint(e.clientX, e.clientY, e.target);
            if (!word) return false;
            e.preventDefault();
            showDexContextMenu(e.clientX, e.clientY, word);
            return true;
        }
        page.addEventListener('contextmenu', e => {
            openDexContextFromEvent(e);
        });
        page.addEventListener('mouseup', e => {
            if (e.button === 2) openDexContextFromEvent(e);
        });

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
                if (e.target.classList.contains('close-x') || e.target.classList.contains('x')) return;
                drag = true; sx = e.clientX; sy = e.clientY;
                const r = p.getBoundingClientRect(); ox = r.left; oy = r.top;
                p.style.right = 'auto'; p.style.transform = 'none';
                p.style.left = ox + 'px'; p.style.top = oy + 'px';
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
            updateBookmarkUi();
        }
        // un tab e „murdar" daca textul curent difera de cel salvat/deschis (baseline)
        function isTabDirty(t) {
            if (!t) return false;
            const cur = (t.id === activeId) ? getDocHtml() : t.html;
            return cur !== t.savedHtml;
        }
        function syncActiveTab() {
            const cur = tabs.find(t => t.id === activeId);
            if (cur) cur.html = getDocHtml();
            return cur;
        }
        function isEmptyDocHtml(html) {
            const compact = String(html || '').replace(/\s+/g, '').toLowerCase();
            return compact === '' || compact === '<p><br></p>' || compact === '<br>';
        }
        function hasUntabbedDirtyDoc() {
            return !activeId && isEmptyDocHtml(getDocHtml()) === false;
        }
        function hasUnsavedChanges() {
            syncActiveTab();
            return tabs.some(t => isTabDirty(t)) || hasUntabbedDirtyDoc();
        }
        const AUTO_BACKUP_INTERVAL_MS = 120000; // 2 minute
        const AUTO_BACKUP_FIRST_DELAY_MS = 12000; // primul backup dupa editare apare repede
        let autoBackupBusy = false;
        let autoBackupTimer = null;
        const autoBackupHashes = new Map();
        function autoBackupHash(html) {
            let h = 2166136261;
            const s = String(html || '');
            for (let i = 0; i < s.length; i++) {
                h ^= s.charCodeAt(i);
                h = Math.imul(h, 16777619);
            }
            return s.length + ':' + (h >>> 0).toString(16);
        }
        function autoBackupBaseName(name) {
            const raw = String(name || '').split(/[\\/]/).pop() || 'document nou.docx';
            return raw.replace(/\.(docx|doc|odt|pdf|html?)$/i, '') + '.docx';
        }
        function autoBackupEntries() {
            syncActiveTab();
            const out = [];
            tabs.forEach(t => {
                const html = (t.id === activeId) ? getDocHtml() : (t.html || '');
                if (!isTabDirty(t) || isEmptyDocHtml(html)) return;
                out.push({
                    key: 'tab-' + t.id,
                    fileName: autoBackupBaseName(t.file || t.path || 'document nou.docx'),
                    source: t.path || '',
                    html
                });
            });
            if (hasUntabbedDirtyDoc()) {
                out.push({ key: 'untabbed', fileName: 'document nou.docx', source: '', html: getDocHtml() });
            }
            return out;
        }
        async function postAutoBackup(entry) {
            const fd = new FormData();
            fd.append('fileName', entry.fileName);
            fd.append('source', entry.source || '');
            const blob = makeDocxBlob(entry.html);
            if (blob) fd.append('content', await blobToBase64(blob));
            else fd.append('html', entry.html);
            const r = await fetch(api('action=autobackup'), { method: 'POST', body: fd });
            const txt = await r.text();
            let j;
            try { j = JSON.parse(txt); }
            catch (_) { throw new Error('raspuns invalid la autobackup'); }
            if (!j.ok) throw new Error(j.error || 'autobackup esuat');
            return j;
        }
        async function autoBackupNow(force = false) {
            if (autoBackupBusy) return;
            const entries = autoBackupEntries()
                .map(e => ({ ...e, hash: autoBackupHash(e.html) }))
                .filter(e => force || autoBackupHashes.get(e.key) !== e.hash);
            if (!entries.length) return;
            autoBackupBusy = true;
            try {
                let saved = 0, lastFile = '';
                for (const entry of entries) {
                    const j = await postAutoBackup(entry);
                    autoBackupHashes.set(entry.key, entry.hash);
                    saved++;
                    lastFile = j.file || lastFile;
                }
                if (saved) setInfo('Backup Temp ' + new Date().toLocaleTimeString());
                if (lastFile) console.info('AutoBackup:', lastFile);
            } catch (e) {
                console.warn('AutoBackup:', e);
                setInfo('Backup Temp esuat');
            } finally {
                autoBackupBusy = false;
            }
        }
        function scheduleAutoBackup(delay = AUTO_BACKUP_FIRST_DELAY_MS) {
            clearTimeout(autoBackupTimer);
            autoBackupTimer = setTimeout(() => autoBackupNow(false), delay);
        }
        function autoBackupBeacon() {
            if (!navigator.sendBeacon) return;
            const entries = autoBackupEntries().slice(0, 3);
            entries.forEach(entry => {
                const fd = new FormData();
                fd.append('fileName', autoBackupBaseName(entry.fileName));
                fd.append('source', entry.source || '');
                fd.append('html', entry.html);
                navigator.sendBeacon(api('action=autobackup'), fd);
            });
        }

        let bookmarkStore = {};
        let bookmarkStoreLoaded = false;
        let bookmarkSaveTimer = null;

        function normalizeBookmarkKey(path) {
            return String(path || '').replace(/\\/g, '/').trim().toLocaleLowerCase();
        }
        function activeBookmarkPath() {
            const t = curTab();
            return (t && (t.path || t.file)) || currentFile || '';
        }
        function activeBookmarkKey() {
            return normalizeBookmarkKey(activeBookmarkPath());
        }
        function bookmarkBlocks() {
            return [...page.querySelectorAll('.page p,.page h1,.page h2,.page h3,.page h4,.page h5,.page h6,.page li,.page blockquote,.page pre')];
        }
        function bookmarkTextSample(block) {
            return String(block ? block.textContent : '').replace(/\s+/g, ' ').trim().slice(0, 180);
        }
        function bookmarkPageNumber(block) {
            const sheet = block ? block.closest('.page') : null;
            if (!sheet) return 1;
            return [...page.querySelectorAll('.page')].indexOf(sheet) + 1;
        }
        function blockNearViewportCenter() {
            const blocks = bookmarkBlocks();
            if (!blocks.length) return null;
            const cr = canvasEl.getBoundingClientRect();
            const centerY = cr.top + cr.height * 0.38;
            let best = blocks[0], bestDist = Infinity;
            blocks.forEach(block => {
                const r = block.getBoundingClientRect();
                if (r.bottom < cr.top || r.top > cr.bottom) return;
                const dist = Math.abs((r.top + Math.min(r.height, 40) / 2) - centerY);
                if (dist < bestDist) { best = block; bestDist = dist; }
            });
            return best;
        }
        function currentBookmarkBlock() {
            const sel = window.getSelection();
            if (sel.rangeCount && page.contains(sel.getRangeAt(0).startContainer)) {
                const blk = blockOf(sel.getRangeAt(0).startContainer);
                if (blk) return blk;
            }
            return blockNearViewportCenter();
        }
        function activeBookmark() {
            const key = activeBookmarkKey();
            return key ? bookmarkStore[key] : null;
        }
        function findBookmarkBlock(bookmark) {
            const blocks = bookmarkBlocks();
            if (!bookmark || !blocks.length) return null;
            const idx = Number.isFinite(bookmark.blockIndex) ? bookmark.blockIndex : parseInt(bookmark.blockIndex, 10);
            const sample = String(bookmark.sample || '').replace(/\s+/g, ' ').trim();
            if (Number.isFinite(idx) && blocks[idx]) {
                if (!sample || bookmarkTextSample(blocks[idx]) === sample || bookmarkTextSample(blocks[idx]).indexOf(sample.slice(0, 80)) === 0) return blocks[idx];
            }
            if (sample) {
                const exact = blocks.find(b => bookmarkTextSample(b) === sample);
                if (exact) return exact;
                const head = sample.slice(0, 80);
                if (head) {
                    const partial = blocks.find(b => bookmarkTextSample(b).indexOf(head) !== -1);
                    if (partial) return partial;
                }
            }
            return Number.isFinite(idx) ? (blocks[idx] || null) : null;
        }
        async function loadBookmarksFile() {
            try {
                const r = await fetch(api('action=bookmarks'), { cache: 'no-store' });
                const j = await r.json();
                if (j && j.ok && j.bookmarks && typeof j.bookmarks === 'object') bookmarkStore = Array.isArray(j.bookmarks) ? {} : j.bookmarks;
                bookmarkStoreLoaded = true;
                updateBookmarkUi();
            } catch (e) {
                console.warn('Bookmarks:', e);
                bookmarkStoreLoaded = true;
                updateBookmarkUi();
            }
        }
        function saveBookmarksFileSoon() {
            clearTimeout(bookmarkSaveTimer);
            bookmarkSaveTimer = setTimeout(saveBookmarksFile, 250);
        }
        async function saveBookmarksFile() {
            try {
                const r = await fetch(api('action=bookmarks'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=utf-8' },
                    body: JSON.stringify({ bookmarks: bookmarkStore })
                });
                const j = await r.json();
                if (!j.ok) throw new Error(j.error || 'salvare semne esuata');
            } catch (e) {
                console.warn('Bookmarks save:', e);
                toast('Nu pot salva semnul de carte');
            }
        }
        function setBookmarkHere() {
            const key = activeBookmarkKey();
            const source = activeBookmarkPath();
            if (!key) { toast('Deschide sau salveaza un fisier inainte de semn de carte'); return; }
            const block = currentBookmarkBlock();
            if (!block) { toast('Nu gasesc paragraful curent'); return; }
            const blocks = bookmarkBlocks();
            const pageNo = bookmarkPageNumber(block);
            bookmarkStore[key] = {
                file: source,
                blockIndex: Math.max(0, blocks.indexOf(block)),
                page: pageNo,
                sample: bookmarkTextSample(block),
                scrollTop: Math.max(0, Math.round(canvasEl.scrollTop)),
                updatedAt: new Date().toISOString()
            };
            saveBookmarksFileSoon();
            updateBookmarkUi();
            toast('Semn de carte pus la pagina ' + pageNo);
        }
        function scrollToBookmark(bookmark) {
            const block = findBookmarkBlock(bookmark);
            if (!block) {
                canvasEl.scrollTo({ top: Math.max(0, bookmark && bookmark.scrollTop ? bookmark.scrollTop : 0), behavior: 'smooth' });
                toast('Am mers la pozitia aproximativa a semnului');
                setTimeout(updateBookmarkUi, 350);
                return;
            }
            const cr = canvasEl.getBoundingClientRect();
            const br = block.getBoundingClientRect();
            const top = canvasEl.scrollTop + br.top - cr.top - canvasEl.clientHeight * 0.32;
            canvasEl.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            const range = document.createRange();
            range.selectNodeContents(block);
            range.collapse(true);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            saveEditorSelection();
            toast('Semn de carte: pagina ' + (bookmark.page || bookmarkPageNumber(block)));
            setTimeout(updateBookmarkUi, 350);
        }
        function toggleBookmark(e) {
            const bookmark = activeBookmark();
            if (!bookmark || (e && (e.ctrlKey || e.shiftKey))) setBookmarkHere();
            else scrollToBookmark(bookmark);
        }
        function positionBookmarkMarker(block) {
            const marker = document.getElementById('bookmarkMarker');
            if (!marker || !block) return false;
            const sheet = block.closest('.page');
            if (!sheet) return false;
            const cr = canvasEl.getBoundingClientRect();
            const br = block.getBoundingClientRect();
            const sr = sheet.getBoundingClientRect();
            marker.style.top = Math.max(0, canvasEl.scrollTop + br.top - cr.top - 3) + 'px';
            marker.style.left = Math.max(4, canvasEl.scrollLeft + sr.left - cr.left - 30) + 'px';
            marker.classList.add('show');
            return true;
        }
        function updateBookmarkUi() {
            const btn = document.getElementById('bookmarkBtn');
            const marker = document.getElementById('bookmarkMarker');
            const bookmark = activeBookmark();
            const has = !!bookmark;
            if (btn) {
                btn.classList.toggle('has-bookmark', has);
                btn.textContent = has ? '||' : 'T';
                btn.title = has
                    ? 'Semn de carte existent: click ca sa mergi acolo. Ctrl/Shift+click muta semnul aici.'
                    : 'Nu exista semn de carte pentru fisierul curent: click ca sa-l pui aici.';
            }
            if (marker) marker.classList.remove('show');
            if (!has || !bookmarkStoreLoaded) return;
            const block = findBookmarkBlock(bookmark);
            if (block) positionBookmarkMarker(block);
        }
        window.addEventListener('resize', () => updateBookmarkUi());

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
            syncActiveTab();   // salvează editările curente în tab
            const t = tabs.find(t => t.id === id);
            if (!t) return;
            activeId = id;
            currentFile = t.path; currentExt = t.ext;
            document.getElementById('stMode').textContent = (t.ext || '').toUpperCase();
            document.getElementById('pdfPages').innerHTML = '';
            document.getElementById('pdfPages').style.display = 'none';
            setDocHtml(t.html || '');
            if (t.isPdf && t.path) loadPdf(api('action=raw&file=' + encodeURIComponent(t.path)));
            renderTabs(); updateWordCount(); updateBookmarkUi();
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
            } else { renderTabs(); updateBookmarkUi(); }
        }
        async function closeUntabbedDocument() {
            if (!hasUntabbedDirtyDoc()) { showOverlay(); return; }
            const choice = await confirmSave('document nou.docx');
            if (choice === 'cancel') return;
            if (choice === 'save') {
                const ok = await saveDocx();
                if (!ok) return;
            }
            currentFile = null; currentExt = null;
            setDocHtml('');
            document.getElementById('stMode').textContent = '—';
            showOverlay();
        }
        function closeActiveTab() { if (activeId) closeTab(activeId); else closeUntabbedDocument(); }
        async function closeApplication() {
            syncActiveTab();

            for (const item of [...tabs]) {
                const tab = tabs.find(t => t.id === item.id);
                if (!tab) continue;
                if (tab.id !== activeId) activateTab(tab.id);
                tab.html = getDocHtml();
                if (!isTabDirty(tab)) continue;

                const choice = await confirmSave(tab.file || tab.path || 'document nou.docx');
                if (choice === 'cancel') return;
                if (choice === 'save') {
                    const ok = await saveDocx();
                    if (!ok) return;
                }
            }

            if (hasUntabbedDirtyDoc()) {
                const choice = await confirmSave('document nou.docx');
                if (choice === 'cancel') return;
                if (choice === 'save') {
                    const ok = await saveDocx();
                    if (!ok) return;
                }
            }

            await autoBackupNow(true);
            appCloseApproved = true;
            setInfo('Iesire confirmata');
            window.close();
            setTimeout(() => toast('Poti inchide fereastra acum.'), 250);
        }

        // ── Modal confirmare salvare → 'save' | 'discard' | 'cancel' ──
        let cancelActiveSaveConfirm = null;
        function confirmSave(fileName) {
            return new Promise(resolve => {
                if (cancelActiveSaveConfirm) cancelActiveSaveConfirm();
                const ov = document.getElementById('confirmOverlay');
                document.getElementById('confirmMsg').textContent =
                    'Documentul „' + ((fileName || '').split(/[\\/]/).pop() || 'fără nume') + '” are modificări nesalvate.';
                ov.classList.add('open');
                const sv = document.getElementById('cfSave'), ds = document.getElementById('cfDiscard'), cx = document.getElementById('cfCancel');
                function done(val) {
                    ov.classList.remove('open');
                    sv.onclick = ds.onclick = cx.onclick = null;
                    document.removeEventListener('keydown', onKey, true);
                    cancelActiveSaveConfirm = null;
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
                cancelActiveSaveConfirm = () => done('cancel');
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
            renderSidebarRecent();
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

        function isCtrlT(e) {
            return (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key && e.key.toLowerCase() === 't';
        }
        function blockCtrlT(e) {
            if (!isCtrlT(e)) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
        window.addEventListener('keydown', blockCtrlT, true);

        // ── Keyboard shortcuts ──
        document.addEventListener('keydown', e => {
            if (isCtrlT(e)) { e.preventDefault(); e.stopImmediatePropagation(); return; }
            // Esc închide (fără modificări): galeria de stiluri / overlay deschidere / Find&Replace / dialog stil
            if (e.key === 'Escape') {
                if (document.getElementById('dexContextMenu').classList.contains('open')) { e.preventDefault(); hideDexContextMenu(); return; }
                if (document.getElementById('styleGallery').classList.contains('open')) { e.preventDefault(); closeStyleGallery(); return; }
                if (document.getElementById('imgOverlay').classList.contains('open')) { e.preventDefault(); closeImgDialog(); return; }
                if (document.getElementById('csOverlay').classList.contains('open')) { e.preventDefault(); document.getElementById('csOverlay').classList.remove('open'); csEditTarget = null; document.getElementById('csTitle').textContent = 'Creează un stil nou'; return; }
                if (document.getElementById('startOverlay').classList.contains('open')) { e.preventDefault(); hideOverlay(); return; }
                if (document.getElementById('frDialog').classList.contains('open')) { e.preventDefault(); closeFR(); return; }
            }
            if (e.ctrlKey && e.key.toLowerCase() === 's') { e.preventDefault(); saveDocx(); }
            else if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase() === 'z') { e.preventDefault(); doUndo(); }
            else if (e.ctrlKey && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { e.preventDefault(); doRedo(); }
            else if (e.ctrlKey && e.key.toLowerCase() === 'f') { e.preventDefault(); openFindReplace('find'); }
            else if (e.ctrlKey && e.key.toLowerCase() === 'h') { e.preventDefault(); openFindReplace('replace'); }
            else if (e.ctrlKey && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'l') { e.preventDefault(); cmd('justifyLeft'); }
            else if (e.ctrlKey && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'e') { e.preventDefault(); cmd('justifyCenter'); }
            else if (e.ctrlKey && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'r') { e.preventDefault(); cmd('justifyRight'); }
            else if (e.ctrlKey && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'j') { e.preventDefault(); cmd('justifyFull'); }
            // F3 = următorul rezultat (Shift+F3 = anteriorul); ascunde fereastra de căutare
            else if (e.key === 'F3') {
                e.preventDefault();
                closeFR();
                if (document.getElementById('frFind').value.trim()) frFindNext(e.shiftKey === true);
                else openFindReplace('find');
            }
            else if (e.altKey && e.key.toLowerCase() === 'p') { e.preventDefault(); repaginate(); }
            else if (e.ctrlKey && e.key.toLowerCase() === 'o') { e.preventDefault(); showOverlay(); }
            // Ctrl+PageDown / Ctrl+PageUp = navigare pagina cu pagina (ca „browse by page" din Word)
            else if (e.ctrlKey && e.key === 'PageDown') { e.preventDefault(); gotoPage(1); }
            else if (e.ctrlKey && e.key === 'PageUp') { e.preventDefault(); gotoPage(-1); }
            // Ctrl+Home / Ctrl+End = început / sfârșit document
            else if (e.ctrlKey && e.key === 'Home') { e.preventDefault(); caretToDocEdge(false); }
            else if (e.ctrlKey && e.key === 'End') { e.preventDefault(); caretToDocEdge(true); }
        });

        // mută caretul la începutul (false) sau sfârșitul (true) documentului + derulează acolo
        function caretToDocEdge(end) {
            page.focus();
            const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
            let first = null, last = null, n;
            while ((n = w.nextNode())) { if (!first) first = n; last = n; }
            const r = document.createRange();
            if (end && last) r.setStart(last, last.nodeValue.length);
            else if (!end && first) r.setStart(first, 0);
            else { r.selectNodeContents(page); r.collapse(!end); }
            r.collapse(true);
            const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            const host = (end && last ? last.parentElement : (first ? first.parentElement : page));
            if (host && host.scrollIntoView) host.scrollIntoView({ block: end ? 'end' : 'start' });
            canvasEl.scrollTo({ top: end ? canvasEl.scrollHeight : 0, behavior: 'smooth' });
        }

        // ── Backup la inchiderea ferestrei ──
        // Chrome nu permite inlocuirea dialogului nativ "Leave app?" la X-ul ferestrei.
        // De aceea nu setam e.returnValue aici; confirmarea personalizata este pe butonul Iesire.
        window.addEventListener('beforeunload', e => {
            if (appCloseApproved) return;
            if (hasUnsavedChanges()) {
                if (cancelActiveSaveConfirm) cancelActiveSaveConfirm();
                autoBackupBeacon();
            }
        });
        window.addEventListener('pagehide', () => {
            if (hasUnsavedChanges()) autoBackupBeacon();
        });

        // ── Init ──
        buildDiac();
        loadDexUserWordsFile().then(() => { if (dexOn) runDexCheck(); });
        loadBookmarksFile();
        // Enter în câmpurile de căutare → caută / înlocuiește
        document.getElementById('frFind').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); frFindNext(e.shiftKey); } });
        document.getElementById('frReplace').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); frReplace(); } });
        makeDraggable('frTitle', 'frDialog');
        makeDraggable('selPaneHandle', 'selPane');
        makeDraggable('pathHandle', 'pathPopup');
        makeDraggable('diacHandle', 'diacPopup');
        setDocHtml('');   // o coală goală (paragraf gol), gata de scris
        renderTabs();
        loadFileList('');
        renderRecent();
        renderSidebarRecent();
        showOverlay();    // popup de deschidere la pornire (ca în index V.4.php)
        setInterval(() => autoBackupNow(false), AUTO_BACKUP_INTERVAL_MS);
    </script>
</body>

</html>
