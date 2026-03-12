<?php
require_once __DIR__ . '/../fpdf.php';

class RecipePDF extends FPDF {
    private $accentColor = [139, 90, 43];
    private $recipeName = '';
    
    function __construct($recipeName = 'Recept') {
        parent::__construct();
        $this->recipeName = $recipeName;
    }
    
    function Header() {
        $this->SetFont('Helvetica', 'B', 24);
        $this->SetTextColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
        $this->Cell(0, 12, utf8_decode($this->recipeName), 0, 1, 'L');
        $this->SetDrawColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(8);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Civetta Bakkerij - ' . date('d-m-Y'), 0, 0, 'C');
    }
    
    function SectionTitle($title, $icon = '') {
        $this->Ln(4);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
        $this->Cell(0, 8, utf8_decode($title), 0, 1, 'L');
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
    }
    
    function StatBox($label, $value, $unit = '') {
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100);
        $this->Cell(35, 5, utf8_decode($label), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(50);
        $this->Cell(25, 5, $value . $unit, 0, 1, 'L');
    }
    
    function IngredientRow($name, $value, $isSubItem = false) {
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor($isSubItem ? 120 : 60);
        $indent = $isSubItem ? 15 : 10;
        $this->SetX($indent);
        $this->Cell(80, 6, utf8_decode($name), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(50);
        $this->Cell(30, 6, $value, 0, 1, 'R');
    }
    
    function TotalRow($label, $value) {
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 120, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
        $this->Cell(80, 6, utf8_decode($label), 0, 0, 'L');
        $this->Cell(30, 6, $value, 0, 1, 'R');
    }
    
    function CategoryLabel($category) {
        $labels = [
            'integrated' => 'Int.',
            'non-integrated' => 'Vast',
            'liquid' => 'Vloei.'
        ];
        return $labels[$category] ?? $category;
    }
}

function generateRecipePDF($data) {
    $name = $data['name'] ?? 'Recept';
    $recipe = $data['recipe_data'] ?? $data;
    
    $pdf = new RecipePDF($name);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    
    $weightFromOrder = $recipe['weightFromOrder'] ?? false;
    $hydration = $recipe['hydration'] ?? 62;
    $saltPct = $recipe['saltPct'] ?? 2.6;
    $numberOfBalls = $recipe['numberOfBalls'] ?? 24;
    $weightPerBall = $recipe['weightPerBall'] ?? 300;
    
    $totalDoughWeight = $numberOfBalls * $weightPerBall;
    $totalFlour = $totalDoughWeight / (1 + $hydration / 100 + $saltPct / 100);
    $totalWater = $totalFlour * ($hydration / 100);
    $saltWeight = $totalFlour * ($saltPct / 100);
    
    $pdf->SectionTitle('Overzicht');
    
    if (!$weightFromOrder) {
        $pdf->StatBox('Aantal', $numberOfBalls, ' st');
        $pdf->StatBox('Gewicht/stuk', round($weightPerBall), 'g');
        $pdf->StatBox('Totaal', round($totalDoughWeight), 'g');
    }
    $pdf->StatBox('Hydratatie', number_format($hydration, 1, ',', ''), '%');
    $pdf->StatBox('Zout', number_format($saltPct, 1, ',', ''), '%');
    
    $wholeGrainPct = calculateWholeGrainPct($recipe);
    $pdf->StatBox('Volkoren', number_format($wholeGrainPct, 1, ',', ''), '%');
    
    if ($weightFromOrder) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(46, 125, 50);
        $pdf->Cell(0, 5, 'Gewicht wordt bepaald door bestelling', 0, 1, 'L');
    }
    
    if (!empty($recipe['useSourdough']) && $recipe['useSourdough']) {
        $pdf->SectionTitle('Zuurdesem');
        $sourdoughPct = $recipe['sourdoughPct'] ?? 20;
        $sourdoughHydration = $recipe['sourdoughHydration'] ?? 100;
        
        if ($weightFromOrder) {
            $pdf->IngredientRow('Percentage', number_format($sourdoughPct, 1, ',', '') . '%');
            $pdf->IngredientRow('Hydratatie', number_format($sourdoughHydration, 1, ',', '') . '%');
        } else {
            $sourdoughWeight = $totalFlour * ($sourdoughPct / 100);
            $sourdoughFlour = $sourdoughWeight / (1 + $sourdoughHydration / 100);
            $sourdoughWater = $sourdoughWeight - $sourdoughFlour;
            $pdf->IngredientRow('Meel', round($sourdoughFlour) . 'g');
            $pdf->IngredientRow('Water', round($sourdoughWater) . 'g');
            $pdf->TotalRow('Totaal', round($sourdoughWeight) . 'g');
        }
        
        if (!empty($recipe['sourdoughGrains'])) {
            foreach ($recipe['sourdoughGrains'] as $grain) {
                if (($grain['pct'] ?? 0) > 0) {
                    $grainName = getGrainName($grain['type']);
                    $pdf->IngredientRow($grainName, number_format($grain['pct'], 0) . '%', true);
                }
            }
        }
    }
    
