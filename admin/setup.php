<?php
require_once 'config.php';

$tables = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    post_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2),
    image VARCHAR(255),
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(50),
    order_details TEXT NOT NULL,
    total_amount DECIMAL(10,2),
    status ENUM('nieuw', 'bevestigd', 'klaar', 'afgehaald', 'geannuleerd') DEFAULT 'nieuw',
    pickup_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
";

try {
    $pdo->exec($tables);
    echo "<h2>Tabellen succesvol aangemaakt!</h2>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $defaultPassword = password_hash('civetta2026', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)")
            ->execute(['admin', $defaultPassword]);
        echo "<p>Admin gebruiker aangemaakt:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> admin</li>";
        echo "<li><strong>Wachtwoord:</strong> civetta2026</li>";
        echo "</ul>";
        echo "<p style='color: red;'><strong>Wijzig dit wachtwoord direct na eerste login!</strong></p>";
    }
    
    echo "<p><a href='login.php'>Ga naar login</a></p>";
    echo "<p style='color: orange;'><strong>Verwijder dit bestand (setup.php) na installatie!</strong></p>";
    
} catch (PDOException $e) {
    die("Fout bij aanmaken tabellen: " . $e->getMessage());
}
?>
