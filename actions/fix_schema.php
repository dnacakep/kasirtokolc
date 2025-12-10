<?php

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = get_db_connection();
    
    // Update the ENUM definition to include 'expired'
    // We include all likely used values plus the new one.
    $sql = "
        ALTER TABLE stock_adjustments 
        MODIFY COLUMN adjustment_type 
        ENUM('initial', 'purchase', 'sale', 'return', 'adjust', 'transfer', 'convert_in', 'convert_out', 'expired') 
        NOT NULL
    ";
    
    $pdo->exec($sql);
    echo "Schema updated successfully. 'expired' added to adjustment_type enum.";
    
} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage();
}