    if (!empty($recipe['usePreFerment']) && $recipe['usePreFerment']) {
        $pdf->SectionTitle('Voordeeg');
        $preFermentPct = $recipe['preFermentPct'] ?? 20;
        $preFermentHydration = $recipe['preFermentHydration'] ?? 100;
        
        if ($weightFromOrder) {
            $pdf->IngredientRow('Percentage', number_format($preFermentPct, 1, ',', '') . '%');
            $pdf->IngredientRow('Hydratatie', number_format($preFermentHydration, 1, ',', '') . '%');
        } else {
            $preFermentWeight = $totalFlour * ($preFermentPct / 100);
            $preFermentFlour = $preFermentWeight / (1 + $preFermentHydration / 100);
            $preFermentWater = $preFermentWeight - $preFermentFlour;
            $pdf->IngredientRow('Meel', round($preFermentFlour) . 'g');
            $pdf->IngredientRow('Water', round($preFermentWater) . 'g');
            $pdf->TotalRow('Totaal', round($preFermentWeight) . 'g');
        }
        
        if (!empty($recipe['preFermentGrains'])) {
            foreach ($recipe['preFermentGrains'] as $grain) {
                if (($grain['pct'] ?? 0) > 0) {
                    $grainName = getGrainName($grain['type']);
                    $pdf->IngredientRow($grainName, number_format($grain['pct'], 0) . '%', true);
                }
            }
        }
    }
    
    $pdf->SectionTitle('Hoofddeeg');
    if (!empty($recipe['mainDoughGrains'])) {
        foreach ($recipe['mainDoughGrains'] as $grain) {
            if (($grain['pct'] ?? 0) > 0) {
                $grainName = getGrainName($grain['type']);
                $pdf->IngredientRow($grainName, number_format($grain['pct'], 0) . '%');
            }
        }
    }
    
    if (!empty($recipe['useYeast']) && $recipe['useYeast']) {
        $yeastType = $recipe['yeastType'] ?? 'instant_yeast';
        $yeastPct = $recipe['yeastPct'] ?? 2.8;
        $yeastName = getYeastName($yeastType);
        $pdf->IngredientRow($yeastName, number_format($yeastPct, 1, ',', '') . '%');
    }
    
    if (!empty($recipe['mixins']) && count($recipe['mixins']) > 0) {
        $mixinMode = $recipe['mixinMode'] ?? 'flour';
        $modeLabel = $mixinMode === 'flour' ? '% meel' : '% deeg';
        $pdf->SectionTitle('Mix-ins (' . $modeLabel . ')');
        foreach ($recipe['mixins'] as $mixin) {
            if (!empty($mixin['ingredient']) && ($mixin['pct'] ?? 0) > 0) {
                $cat = $pdf->CategoryLabel($mixin['category'] ?? 'non-integrated');
                $pdf->IngredientRow($mixin['ingredient'] . ' [' . $cat . ']', number_format($mixin['pct'], 1, ',', '') . '%');
            }
        }
    }
    
    if (!empty($recipe['toppings']) && count($recipe['toppings']) > 0) {
        $pdf->SectionTitle('Toppings (% deeg)');
        foreach ($recipe['toppings'] as $topping) {
            if (!empty($topping['ingredient']) && ($topping['pct'] ?? 0) > 0) {
                $pdf->IngredientRow($topping['ingredient'], number_format($topping['pct'], 1, ',', '') . '%');
            }
        }
    }
    
    $pdf->SectionTitle("Baker's Percentages");
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(100);
    $pdf->Cell(60, 6, 'Ingrediënt', 0, 0, 'L');
    if (!$weightFromOrder) {
        $pdf->Cell(30, 6, 'Gewicht', 0, 0, 'R');
    }
    $pdf->Cell(30, 6, "Baker's %", 0, 1, 'R');
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(10, $pdf->GetY(), $weightFromOrder ? 100 : 130, $pdf->GetY());
    $pdf->Ln(2);
    
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(60);
    
