# Script final pentru extragerea bazei de date cu virgula
# Autor: Assistant AI
# Data: 27 august 2025

Write-Host "=== EXTRAGERE BAZA DE DATE CU VIRGULA ===" -ForegroundColor Green

# 1. Creeaza fisierul de baza de date
$outputFile = "baza_date_virgula.txt"

# 2. Sterge fisierul daca exista
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fisierul existent a fost sters." -ForegroundColor Red
}

# 3. Funcție pentru separarea cuvintelor (CORECTATĂ)
function Separate-Words-With-Commas {
    param([string]$text)
    
    if (-not $text) { return $text }
    
    $result = $text
    
    # 1. Înlocuiește caracterele speciale cu spații
    $result = $result -replace '[º°²³¼½¾]', ' '
    
    # 2. Înlocuiește caracterele non-alfabetice cu spații (păstrând doar literele, inclusiv diacritice)
    # Folosește clasa Unicode de litere \p{L} pentru a evita problemele de encoding
    $result = $result -replace '[^\p{L}\s]', ' '
    
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

# 4. Gaseste toate fisierele de dictionar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au gasit $($dictionaryFiles.Count) fisiere de dictionar." -ForegroundColor Cyan

# 5. Extrage continutul fiecarui fisier
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adauga header cu numele fisierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citeste continutul
        $content = Get-Content $file.FullName -Raw -Encoding UTF8
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        if ($content) {
            # Proceseaza continutul pentru separarea cuvintelor
            $processedContent = Separate-Words-With-Commas $content
            
            # Adauga continutul procesat
            $processedContent | Out-File -Append -FilePath $outputFile -Encoding UTF8
            
            Write-Host "  Procesat cu succes - $([math]::Round($file.Length/1KB,2)) KB" -ForegroundColor Green
        } else {
            Write-Host "  Continut gol sau null" -ForegroundColor Yellow
        }
        
        # Adauga separator intre fisiere
        "`n`n" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        $totalSize += $file.Length
        $processedFiles++
        
    } catch {
        Write-Host "  Eroare: $($_.Exception.Message)" -ForegroundColor Red
    }
}

# 6. Afiseaza rezultatele finale
Write-Host "`n=== REZULTATE FINALE ===" -ForegroundColor Green
Write-Host "Fisiere procesate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totala originala: $([math]::Round($totalSize/1MB,2)) MB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fisier final creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fisier final: $([math]::Round($finalSize/1MB,2)) MB" -ForegroundColor Green
    Write-Host "Extragerea a fost finalizata cu succes!" -ForegroundColor Green
    
    # Afiseaza primele linii pentru verificare
    Write-Host "`n=== PREVIEW CONTINUT ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 10 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fisierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nExtragerea este completa! Verifica fisierul $outputFile" -ForegroundColor Green
