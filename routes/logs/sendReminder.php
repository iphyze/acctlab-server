
<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();

require_once 'utils/email_template.php';

// Set timezone to West African Time (Nigeria)
date_default_timezone_set('Africa/Lagos');

function sendReminderEmail($to, $name, $title, $message) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = 'ssl';
        $mail->Port = $_ENV['SMTP_PORT'];
        
        // Set UTF-8 encoding
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom($_ENV['SMTP_USER'], 'Lambert Electromec REDM Platform');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = "Reminder: " . $title;
        $mail->Body = reminderTemplate($name, $title, $message);

        $mail->send();
        return true;

    } catch(Exception $e) {
        error_log("Mail sending failed: " . $e->getMessage());
        return false;
    }
}

try {
    // Get current date and time in Nigeria timezone
    $currentDateTime = date('Y-m-d H:i:00');
    
    // Log the current check time
    error_log("Checking reminders at: " . $currentDateTime);
    
    $stmt = $conn->prepare("
        SELECT * FROM reminder 
        WHERE CONCAT(date, ' ', time) <= ? 
        AND is_sent = 0 
        ORDER BY date ASC, time ASC
    ");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("s", $currentDateTime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reminderCount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $reminderCount++;
        error_log("Processing reminder ID: " . $row['messageId'] . " scheduled for: " . $row['date'] . " " . $row['time']);
        
        // Try to send email
        $emailSent = sendReminderEmail(
            $row['email'],
            $row['name'],
            $row['title'],
            $row['message']
        );
        
        if ($emailSent) {
            // Update the reminder as sent
            $updateStmt = $conn->prepare("UPDATE reminder SET is_sent = 1 WHERE messageId = ?");
            if (!$updateStmt) {
                throw new Exception("Failed to prepare update statement: " . $conn->error);
            }
            
            $updateStmt->bind_param("s", $row['messageId']);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Log success
            error_log("Reminder sent successfully to: " . $row['email'] . " for date: " . $row['date'] . " " . $row['time']);
        } else {
            // Log failure
            error_log("Failed to send reminder to: " . $row['email'] . " for date: " . $row['date'] . " " . $row['time']);
        }
    }
    
    // Log summary
    error_log("Reminder check completed. Processed " . $reminderCount . " reminders.");
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error in reminder system: " . $e->getMessage());
}
