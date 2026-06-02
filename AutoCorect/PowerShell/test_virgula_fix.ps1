# Script de test pentru verificarea separării cuvintelor prin virgulă - VERSIUNEA CORECTATĂ
# Autor: Assistant AI
# Data: 27 august 2025

Write-Host "=== TEST SEPARARE CUVINTE PRIN VIRGULĂ - VERSIUNEA CORECTATĂ ===" -ForegroundColor Green
Write-Host "Testează separarea cuvintelor lipite cu virgulă..." -ForegroundColor Yellow

# 1. Creează fișierul de test
$outputFile = "test_virgula_fix.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul de test existent a fost șters." -ForegroundColor Red
}

# 3. Funcție pentru separarea cuvintelor lipite și adăugarea virgulelor
function Separate-Words-With-Commas {
    param([string]$text)
    
    if (-not $text) { return $text }
    
    # Încearcă să detectezi și să separezi cuvintele lipite
    $result = $text
    
    # 1. Adaugă virgulă între litere mici și mari (camelCase)
    $result = $result -replace '([a-z])([A-Z])', '$1,$2'
    
    # 2. Adaugă virgulă între litere și cifre
    $result = $result -replace '([a-zA-Z])(\d)', '$1,$2'
    $result = $result -replace '(\d)([a-zA-Z])', '$1,$2'
    
    # 3. Adaugă virgulă între cuvinte care par lipite (pattern-uri comune)
    # Detectează cuvinte care încep cu majuscule în mijlocul textului
    $result = $result -replace '([a-z])([A-Z][a-z]+)', '$1,$2'
    
    # 4. Adaugă virgulă între cuvinte care se termină cu vocale și încep cu consoane
    $result = $result -replace '([aeiou])([bcdfghjklmnpqrstvwxz])', '$1,$2'
    
    # 5. Adaugă virgulă între cuvinte care se termină cu consoane și încep cu vocale
    $result = $result -replace '([bcdfghjklmnpqrstvwxz])([aeiou])', '$1,$2'
    
    # 6. Adaugă virgulă între cuvinte lungi consecutive (mai mult de 6 caractere)
    $result = $result -replace '([a-zA-Z]{6,})([a-zA-Z]{6,})', '$1,$2'
    
    # 7. Curăță virgulele multiple și spațiile
    $result = $result -replace '[,]+', ','
    $result = $result -replace '\s+', ' '
    
    return $result.Trim()
}

# 4. Găsește primele 3 fișiere pentru test
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName | Select-Object -First 3

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere pentru test." -ForegroundColor Cyan

# 5. Testează fiecare fișier cu separare prin virgulă
foreach ($file in $dictionaryFiles) {
    Write-Host "`n=== TESTEZ: $($file.Name) ===" -ForegroundColor Yellow
    
    # Adaugă header cu numele fișierului
    "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
    
    # Citește conținutul cu encoding-ul detectat
    $content = Get-Content $file.FullName -Raw -Encoding UTF8
    if (-not $content) {
        $content = Get-Content $file.FullName -Raw
    }
    
    if ($content) {
        # Afișează conținutul original (primele 200 de caractere)
        $originalPreview = $content.Substring(0, [Math]::Min(200, $content.Length))
        Write-Host "  Original (primele 200 caractere):" -ForegroundColor Cyan
        Write-Host "  $originalPreview" -ForegroundColor White
        
        # Procesează conținutul pentru separarea cuvintelor cu virgulă
        $processedContent = Separate-Words-With-Commas $content
        
        # Afișează conținutul procesat (primele 200 de caractere)
        $processedPreview = $processedContent.Substring(0, [Math]::Min(200, $processedContent.Length))
        Write-Host "  Procesat cu virgulă (primele 200 caractere):" -ForegroundColor Green
        Write-Host "  $processedPreview" -ForegroundColor White
        
        # Adaugă conținutul procesat
        $processedContent | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        Write-Host "  ✓ Procesat cu succes - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
    } else {
        Write-Host "  ⚠ Conținut gol sau null" -ForegroundColor Yellow
    }
    
    # Adaugă separator între fișiere
    "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
}

# 6. Afișează rezultatele testului
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

Write-Host "`nTestul este complet! Verifică fișierul test_virgula_fix.txt pentru a vedea separarea cu virgulă." -ForegroundColor Cyan
Write-Host "Apasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
