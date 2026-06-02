# Script de test pentru verificarea encoding-ului și separării cuvintelor
# Autor: Assistant AI
# Data: 27 august 2025

Write-Host "=== TEST ENCODING ȘI SEPARARE CUVINTE ===" -ForegroundColor Green
Write-Host "Testează cu câteva fișiere pentru a verifica encoding-ul..." -ForegroundColor Yellow

# 1. Creează fișierul de test
$outputFile = "test_encoding.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul de test existent a fost șters." -ForegroundColor Red
}

# 3. Găsește primele 3 fișiere pentru test
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName | Select-Object -First 3

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere pentru test." -ForegroundColor Cyan

# 4. Testează fiecare fișier cu diferite encoding-uri
foreach ($file in $dictionaryFiles) {
    Write-Host "`n=== TESTEZ: $($file.Name) ===" -ForegroundColor Yellow
    
    # Adaugă header cu numele fișierului
    "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
    
    # Testează diferite encoding-uri
    $encodings = @('UTF8', 'UTF7', 'Unicode', 'BigEndianUnicode', 'Default', 'ASCII')
    
    foreach ($encoding in $encodings) {
        try {
            $content = Get-Content $file.FullName -Raw -Encoding $encoding
            if ($content -and $content.Length -gt 0) {
                Write-Host "  ✓ $encoding: $($content.Length) caractere" -ForegroundColor Green
                
                # Afișează primele 100 de caractere pentru verificare
                $preview = $content.Substring(0, [Math]::Min(100, $content.Length))
                Write-Host "  Preview: $preview" -ForegroundColor Cyan
                
                # Adaugă conținutul cu encoding-ul care funcționează
                "Encoding: $encoding" | Out-File -Append -FilePath $outputFile -Encoding UTF8
                $content | Out-File -Append -FilePath $outputFile -Encoding UTF8
                break
            }
        } catch {
            Write-Host "  ✗ $encoding: Eroare" -ForegroundColor Red
        }
    }
    
    # Adaugă separator între fișiere
    "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
}

# 5. Afișează rezultatele testului
Write-Host "`n=== REZULTATE TEST ===" -ForegroundColor Green

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fișier de test creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fișier test: $([math]::Round($finalSize/1KB,2)) KB" -ForegroundColor Green
    Write-Host "Testul a fost finalizat cu succes!" -ForegroundColor Green
    
    # Afișează conținutul fișierului de test
    Write-Host "`n=== CONȚINUT TEST ===" -ForegroundColor Yellow
    Get-Content $outputFile | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul de test nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nTestul este complet! Verifică fișierul test_encoding.txt pentru a vedea encoding-ul corect." -ForegroundColor Cyan
Write-Host "Apasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