    $pdf->Cell(60, 6, 'Totaal meel', 0, 0, 'L');
    if (!$weightFromOrder) $pdf->Cell(30, 6, round($totalFlour) . 'g', 0, 0, 'R');
    $pdf->Cell(30, 6, '100%', 0, 1, 'R');
    
    $pdf->Cell(60, 6, 'Water', 0, 0, 'L');
    if (!$weightFromOrder) $pdf->Cell(30, 6, round($totalWater) . 'g', 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($hydration, 1, ',', '') . '%', 0, 1, 'R');
    
    $pdf->Cell(60, 6, 'Zout', 0, 0, 'L');
    if (!$weightFromOrder) $pdf->Cell(30, 6, round($saltWeight) . 'g', 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($saltPct, 1, ',', '') . '%', 0, 1, 'R');
    
    if (!empty($recipe['useYeast']) && $recipe['useYeast']) {
        $yeastPct = $recipe['yeastPct'] ?? 2.8;
        $yeastWeight = $totalFlour * ($yeastPct / 100);
        $pdf->Cell(60, 6, utf8_decode(getYeastName($recipe['yeastType'] ?? 'instant_yeast')), 0, 0, 'L');
        if (!$weightFromOrder) $pdf->Cell(30, 6, round($yeastWeight) . 'g', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($yeastPct, 1, ',', '') . '%', 0, 1, 'R');
    }
    
    if (!empty($recipe['methodDays'])) {
        $pdf->AddPage();
        $pdf->SectionTitle('Bereidingswijze');
        foreach ($recipe['methodDays'] as $di => $day) {
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetTextColor(92, 61, 30);
            $pdf->Cell(0, 8, utf8_decode($day['label'] ?? ('Dag ' . ($di + 1))), 0, 1, 'L');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(60);
            if (!empty($day['steps'])) {
                foreach ($day['steps'] as $si => $step) {
                    if (trim($step) === '') continue;
                    $pdf->Cell(8, 6, ($si + 1) . '.', 0, 0, 'R');
                    $pdf->MultiCell(0, 6, utf8_decode($step));
                }
            }
            $pdf->Ln(3);
        }
    } elseif (!empty($recipe['method'])) {
        $pdf->AddPage();
        $pdf->SectionTitle('Bereidingswijze');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(60);
        $pdf->MultiCell(0, 6, utf8_decode($recipe['method']));
    }
    
    return $pdf;
}

function getGrainName($type) {
    $names = [
        'wheat_white' => 'Tarwe wit',
        'wheat_whole' => 'Tarwe volkoren',
        'spelt_white' => 'Spelt wit',
        'spelt_whole' => 'Spelt volkoren',
        'durum' => 'Durum',
        'emmer' => 'Emmer',
        'rye_white' => 'Rogge wit',
        'rye_whole' => 'Rogge volkoren',
        'einkorn' => 'Einkorn',
        'buckwheat' => 'Boekweit',
        'rice' => 'Rijst',
        'barley' => 'Gerst',
        'teff' => 'Teff',
    ];
    return $names[$type] ?? $type;
}

function getYeastName($type) {
    $names = [
        'fresh_yeast' => 'Verse gist',
        'instant_yeast' => 'Instant gist',
        'sourdough_culture' => 'Desemcultuur',
    ];
    return $names[$type] ?? $type;
}

function calculateWholeGrainPct($recipe) {
    $totalFlour = 100;
    $wholeGrainFlour = 0;
    
    $allGrains = [];
    if (!empty($recipe['mainDoughGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['mainDoughGrains']);
    }
    if (!empty($recipe['useSourdough']) && !empty($recipe['sourdoughGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['sourdoughGrains']);
    }
    if (!empty($recipe['usePreFerment']) && !empty($recipe['preFermentGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['preFermentGrains']);
    }
    
    $totalPct = 0;
    $wholePct = 0;
    foreach ($allGrains as $grain) {
        $pct = $grain['pct'] ?? 0;
        $totalPct += $pct;
        if (strpos($grain['type'] ?? '', '_whole') !== false) {
            $wholePct += $pct;
        }
    }
    
    return $totalPct > 0 ? ($wholePct / $totalPct) * 100 : 0;
}
