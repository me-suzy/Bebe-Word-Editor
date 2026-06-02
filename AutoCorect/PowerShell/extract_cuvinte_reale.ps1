# Script pentru extragerea cuvintelor reale din baza de date AutoCorect
# Autor: Assistant AI
# Data: 28 august 2025
# Versiune: CORECTATĂ - Cuvinte reale separate prin virgulă

Write-Host "=== EXTRAGERE CUVINTE REALE DIN BAZA DE DATE ===" -ForegroundColor Green
Write-Host "Extrag cuvintele reale românești și le separ prin virgulă..." -ForegroundColor Yellow

# 1. Creează fișierul de bază de date
$outputFile = "cuvinte_reale_virgula.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul existent a fost șters." -ForegroundColor Red
}

# 3. Funcție pentru separarea cuvintelor reale (CORECTATĂ)
function Separate-Real-Words {
    param([string]$text)
    
    if (-not $text) { return $text }
    
    $result = $text
    
    # 1. Înlocuiește caracterele speciale cu spații
    $result = $result -replace '[º°²³¼½¾]', ' '
    
    # 2. Înlocuiește caracterele non-alfabetice cu spații (păstrând doar literele)
    $result = $result -replace '[^a-zA-ZăâîșțĂÂÎȘȚ\s]', ' '
    
    # 3. Curăță spațiile multiple
    $result = $result -replace '\s+', ' '
    
    # 4. Împarte textul în cuvinte
    $words = $result -split '\s+' | Where-Object { $_.Length -gt 0 }
    
    # 5. Filtrează cuvintele (elimină cuvintele prea scurte sau care conțin doar o literă)
    $filteredWords = $words | Where-Object { $_.Length -gt 1 }
    
    # 6. Unește cuvintele cu virgulă
    $result = $filteredWords -join ','
    
    return $result.Trim()
}

# 4. Găsește toate fișierele de dicționar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere de dicționar." -ForegroundColor Cyan

# 5. Extrage conținutul fiecărui fișier cu separarea cuvintelor reale
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citește conținutul cu encoding-ul corect
        $content = Get-Content $file.FullName -Raw -Encoding Default
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        if ($content) {
            # Procesează conținutul pentru separarea cuvintelor reale
            $processedContent = Separate-Real-Words $content
            
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

# 6. Afișează rezultatele finale
Write-Host "`n=== REZULTATE FINALE ===" -ForegroundColor Green
Write-Host "Fișiere procesate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totală originală: $([math]::Round($totalSize/1MB,2)) MB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fișier final creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fișier final: $([math]::Round($finalSize/1MB,2)) MB" -ForegroundColor Green
    Write-Host "Extragerea cuvintelor reale a fost finalizată cu succes!" -ForegroundColor Green
    
    # Afișează primele linii pentru verificare
    Write-Host "`n=== PREVIEW CONȚINUT ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 15 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nExtragerea cuvintelor reale este completă! Verifică fișierul $outputFile" -ForegroundColor Green
