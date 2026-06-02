# AutoCorect local pentru Bebe Word Editor

Proiectul foloseste baza AutoCorect din acest repository, nu o instalare separata din `Program Files`.

Fisiere importante:

- `AutoCorect/autocorect-words.txt` - dictionarul local folosit de DEX.
- `AutoCorect/autocorect-words.txt.meta.json` - metadata despre generare.
- `tools/extract-autocorect-words.js` - scriptul care regenereaza dictionarul.

Verificarea ortografica foloseste trei straturi:

1. dictionarul personal din browser, in `localStorage`, cheia `wordEditor.dexUserWords.v2`;
2. dictionarul romanesc Hunspell incarcat prin CDN la prima apasare pe DEX;
3. dictionarul local `AutoCorect/autocorect-words.txt`.

Pentru regenerare:

```bash
node tools/extract-autocorect-words.js
```

Implicit, scriptul citeste din `AutoCorect/Dictionare` si rescrie `AutoCorect/autocorect-words.txt`.
Sursele `Dex` si `OCR_DIC` sunt opt-in, deoarece contin si forme artificiale/OCR care pot accepta
cuvinte gresite.

Nota GitHub: `AutoCorect/AutoCorect-2023.zip` are peste 100 MB si este configurat prin Git LFS in
`.gitattributes`. Aplicatia nu are nevoie de arhiva ZIP ca sa ruleze; foloseste fisierul text generat.
