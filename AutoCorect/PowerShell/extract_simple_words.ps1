# Script simplu pentru extragerea cuvintelor reale
# Autor: Assistant AI
# Data: 28 august 2025

Write-Host "=== EXTRAGERE CUVINTE REALE ===" -ForegroundColor Green

# 1. Creeaza fisierul de baza de date
$outputFile = "cuvinte_reale_virgula.txt"

# 2. Sterge fisierul daca exista
if (Test-Path $outputFile) {
    Remove-Item $outputFile -Force
    Write-Host "Fisierul existent a fost sters." -ForegroundColor Red
}

# 3. Gaseste toate fisierele de dictionar
$dictionaryFiles = Get-ChildItem -Recurse -Include *.dic,*.len,*.cnt,*.imd | Sort-Object FullName

Write-Host "S-au gasit $($dictionaryFiles.Count) fisiere de dictionar." -ForegroundColor Cyan

# 4. Extrage continutul fiecarui fisier
$totalSize = 0
$processedFiles = 0

foreach ($file in $dictionaryFiles) {
    try {
        Write-Host "Procesez: $($file.Name)" -ForegroundColor Yellow
        
        # Adauga header cu numele fisierului
        "=== $($file.Name) ===" | Out-File -Append -FilePath $outputFile -Encoding UTF8
        
        # Citeste continutul cu encoding-ul corect
        $content = Get-Content $file.FullName -Raw -Encoding Default
        if (-not $content) {
            $content = Get-Content $file.FullName -Raw
        }
        
        if ($content) {
            # Proceseaza continutul pentru separarea cuvintelor
            $processedContent = $content
            
            # Inlocuieste caracterele speciale cu spatii
            $processedContent = $processedContent -replace '[º°²³¼½¾]', ' '
            
            # Adauga virgula intre cuvinte care se termina cu vocale si incep cu consoane
            $processedContent = $processedContent -replace '([aeiou])([bcdfghjklmnpqrstvwxz])', '$1,$2'
            
            # Adauga virgula intre cuvinte care se termina cu consoane si incep cu vocale
            $processedContent = $processedContent -replace '([bcdfghjklmnpqrstvwxz])([aeiou])', '$1,$2'
            
            # Curata virgulele multiple
            $processedContent = $processedContent -replace '[,]+', ','
            
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

# 5. Afiseaza rezultatele finale
Write-Host "`n=== REZULTATE FINALE ===" -ForegroundColor Green
Write-Host "Fisiere procesate: $processedFiles" -ForegroundColor Cyan
Write-Host "Dimensiune totala originala: $([math]::Round($totalSize/1MB,2)) MB" -ForegroundColor Cyan

if (Test-Path $outputFile) {
    $finalSize = (Get-Item $outputFile).Length
    Write-Host "Fisier final creat: $outputFile" -ForegroundColor Green
    Write-Host "Dimensiune fisier final: $([math]::Round($finalSize/1MB,2)) MB" -ForegroundColor Green
    Write-Host "Extragerea cuvintelor reale a fost finalizata cu succes!" -ForegroundColor Green
    
    # Afiseaza primele linii pentru verificare
    Write-Host "`n=== PREVIEW CONTINUT ===" -ForegroundColor Yellow
    Get-Content $outputFile -Head 15 | ForEach-Object { Write-Host $_ -ForegroundColor White }
    
} else {
    Write-Host "EROARE: Fisierul final nu a fost creat!" -ForegroundColor Red
}

Write-Host "`nExtragerea cuvintelor reale este completa! Verifica fisierul $outputFile" -ForegroundColor Green
