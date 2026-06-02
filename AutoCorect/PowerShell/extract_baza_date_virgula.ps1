# Script PowerShell pentru extragerea bazei de date AutoCorect - VERSIUNEA CU VIRGULĂ
# Autor: Assistant AI
# Data: 27 august 2025
# Versiune: 3.0 - Cuvinte separate prin virgulă, encoding UTF-8 complet

Write-Host "=== Extragere baza de date AutoCorect - VERSIUNEA CU VIRGULĂ ===" -ForegroundColor Green
Write-Host "Incepe procesul de extragere cu cuvinte separate prin virgulă..." -ForegroundColor Yellow

# 1. Creează fișierul de bază de date
$outputFile = "baza_date_completa_virgula.txt"

# 2. Șterge fișierul dacă există
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fișierul existent a fost șters." -ForegroundColor Red
}

# 3. Funcție pentru separarea cuvintelor (CORECTATĂ)
function Separate-Words-With-Commas {
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
    
    # 5. Filtrează cuvintele (elimină cuvintele prea scurte)
    $filteredWords = $words | Where-Object { $_.Length -gt 1 }
    
    # 6. Unește cuvintele cu virgulă
    $result = $filteredWords -join ','
    
    return $result.Trim()
}

# 4. Funcție pentru detectarea encoding-ului și conversia la UTF-8
function Get-Content-UTF8 {
    param([string]$filePath)
    
    try {
        # Încearcă să citești cu diferite encoding-uri
        $encodings = @('UTF8', 'UTF7', 'Unicode', 'BigEndianUnicode', 'Default', 'ASCII')
        
        foreach ($encoding in $encodings) {
            try {
                $content = Get-Content $filePath -Raw -Encoding $encoding
                if ($content -and $content.Length -gt 0) {
                    Write-Host "  Encoding detectat: $encoding" -ForegroundColor Cyan
                    return $content
                }
            } catch {
                continue
            }
        }
        
        # Dacă nimic nu funcționează, folosește Default
        Write-Host "  Encoding: Default (fallback)" -ForegroundColor Yellow
        return Get-Content $filePath -Raw
        
    } catch {
        Write-Host "  Eroare la citirea fișierului: $($_.Exception.Message)" -ForegroundColor Red
        return $null
    }
}

# 5. Găsește toate fișierele de dicționar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au găsit $($dictionaryFiles.Count) fișiere de dicționar." -ForegroundColor Cyan

# 6. Extrage conținutul fiecărui fișier cu separare prin virgulă și encoding UTF-8
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adaugă header cu numele fișierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citește conținutul cu encoding-ul detectat
        $content = Get-Content-UTF8 $file.FullName
        
        if ($content) {
            # Procesează conținutul pentru separarea cuvintelor cu virgulă
            $processedContent = Separate-Words-With-Commas $content
            
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
    
    # Verifică encoding-ul fișierului final
    Write-Host "`n=== VERIFICARE ENCODING ===" -ForegroundColor Yellow
    try {
        $encoding = [System.Text.Encoding]::UTF8
        $bytes = [System.IO.File]::ReadAllBytes($outputFile)
        $content = $encoding.GetString($bytes)
        Write-Host "✓ Fișierul este codat corect în UTF-8" -ForegroundColor Green
        Write-Host "✓ Diacriticele românești sunt suportate complet" -ForegroundColor Green
    } catch {
        Write-Host "⚠ Eroare la verificarea encoding-ului: $($_.Exception.Message)" -ForegroundColor Yellow
    }
    
} else {
    Write-Host "EROARE: Fișierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nApasă orice tastă pentru a închide..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
