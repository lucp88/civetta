<?php
require_once 'config.php';

$posts = [
    [
        'title' => 'Steun Civetta',
        'content' => "Civetta is volop in opbouw. Een deel van de investeringen heb ik al zelf gedaan, maar om de bakkerij écht goed te starten zoek ik nog een klein steuntje in de rug.\n\nWil je meedoen via een gift, voorverkoop of een combinatie daarvan?",
        'post_date' => '2026-01-09'
    ],
    [
        'title' => 'De website is live',
        'content' => "Op deze website zal ik regelmatig updates delen over wat er uit de oven komt, nieuwe recepten die ik uitprobeer, en andere wetenswaardigheden uit de bakkerij.\n\nBinnenkort volgen foto's van de producten en meer informatie over waar je ons brood kunt vinden.",
        'post_date' => '2026-01-09'
    ],
    [
        'title' => 'Civetta: de eerste stappen!',
        'content' => "Zoals sommigen van jullie misschien al weten, ben ik bezig met het opstarten van een kleine ambachtelijke bakkerij in Leersum: Civetta.\n\nHet idee is om bewust klein te beginnen. In de eerste fase ga ik vooral brood bakken voor restaurants in de buurt, met de focus op kwaliteit, rust en een gezonde, terugkerende omzet. Particulieren kunnen straks ook op bestelling brood afhalen. Ik doe dit niet alleen: in Leersum heb ik iemand gevonden met bakervaring die het leuk vindt om mee te helpen.\n\nDe plek waar Civetta gaat starten is inmiddels gevonden — bij een boer aan de rand van Leersum. De verbouwing is bijna klaar en daarna volgt een korte klusperiode. Als alles meezit, kan ik over een paar maanden echt beginnen.\n\nDe eerste stappen zijn ondertussen gezet: Civetta staat ingeschreven bij de KVK, het domein is gekocht, de eerste machines zijn aangeschaft en de eerste gesprekken met restaurants zijn veelbelovend. Meerdere zaken hebben al aangegeven interesse te hebben om samen te werken, mits de kwaliteit klopt — precies zoals het hoort.\n\nDit is pas het begin, maar het voelt als een goed begin. Binnenkort deel ik hier meer.",
        'post_date' => '2026-01-08'
    ]
];

try {
    $stmt = $pdo->prepare("INSERT INTO blog_posts (title, content, post_date) VALUES (?, ?, ?)");
    
    foreach ($posts as $post) {
        $stmt->execute([$post['title'], $post['content'], $post['post_date']]);
        echo "Post toegevoegd: " . $post['title'] . "<br>";
    }
    
    echo "<br><strong>Klaar! Verwijder dit bestand.</strong>";
    echo "<br><a href='index.php'>Ga naar admin</a>";
    
} catch (PDOException $e) {
    echo "Fout: " . $e->getMessage();
}
?>
