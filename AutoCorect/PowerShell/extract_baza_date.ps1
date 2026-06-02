# Script PowerShell pentru extragerea bazei de date AutoCorect
# Autor: Assistant AI
# Data: 27 august 2025

Write-Host "=== Extragere baza de date AutoCorect ===" -ForegroundColor Green
Write-Host "Incepe procesul de extragere..." -ForegroundColor Yellow

# 1. Creează fișierul de bază de date
$outputFile = "baza_date_completa.txt"

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
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Adaugă conținutul fișierului
        Get-Content $file.FullName -Raw | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
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
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nApasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
