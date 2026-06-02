# Script PowerShell pentru extragerea bazei de date AutoCorect - VERSIUNEA CORECTATĂ
# Autor: Assistant AI
# Data: 27 august 2025
# Versiune: 1.1 - Corectat encoding și spațiere

Write-Host "=== Extragere baza de date AutoCorect - VERSIUNEA CORECTATĂ ===" -ForegroundColor Green
Write-Host "Incepe procesul de extragere cu encoding corect..." -ForegroundColor Yellow

# 1. Creează fișierul de bază de date
$outputFile = "baza_date_completa_fix.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul existent a fost șters." -ForegroundColor Red
}

# 3. Găsește toate fișierele de dicționar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere de dicționar." -ForegroundColor Cyan

# 4. Extrage conținutul fiecărui fișier cu encoding corect
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citește conținutul cu encoding corect
        $content = Get-Content $file.FullName -Raw -Encoding UTF8
        
        # Dacă nu poate citi cu UTF8, încearcă cu encoding-ul implicit
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        # Procesează conținutul pentru a adăuga spații între cuvinte
        if ($content) {
            # Încearcă să detectezi dacă cuvintele sunt lipite
            if ($content -match '^[a-zA-ZăâîșțĂÂÎȘȚ]+$') {
                # Dacă e un singur cuvânt lipit, nu face nimic
                $processedContent = $content
            } else {
                # Încearcă să adaugi spații între cuvinte care par lipite
                # Adaugă spații între litere mici și mari
                $processedContent = $content -replace '([a-zăâîșț])([A-ZĂÂÎȘȚ])', '$1 $2'
                # Adaugă spații între litere și cifre
                $processedContent = $processedContent -replace '([a-zA-ZăâîșțĂÂÎȘȚ])(\d)', '$1 $2'
                $processedContent = $processedContent -replace '(\d)([a-zA-ZăâîșțĂÂÎȘȚ])', '$1 $2'
                # Adaugă spații între cuvinte care par lipite (mai mult de 8 caractere consecutive)
                $processedContent = $processedContent -replace '([a-zA-ZăâîșțĂÂÎȘȚ]{8,})', '$1 '
            }
            
            # Adaugă conținutul procesat
            $processedContent | Out-File -Append -FilePath $outputFile -Encoding UTF8
        }
        
        # Adaugă separator între fișiere
        "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        $totalSize += $file.Length
        $processedFiles++
        
        Write-Host "Procesat: $($file.Name) - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
        
    } catch {
        Write-Host "Eroare la procesarea $($file.Name): $($_.Exception.Message)" -ForegroundColor Red
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
    Get-Content $outputFile -Head 15 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nApasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
