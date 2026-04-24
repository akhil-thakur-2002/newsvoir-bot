<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$config = require __DIR__ . '/config.php';

$body = json_decode(file_get_contents('php://input'), true);

function logError($config, $errorType, $errorMessage, $data = []) {
    if (empty($config['error_log_sheet_url'])) return;
    $payload = json_encode([
        'type'      => $errorType,
        'message'   => $errorMessage,
        'data'      => json_encode($data),
        'timestamp' => date('d-m-Y H:i:s'),
        'url'       => $_SERVER['REQUEST_URI'] ?? ''
    ]);
    $ch = curl_init($config['error_log_sheet_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}
$name  = isset($body['name'])  ? htmlspecialchars($body['name'])  : '';
$email = isset($body['email']) ? htmlspecialchars($body['email']) : '';
$phone = isset($body['phone']) ? htmlspecialchars($body['phone']) : '';
$query = isset($body['query']) ? htmlspecialchars($body['query']) : '';
$date  = date('d-m-Y H:i:s');

$endpoint = $_SERVER['REQUEST_URI'];

if (strpos($endpoint, 'submit-email') !== false) {
    try {
        sendEmail($config, $name, $email, $phone, $query, $date);
    } catch (Exception $e) {
        logError($config, 'EMAIL_FAILED', $e->getMessage(), ['name' => $name, 'email' => $email]);
    }
    try {
        saveToSheet($config, $name, $email, $phone, $query, $date);
    } catch (Exception $e) {
        logError($config, 'SHEET_SAVE_FAILED', $e->getMessage(), ['name' => $name]);
    }
}

if (strpos($endpoint, 'submit-whatsapp') !== false) {
    if ($phone) {
        try {
            addContactAiSensy($config, $name, $email, $phone, $query, $date);
        } catch (Exception $e) {
            logError($config, 'AISENSY_CONTACT_FAILED', $e->getMessage(), ['phone' => $phone]);
        }
        try {
            sendWhatsAppGreeting($config, $name, $phone);
        } catch (Exception $e) {
            logError($config, 'WHATSAPP_GREETING_FAILED', $e->getMessage(), ['name' => $name, 'phone' => $phone]);
        }
    }
}

echo json_encode(['success' => true]);
exit();

function sendEmail($config, $name, $email, $phone, $query, $date) {
    $subject = 'New Lead from Newsvoir Chatbot';
    $message = "
    <html>
    <body>
        <h2>New Lead Received</h2>
        <p><strong>Name:</strong> {$name}</p>
        <p><strong>Email:</strong> {$email}</p>
        <p><strong>Phone:</strong> {$phone}</p>
        <p><strong>Query:</strong> {$query}</p>
        <p><strong>Date:</strong> {$date}</p>
    </body>
    </html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$config['smtp_user']}\r\n";
    $headers .= "Reply-To: {$config['smtp_user']}\r\n";

    if (function_exists('PHPMailer\PHPMailer\PHPMailer')) {
        sendEmailPHPMailer($config, $subject, $message, $headers);
    } else {
        $ini = ini_get('sendmail_from');
        ini_set('sendmail_from', $config['smtp_user']);
        @mail($config['to_email'], $subject, $message, $headers);
        ini_set('sendmail_from', $ini);
    }
}

function sendEmailPHPMailer($config, $subject, $message) {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_user'];
    $mail->Password   = $config['smtp_pass'];
    $mail->SMTPSecure = $config['smtp_port'] == 465 ? 'ssl' : 'tls';
    $mail->Port       = $config['smtp_port'];
    $mail->setFrom($config['smtp_user'], 'Newsvoir Chatbot');
    $mail->addAddress($config['to_email']);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $message;
    $mail->send();
}

function saveToSheet($config, $name, $email, $phone, $query, $date) {
    if (empty($config['sheet_url'])) return;
    $data = json_encode([
        'name'  => $name,
        'email' => $email,
        'phone' => $phone,
        'query' => $query,
        'date'  => $date,
    ]);
    $ch = curl_init($config['sheet_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}

function addContactAiSensy($config, $name, $email, $phone, $query, $date) {
    if (empty($config['aisensy_api_key'])) return;
    $data = json_encode([
        'apiKey'       => $config['aisensy_api_key'],
        'fullName'     => $name,
        'phone'        => $phone,
        'email'        => $email,
        'customParams' => [
            ['name' => 'query', 'value' => $query],
            ['name' => 'date',  'value' => $date],
        ],
    ]);
    $ch = curl_init('https://backend.aisensy.com/contact/t1/api');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}

function sendWhatsAppGreeting($config, $name, $phone) {
    if (empty($config['aisensy_api_key'])) return;
    $data = json_encode([
        'apiKey'          => $config['aisensy_api_key'],
        'campaignName'    => $config['aisensy_greeting_template'],
        'destination'     => $phone,
        'userName'        => $name,
        'templateParams'  => [$name],
        'source'          => 'chatbot',
        'media'           => new stdClass(),
    ]);
    $ch = curl_init('https://backend.aisensy.com/campaign/t1/api/v2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}
