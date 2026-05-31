# Bebe - Word Editor — editor de documente `.docx` / `.doc` / `.odt` / `.pdf`

Editor de documente office, în browser, cu aspect și comportament tip **Microsoft Word**:
deschide fișiere Word/ODT/PDF, le afișează **paginat pe coli A4** (cu scroll și Page Up/Down),
le poți edita și salva înapoi ca `.docx`. Aplicație într-un **singur fișier PHP** (`index.php`),
inspirată din proiectul „Mini Dreamweaver" (editor HTML/PHP din folderul părinte).

> Limbă interfață: română. Rulează local sub XAMPP (Apache + PHP 8).

---

## Cuprins
- [Funcționalități](#funcționalități)
- [Cum deschide / salvează fiecare format](#cum-deschide--salvează-fiecare-format)
- [Arhitectură](#arhitectură)
- [Cerințe](#cerințe)
- [Instalare și rulare](#instalare-și-rulare)
- [Scurtături de tastatură](#scurtături-de-tastatură)
- [Limitări cunoscute](#limitări-cunoscute)
- [Structura fișierelor](#structura-fișierelor)

---

## Funcționalități

### Vizualizare paginată (ca în MS Word)
- Conținutul e împărțit în **coli A4 separate**, fiecare cu numărul ei jos („Pag. 1", „Pag. 2"…).
- Derulare normală prin pagini; bara de stare arată live „Pagina X / N".
- **Ctrl+PageDown / Ctrl+PageUp** = salt pagină cu pagină.
- Re‑paginare manuală: buton ¶ în ribbon sau **Alt+P** (după editări mari).
- Optimizat pentru documente de **sute de pagini** (o carte de 225 pagini se paginează în ~0,5 s).

### Toolbar tip Word clasic (tab Home) + extra
- **Clipboard:** Lipește · Decupează · Copiază · **Format Painter** (descriptor de formate).
- **Font:** familie, dimensiune, A▲/A▼, schimbă registrul (Aa), golește formatarea, **B** *I* <u>U</u> ~~S~~, indice/exponent, evidențiere, culoare font.
- **Paragraf:** marcatori, numerotare, indent ⇤⇥, marcaje ¶, aliniere (stânga/centru/dreapta/justify), spațiere rânduri, umbrire.
- **Stiluri:** Normal / Titlu 1–4 / Citat / Cod.
- **Inserare:** link, imagine, tabel, linie orizontală.
- **Editare/Extra:** Undo/Redo, Găsește & Înlocuiește, Selectează tot, Diacritice românești, Traducere (Google Translate).

### Format Painter
- **Click** pe un paragraf → aplică stilul pe **tot paragraful**.
- **Selecție prin tragere cu mouse-ul** → aplică stilul **doar pe textul selectat** (în același paragraf sau în altul), fără a sparge paragrafele.

### Taburi
- Deschide mai multe documente simultan și comută între ele.
- Drag & drop un fișier **oriunde în fereastră** (inclusiv pe linia tab‑urilor) → se deschide ca tab nou; butonul „+" e opțional.
- La comutare se păstrează editările fiecărui tab.

### Undo / Redo unificat
- Funcționează pentru **orice** modificare: tastare, butoane, Format Painter, dimensiune font, spațiere, Replace etc.
- Butoanele ↶ Undo / ↷ Redo și **Ctrl+Z / Ctrl+Y** (+ Ctrl+Shift+Z).
- Istoric **separat per tab**.

### Diacritice românești (combinații preluate din proiectul original)
| Literă | Combinație | Literă | Combinație |
|---|---|---|---|
| ă | Ctrl + A | â | Alt + A |
| î | Ctrl + I | Î | Alt + I |
| ș | Ctrl + Shift + S | Ș | Alt + S |
| ț | Alt + T | Ț | Alt + Shift + T |

### Deschidere & istoric
- **Pop‑up de deschidere la pornire**: cale completă, **drag & drop**, sau listă cu **ultimele 15 fișiere** (ordonate după data deschiderii/închiderii). Se închide cu butonul sau cu **Esc**.
- Fișierele cu cale persistă în `localStorage` (reopenabile oricând); cele aduse prin drag & drop sunt reținute în sesiune.

### Salvare sigură
- **Ctrl+S** salvează ca `.docx`.
- La închiderea unui tab / a aplicației, dacă există modificări nesalvate, apare un pop‑up **Salvează / Nu salva / Anulează**.

---

## Cum deschide / salvează fiecare format

| Format | Deschidere | Salvare |
|---|---|---|
| **.docx** | Parsat **server‑side în PHP** (`word/document.xml` + relații → titluri, bold/italic/subliniat, culori, dimensiuni, aliniere, liste, tabele, imagini). | `.docx` prin `html-docx-js`. |
| **.odt** | Parsat server‑side (`content.xml` din arhiva ZIP). | salvat ca `.docx`. |
| **.pdf** | Randat cu **pdf.js** (fiecare pagină o coală) + text extras, editabil. | salvat ca `.docx`. |
| **.doc** (binar vechi OLE2) | Nu poate fi editat direct — mesaj care recomandă conversia în `.docx`. | — |

> **Round‑trip:** fișierele salvate de editor folosesc formatul `altChunk` al `html-docx-js` (conținut HTML în `word/afchunk.mht`); parser‑ul le recunoaște și le redeschide corect.

> ⚠️ Multe fișiere `.docx` din arhive vechi sunt de fapt `.doc` binare redenumite (semnătură `D0 CF 11 E0`). Editorul le detectează și cere conversia reală în Word.

---

## Arhitectură

- **Un singur fișier**: [`index.php`](index.php) — conține atât API‑ul server (PHP), cât și interfața (HTML/CSS/JS), fără build.
- **Server (PHP):**
  - `?action=docx2html` — docx → HTML (cale `?file=` sau upload `multipart` pentru drag & drop).
  - `?action=odt2html` — odt → HTML.
  - `?action=raw` — servește fișierul brut (pentru pdf.js).
  - `?action=savebin` — scrie `.docx` pe disc (base64 din client).
  - `?action=list` — listă de fișiere (filtrată la docx/doc/odt/pdf).
- **Client (JS):**
  - **pdf.js** (CDN) — randare PDF.
  - **html‑docx‑js** (CDN) — HTML → docx la salvare.
  - Paginator propriu: măsoară blocurile o singură dată într‑o coală‑probă, apoi împarte matematic pe pagini A4.
  - Undo/redo propriu pe bază de snapshot‑uri HTML.

---

## Cerințe

- **XAMPP** cu **PHP 8.0+** și extensiile `zip`, `dom`, `xml` (active implicit).
- **Apache** (sau serverul încorporat PHP pentru test).
- Un browser modern (Chrome recomandat) — pentru CDN (pdf.js, html‑docx‑js) e nevoie de conexiune la internet.
- Fără pași de build, fără `node_modules`.

---

## Instalare și rulare

### Varianta A — prin Apache (recomandat)
Adaugă în `httpd.conf` (sau folosește aliasul existent `htmleditor`):

```apache
Alias /wordeditor "d:/Teste cursor/HTML Editor/Word Editor"
<Directory "d:/Teste cursor/HTML Editor/Word Editor">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>
```

Repornește Apache din **XAMPP Control Panel** (Stop → Start), apoi deschide:

```
http://localhost/wordeditor/
```

### Varianta B — server PHP încorporat (fără Apache)
```bash
php -S 127.0.0.1:8899 -t "d:/Teste cursor/HTML Editor/Word Editor"
# apoi: http://127.0.0.1:8899/index.php
```

### Lansator Chrome (mod „app")
Fișierul `Word Editor - docx odt pdf.lnk` deschide aplicația într‑o fereastră Chrome dedicată
(`chrome.exe --app=...`), cu iconiță proprie (`.ico`). Dublu‑click și gata.

### Folderul de lucru
Implicit, lista de fișiere pornește din `e:/Carte/` (variabila `$ROOT` din `index.php`).
Poți deschide și orice fișier după cale absolută sau prin drag & drop.

---

## Scurtături de tastatură

| Acțiune | Tastă |
|---|---|
| Salvează (.docx) | Ctrl + S |
| Undo / Redo | Ctrl + Z / Ctrl + Y (sau Ctrl+Shift+Z) |
| Găsește & Înlocuiește | Ctrl + H |
| Deschide (pop‑up) | Ctrl + O |
| Re‑paginează | Alt + P |
| Pagina următoare / anterioară | Ctrl + PageDown / Ctrl + PageUp |
| Închide pop‑up deschidere | Esc |
| Diacritice | vezi tabelul de mai sus |

---

## Limitări cunoscute

- **PDF**: editarea e best‑effort (text extras → salvat ca `.docx`); PDF‑ul nu se rescrie ca PDF.
- **.doc** binar vechi nu e suportat la editare (necesită conversie în `.docx`).
- Re‑paginarea după editări mari este manuală (buton ¶ / Alt+P), nu live la fiecare tastă.
- Conversia docx/odt acoperă formatarea uzuală; layout‑uri foarte complexe pot diferi de Word.
- Necesită internet pentru librăriile CDN (pdf.js, html‑docx‑js).

---

## Structura fișierelor

```
Word Editor/
├─ index.php                         # aplicația completă (server + UI)
├─ README.md                         # acest fișier
├─ Word Editor - docx odt pdf.lnk    # lansator Chrome (mod app)
├─ Word Editor - docx odt pdf.ico    # iconiță
├─ Word Editor - docx odt pdf.ico.md5
├─ test simplu.docx                  # document de test
└─ .claude/launch.json               # config pentru rulare în preview (opțional)
```

---

🤖 Generat cu [Claude Code](https://claude.com/claude-code)
