# Script PowerShell pentru extragerea bazei de date AutoCorect - VERSIUNEA AVANSATĂ
# Autor: Assistant AI
# Data: 27 august 2025
# Versiune: 2.0 - Gestionare avansată encoding și separare cuvinte

Write-Host "=== Extragere baza de date AutoCorect - VERSIUNEA AVANSATĂ ===" -ForegroundColor Green
Write-Host "Incepe procesul de extragere cu encoding și separare avansată..." -ForegroundColor Yellow

# 1. Creează fișierul de bază de date
$outputFile = "baza_date_completa_advanced.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul existent a fost șters." -ForegroundColor Red
}

# 3. Funcție pentru separarea cuvintelor lipite
function Separate-Words {
    param([string]$text)
    
    if (-not $text) { return $text }
    
    # Încearcă să detectezi și să separezi cuvintele lipite
    $result = $text
    
    # 1. Adaugă spații între litere mici și mari (camelCase)
    $result = $result -replace '([a-zăâîșț])([A-ZĂÂÎȘȚ])', '$1 $2'
    
    # 2. Adaugă spații între litere și cifre
    $result = $result -replace '([a-zA-ZăâîșțĂÂÎȘȚ])(\d)', '$1 $2'
    $result = $result -replace '(\d)([a-zA-ZăâîșțĂÂÎȘȚ])', '$1 $2'
    
    # 3. Adaugă spații între cuvinte care par lipite (pattern-uri comune)
    # Detectează cuvinte care încep cu majuscule în mijlocul textului
    $result = $result -replace '([a-zăâîșț])([A-ZĂÂÎȘȚ][a-zăâîșț]+)', '$1 $2'
    
    # 4. Adaugă spații între cuvinte care se termină cu vocale și încep cu consoane
    $result = $result -replace '([aeiouăâî])([bcdfghjklmnpqrstvwxz])', '$1 $2'
    
    # 5. Adaugă spații între cuvinte care se termină cu consoane și încep cu vocale
    $result = $result -replace '([bcdfghjklmnpqrstvwxz])([aeiouăâî])', '$1 $2'
    
    # 6. Curăță spațiile multiple
    $result = $result -replace '\s+', ' '
    
    return $result.Trim()
}

# 4. Funcție pentru detectarea encoding-ului
function Get-FileEncoding {
    param([string]$filePath)
    
    try {
        # Încearcă să citești cu diferite encoding-uri
        $encodings = @('UTF8', 'UTF7', 'Unicode', 'BigEndianUnicode', 'Default', 'ASCII')
        
        foreach ($encoding in $encodings) {
            try {
                $content = Get-Content $filePath -Raw -Encoding $encoding
                if ($content -and $content.Length -gt 0) {
                    Write-Host "  Encoding detectat: $encoding" -ForegroundColor Cyan
                    return $encoding
                }
            } catch {
                continue
            }
        }
        
        # Dacă nimic nu funcționează, folosește Default
        Write-Host "  Encoding: Default (fallback)" -ForegroundColor Yellow
        return 'Default'
        
    } catch {
        Write-Host "  Eroare la detectarea encoding-ului: $($_.Exception.Message)" -ForegroundColor Red
        return 'Default'
    }
}

# 5. Găsește toate fișierele de dicționar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere de dicționar." -ForegroundColor Cyan

# 6. Extrage conținutul fiecărui fișier cu encoding și separare avansată
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Detectează encoding-ul fișierului
        $detectedEncoding = Get-FileEncoding $file.FullName
        
        # Citește conținutul cu encoding-ul detectat
        $content = Get-Content $file.FullName -Raw -Encoding $detectedEncoding
        
        if ($content) {
            # Procesează conținutul pentru separarea cuvintelor
            $processedContent = Separate-Words $content
            
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

# 7. Afișează rezultatele finale
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
    Get-Content $outputFile -Head 20 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nApasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
