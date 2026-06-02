# Script simplu pentru extragerea bazei de date cu virgulă
# Autor: Assistant AI
# Data: 27 august 2025

Write-Host "=== EXTRAGERE BAZA DE DATE CU VIRGULĂ ===" -ForegroundColor Green

# 1. Creează fișierul de bază de date
$outputFile = "baza_date_virgula.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul existent a fost șters." -ForegroundColor Red
}

# 3. Găsește toate fișierele de dicționar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere de dicționar." -ForegroundColor Cyan

# 4. Extrage conținutul fiecărui fișier
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citește conținutul
        $content = Get-Content $file.FullName -Raw -Encoding UTF8
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        if ($content) {
            # Procesează conținutul pentru separarea cuvintelor
            $processedContent = $content
            
            # Adaugă virgulă între litere mici și mari
            $processedContent = $processedContent -replace '([a-z])([A-Z])', '$1,$2'
            
            # Adaugă virgulă între litere și cifre
            $processedContent = $processedContent -replace '([a-zA-Z])(\d)', '$1,$2'
            $processedContent = $processedContent -replace '(\d)([a-zA-Z])', '$1,$2'
            
            # Curăță virgulele multiple
            $processedContent = $processedContent -replace '[,]+', ','
            
            # Adaugă conținutul procesat
            $processedContent | Out-File -Append -FilePath $outputFile -Encoding UTF8
            
            Write-Host "  ✓ Procesat cu succes - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
        } else {
            Write-Host "  ⚠ Conținut gol sau null" -ForegroundColor Yellow
        }
        
        # Adaugă separator între fișiere
        "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        $totalSize += $file.Length
        $processedFiles++
        
    } catch {
        Write-Host "  ✗ Eroare: $($_.Exception.Message)" -ForegroundColor Red
    }
}

# 5. Afișează rezultatele finale
Write-Host "`n=== REZULTATE FINALE ===" -ForegroundColor Green
Write-Host "Fișiere procesate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totală originală: $([math]::Round($totalSize/1MB,2)) MB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fișier final creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fișier final: $([math]::Round($finalSize/1MB,2)) MB" -ForegroundColor Green
    Write-Host "Extragerea a fost finalizată cu succes!" -ForegroundColor Green
    
    # Afișează primele linii pentru verificare
    Write-Host "`n=== PREVIEW CONȚINUT ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 10 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nExtragerea este completă! Verifică fișierul $outputFile" -ForegroundColor Green
