<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'utils/email_template.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

function checkAndSendReminders($conn) {
    $currentDateTime = date('Y-m-d H:i:00');
    
    $stmt = $conn->prepare("
        SELECT r.*, l.title as logTitle, l.message as logMessage 
        FROM reminder r
        JOIN logs l ON r.messageId = l.id
        WHERE CONCAT(r.date, ' ', r.time) <= ? 
        AND r.is_sent = 0 
        ORDER BY r.date ASC, r.time ASC
        LIMIT 1
    ");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $currentDateTime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($reminder = $result->fetch_assoc()) {
        // Try to send email
        $emailSent = sendReminderEmail(
            $reminder['email'],
            $reminder['name'],
            $reminder['title'],
            $reminder['message']
        );
        
        if ($emailSent) {
            // Update reminder as sent
            $updateStmt = $conn->prepare("UPDATE reminder SET is_sent = 1 WHERE messageId = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("s", $reminder['messageId']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            // Return event data
            return [
                'type' => 'reminder',
                'data' => [
                    'messageId' => $reminder['messageId'],
                    'title' => $reminder['logTitle'],
                    'message' => $reminder['logMessage'],
                    'reminderTime' => $reminder['time'],
                    'reminderDate' => $reminder['date'],
                    'type' => 'log',
                    'emailSent' => true
                ]
            ];
        } else {
            error_log("Failed to send reminder email for message ID: " . $reminder['messageId']);
            return [
                'type' => 'reminderError',
                'data' => [
                    'messageId' => $reminder['messageId'],
                    'error' => 'Failed to send reminder email'
                ]
            ];
        }
    }
    
    $stmt->close();
    return null;
}