<?php
require_once __DIR__ . '/../fpdf.php';

class BestelbonPDF extends FPDF {
    function Header() {
        $this->SetFont('Helvetica', 'B', 20);
        $this->Cell(0, 10, 'BESTELBON', 0, 1, 'R');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo(), 0, 0, 'C');
    }
}
